#!/usr/bin/env python3
"""OpenSSL CMS authenticated backup encryption. Scheduler needs only the recipient PUBLIC certificate."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile

spec=importlib.util.spec_from_file_location('dbops',Path(__file__).with_name('db-operations.py'))
ops=importlib.util.module_from_spec(spec); spec.loader.exec_module(ops)
FORMAT='cms-aes256-gcm-v1'


def private_directory(path,empty=False):
    path=Path(path).resolve(); path.mkdir(parents=True,exist_ok=True,mode=0o700)
    if path.stat().st_mode & 0o077: raise ValueError('Backup directory must have mode 0700.')
    if empty and any(path.iterdir()): raise ValueError('Decryption output directory must be empty.')
    return path


def run(command):
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=1800)
    if result.returncode: raise RuntimeError('Encryption/decryption command failed; no plaintext output is released.')
    return result.stdout


def verify(path):
    path=Path(path); metadata=json.loads(Path(str(path)+'.json').read_text())
    if not isinstance(metadata,dict) or metadata.get('format')!=FORMAT or metadata.get('sha256')!=ops.sha256(path): raise ValueError('Encrypted archive integrity mismatch.')
    return metadata


def publish_pair(archive,metadata,directory):
    directory=private_directory(directory)
    target=directory/Path(archive).name
    if target.exists() or Path(str(target)+'.json').exists(): raise ValueError('Replica already exists; existing backups are preserved.')
    with tempfile.TemporaryDirectory(prefix='.replica-',dir=directory) as work:
        staged=Path(work)/target.name
        shutil.copyfile(archive,staged); os.chmod(staged,0o600)
        manifest=Path(str(staged)+'.json'); manifest.write_text(json.dumps(metadata,indent=2)+'\n'); os.chmod(manifest,0o600)
        verify(staged)
        # Exclusive link publication prevents replacement of an existing valid replica.
        os.link(staged,target)
        try: os.link(manifest,Path(str(target)+'.json'))
        except BaseException: target.unlink(); raise
    verify(target)
    return target


def seal(archive,recipient,directory,openssl='openssl',replica=None):
    archive=Path(archive).resolve(); metadata=ops.verify_archive(archive)
    directory=private_directory(directory)
    run([openssl,'x509','-in',str(recipient),'-checkend','0','-noout'])
    certificate=run([openssl,'x509','-in',str(recipient),'-outform','DER'])
    target=directory/(archive.name+'.cms')
    if target.exists() or Path(str(target)+'.json').exists(): raise ValueError('Encrypted backup already exists.')
    with tempfile.TemporaryDirectory(prefix='.seal-',dir=directory) as work:
        bundle=Path(work)/'bundle.tar'; sealed=Path(work)/target.name
        with tarfile.open(bundle,'w') as tar:
            tar.add(archive,arcname='backup.sql.gz',recursive=False)
            tar.add(Path(str(archive)+'.json'),arcname='backup.sql.gz.json',recursive=False)
        os.chmod(bundle,0o600)
        run([openssl,'cms','-encrypt','-binary','-aes-256-gcm','-in',str(bundle),'-outform','DER','-out',str(sealed),'-recip',str(recipient),'-keyopt','rsa_padding_mode:oaep','-keyopt','rsa_oaep_md:sha256'])
        os.chmod(sealed,0o600)
        envelope={'format':FORMAT,'created_utc':metadata['created_utc'],'sha256':ops.sha256(sealed),'bytes':sealed.stat().st_size,'recipient_certificate_sha256':hashlib.sha256(certificate).hexdigest()}
        manifest=Path(str(sealed)+'.json'); manifest.write_text(json.dumps(envelope,indent=2)+'\n'); os.chmod(manifest,0o600)
        verify(sealed)
        os.link(sealed,target)
        try: os.link(manifest,Path(str(target)+'.json'))
        except BaseException: target.unlink(); raise
    result={'status':'encrypted','archive':str(target),'sha256':envelope['sha256']}
    if replica:
        if Path(replica).resolve()==directory: raise ValueError('Replica directory must differ from encrypted local directory.')
        copied=publish_pair(target,envelope,replica)
        result.update({'replica':str(copied),'replica_sha256':ops.sha256(copied),'off_host_verified':False})
    return result


def safe_members(tar):
    members=tar.getmembers()
    if len(members)!=2 or {m.name for m in members}!={'backup.sql.gz','backup.sql.gz.json'}: raise ValueError('Unexpected encrypted bundle contents.')
    if any(not m.isfile() or m.size<0 or m.size>2*1024*1024*1024 for m in members): raise ValueError('Unsafe encrypted bundle member.')
    return members


def unseal(archive,recipient,key,directory,openssl='openssl'):
    archive=Path(archive).resolve(); verify(archive)
    directory=private_directory(directory,empty=True)
    # Decrypted bytes stay in a private temporary directory until GCM authentication succeeds.
    with tempfile.TemporaryDirectory(prefix='inventory-unseal-') as work:
        bundle=Path(work)/'bundle.tar'
        run([openssl,'cms','-decrypt','-binary','-inform','DER','-in',str(archive),'-recip',str(recipient),'-inkey',str(key),'-out',str(bundle)])
        with tarfile.open(bundle,'r:') as tar:
            members=safe_members(tar)
            for member in members:
                source=tar.extractfile(member)
                if source is None: raise ValueError('Missing encrypted member.')
                path=Path(work)/member.name
                with source,path.open('xb') as output: shutil.copyfileobj(source,output)
                os.chmod(path,0o600)
        plain=Path(work)/'backup.sql.gz'; ops.verify_archive(plain)
        for name in ('backup.sql.gz','backup.sql.gz.json'):
            with (Path(work)/name).open('rb') as source,os.fdopen(os.open(directory/name,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600),'wb') as output: shutil.copyfileobj(source,output)
    return {'status':'decrypted','archive':str(directory/'backup.sql.gz')}


def prune(directory,keep=7,apply=False):
    if keep<1: raise ValueError('Keep at least one encrypted backup.')
    archives=sorted(Path(directory).glob('inventory-*.sql.gz.cms'),reverse=True)
    for path in archives: verify(path)
    expired=archives[keep:]
    if apply:
        for path in expired: path.unlink(); Path(str(path)+'.json').unlink()
    return {'status':'pruned' if apply else 'dry_run','expired':[p.name for p in expired],'kept':len(archives)-len(expired)}


def main():
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--openssl',default='openssl')
    actions=parser.add_subparsers(dest='action',required=True)
    p=actions.add_parser('seal'); p.add_argument('archive'); p.add_argument('--recipient',required=True); p.add_argument('--directory',required=True); p.add_argument('--replica-directory')
    p=actions.add_parser('unseal'); p.add_argument('archive'); p.add_argument('--recipient',required=True); p.add_argument('--key',required=True); p.add_argument('--directory',required=True)
    p=actions.add_parser('prune'); p.add_argument('--directory',required=True); p.add_argument('--keep',type=int,default=7); p.add_argument('--apply',action='store_true')
    args=parser.parse_args()
    try:
        if args.action=='seal': result=seal(args.archive,args.recipient,args.directory,args.openssl,args.replica_directory)
        elif args.action=='unseal': result=unseal(args.archive,args.recipient,args.key,args.directory,args.openssl)
        else: result=prune(args.directory,args.keep,args.apply)
        print(json.dumps(result)); return 0
    except (ValueError,RuntimeError,OSError,subprocess.TimeoutExpired) as error:
        print(json.dumps({'status':'failed','reason':str(error)}),file=sys.stderr); return 1

if __name__=='__main__': sys.exit(main())
