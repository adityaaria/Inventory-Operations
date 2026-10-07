#!/usr/bin/env python3
"""Local Docker database backup/recovery. Restore is restricted to an empty disposable target."""
import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time


def compose(args):
    command = [args.docker, 'compose']
    for filename in args.file: command += ['-f', filename]
    if args.env_file: command += ['--env-file', args.env_file]
    return command + ['-p', args.project]


def run(command, **kwargs):
    result = subprocess.run(command, stderr=subprocess.PIPE, timeout=1800, **kwargs)
    if result.returncode:
        raise RuntimeError('Docker/database command failed; inspect local container logs (no dump or credential output is emitted).')
    return result


def database_info(args):
    command = compose(args) + ['exec', '-T', 'db', 'sh', '-c', 'printf "%s" "$MYSQL_DATABASE"']
    name = run(command, stdout=subprocess.PIPE).stdout.decode()
    if not re.fullmatch(r'[A-Za-z0-9_]+', name): raise ValueError('Invalid database name.')
    container = run(compose(args) + ['ps', '-q', 'db'], stdout=subprocess.PIPE).stdout.decode().strip()
    if not container: raise ValueError('Database service is not running.')
    label = run([args.docker, 'inspect', '--format', '{{ index .Config.Labels "com.docker.compose.project" }}', container], stdout=subprocess.PIPE).stdout.decode().strip()
    if label != args.project: raise ValueError('Compose project label mismatch.')
    return name


def mysql_command(args):
    shell = 'export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysql --connect-timeout=10 --user="$MYSQL_USER" --database="$MYSQL_DATABASE" --batch --skip-column-names'
    command = compose(args) + ['exec', '-T', 'db', 'sh', '-c', shell]
    return command


def mysql_query(args, sql):
    shell = 'export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysql --connect-timeout=10 --user="$MYSQL_USER" --database="$MYSQL_DATABASE" --batch --skip-column-names -e "$1"'
    return run(compose(args) + ['exec', '-T', 'db', 'sh', '-c', shell, 'query', sql], stdout=subprocess.PIPE).stdout.decode().strip()


def sha256(path):
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def verify_archive(path):
    metadata = json.loads(Path(str(path) + '.json').read_text())
    if not isinstance(metadata, dict) or metadata.get('format') != 1 or metadata.get('sha256') != sha256(path):
        raise ValueError('Backup manifest/checksum mismatch.')
    # Validate gzip integrity before starting any database import.
    with gzip.open(path, 'rb') as source:
        while source.read(1024 * 1024): pass
    return metadata


def assert_restore_target(project, name, table_count):
    if not re.fullmatch(r'inventory-restore-[a-z0-9-]+', project):
        raise ValueError('Restore requires a disposable inventory-restore-* project.')
    if not re.fullmatch(r'[A-Za-z0-9_]+_restore', name):
        raise ValueError('Restore database must end in _restore.')
    if int(table_count) != 0: raise ValueError('Restore target must be empty; existing data is never overwritten.')


def backup(args):
    name = database_info(args)
    directory = Path(args.directory).resolve()
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    # Backups contain hashes and personal/business data: keep the directory private.
    if directory.stat().st_mode & 0o077: raise ValueError('Backup directory must have mode 0700.')
    stamp = time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()) + '-' + str(time.time_ns())
    final = directory / ('inventory-' + stamp + '.sql.gz')
    shell = 'export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysqldump --user="$MYSQL_USER" --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF --routines --events --triggers --hex-blob --skip-comments "$MYSQL_DATABASE"'
    command = compose(args) + ['exec', '-T', 'db', 'sh', '-c', shell]
    partial = None
    created_archive = False
    created_manifest = False
    try:
        with tempfile.NamedTemporaryFile(dir=directory, prefix='.dump-', delete=False) as plain:
            partial = Path(plain.name)
            run(command, stdout=plain)
        descriptor = os.open(final, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        created_archive = True
        with partial.open('rb') as source, os.fdopen(descriptor, 'wb') as raw:
            os.chmod(final, 0o600)
            with gzip.GzipFile(fileobj=raw, mode='wb', mtime=0) as compressed:
                shutil.copyfileobj(source, compressed)
        metadata = {'format':1, 'created_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'database':name, 'project':args.project, 'sha256':sha256(final), 'bytes':final.stat().st_size}
        manifest = Path(str(final) + '.json')
        descriptor = os.open(manifest, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        created_manifest = True
        with os.fdopen(descriptor, 'w') as output:
            os.chmod(manifest, 0o600)
            json.dump(metadata, output, indent=2)
            output.write('\n')
        verify_archive(final)
        print(json.dumps({'status':'backed_up', 'archive':str(final), 'sha256':metadata['sha256']}))
    except BaseException:
        if created_archive: final.unlink(missing_ok=True)
        if created_manifest: Path(str(final) + '.json').unlink(missing_ok=True)
        raise
    finally:
        if partial is not None: partial.unlink(missing_ok=True)


def restore(args):
    assert_restore_target(args.project, 'preflight_restore', 0)
    path = Path(args.archive).resolve()
    verify_archive(path)
    name = database_info(args)
    count = mysql_query(args, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')
    assert_restore_target(args.project, name, count)
    # mysql uses the target service's scoped user, not root. No in-place production restore.
    with tempfile.TemporaryFile() as plain:
        with gzip.open(path, 'rb') as source: shutil.copyfileobj(source, plain)
        plain.seek(0)
        run(mysql_command(args), stdin=plain, stdout=subprocess.DEVNULL)
    print(json.dumps({'status':'restored', 'project':args.project, 'database':name}))


def prune(args):
    directory = Path(args.directory).resolve()
    if args.keep < 1: raise ValueError('At least one backup must be retained.')
    archives = sorted(directory.glob('inventory-*.sql.gz'), key=lambda p:p.name, reverse=True)
    # A failed/corrupt newest backup must never trigger removal of older good backups.
    for path in archives: verify_archive(path)
    expired = archives[args.keep:]
    if args.apply:
        for path in expired:
            path.unlink()
            Path(str(path) + '.json').unlink()
    print(json.dumps({'status':'pruned' if args.apply else 'dry_run', 'expired':[p.name for p in expired], 'kept':len(archives)-len(expired)}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker', default=os.environ.get('DOCKER_BIN', 'docker'))
    parser.add_argument('--file', action='append', default=[])
    parser.add_argument('--env-file')
    parser.add_argument('--project', default='inventory-operations')
    commands = parser.add_subparsers(dest='action', required=True)
    p = commands.add_parser('backup'); p.add_argument('--directory', default='var/backups')
    p = commands.add_parser('restore'); p.add_argument('archive')
    p = commands.add_parser('prune'); p.add_argument('--directory', default='var/backups'); p.add_argument('--keep', type=int, default=7); p.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    args.file = args.file or ['compose.yaml']
    try: globals()[args.action](args)
    except (ValueError, RuntimeError, OSError, subprocess.TimeoutExpired) as error:
        print(json.dumps({'status':'failed', 'reason':str(error)}), file=sys.stderr)
        return 1
    return 0

if __name__ == '__main__': sys.exit(main())
