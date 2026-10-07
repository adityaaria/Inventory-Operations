#!/usr/bin/env python3
"""Local operational alerts as JSON + exit status; does not send external messages."""
import argparse
import datetime
import importlib.util
import json
from pathlib import Path
import subprocess
import sys

spec = importlib.util.spec_from_file_location('dbops', Path(__file__).with_name('db-operations.py'))
dbops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dbops)


def backup_alert(directory, max_age, now):
    archives = sorted(Path(directory).glob('inventory-*.sql.gz'), reverse=True)
    if not archives: return 'backup_missing'
    try:
        metadata = dbops.verify_archive(archives[0])
        stamp = datetime.datetime.fromisoformat(metadata['created_utc'].replace('Z', '+00:00')).timestamp()
        age = now - stamp
        if age < -300: return 'backup_timestamp_invalid'
        if age > max_age: return 'backup_stale'
    except (ValueError, OSError, KeyError, TypeError): return 'backup_corrupt'
    return None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker', default='docker')
    parser.add_argument('--file', action='append', default=[])
    parser.add_argument('--env-file')
    parser.add_argument('--project', default='inventory-operations')
    parser.add_argument('--backup-directory', default='var/backups')
    parser.add_argument('--backup-max-age-hours', type=int, default=26)
    parser.add_argument('--error-window-seconds', type=int, default=900)
    parser.add_argument('--error-threshold', type=int, default=1)
    args = parser.parse_args(); args.file = args.file or ['compose.yaml']
    if args.backup_max_age_hours < 1 or args.error_threshold < 1 or not 1 <= args.error_window_seconds <= 86400:
        parser.error('Invalid monitoring thresholds.')
    alerts = []
    try:
        services = dbops.run(dbops.compose(args) + ['config', '--services'], stdout=subprocess.PIPE).stdout.decode().splitlines()
        for service in ('app', 'db', 'web'):
            if service not in services: continue
            container = dbops.run(dbops.compose(args) + ['ps', '-q', service], stdout=subprocess.PIPE).stdout.decode().strip()
            if not container: alerts.append(service + '_not_running'); continue
            state = json.loads(dbops.run([args.docker, 'inspect', '--format', '{{json .State}}', container], stdout=subprocess.PIPE).stdout)
            if not state.get('Running') or state.get('Health', {}).get('Status', 'healthy') != 'healthy': alerts.append(service + '_unhealthy')
        health = subprocess.run(dbops.compose(args) + ['exec', '-T', 'app', 'php', 'scripts/health-check.php'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)
        if health.returncode: alerts.append('application_not_ready')
        logs = json.loads(dbops.run(dbops.compose(args) + ['exec', '-T', 'app', 'php', 'scripts/log-summary.php', str(args.error_window_seconds)], stdout=subprocess.PIPE).stdout)
        if logs['errors'] >= args.error_threshold: alerts.append('application_error_threshold')
        if logs['malformed_lines']: alerts.append('malformed_application_log')
    except (ValueError, RuntimeError, OSError, subprocess.TimeoutExpired): alerts.append('monitor_probe_failed')
    import time
    issue = backup_alert(args.backup_directory, args.backup_max_age_hours * 3600, time.time())
    if issue: alerts.append(issue)
    print(json.dumps({'status':'alert' if alerts else 'healthy', 'project':args.project, 'alerts':alerts}))
    return 1 if alerts else 0

if __name__ == '__main__': sys.exit(main())
