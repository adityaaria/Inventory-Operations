#!/usr/bin/env python3
"""Single-host scheduled backup/encryption/replica/monitor job. No outbound notification messages."""
import argparse
import datetime
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from zoneinfo import ZoneInfo

spec=importlib.util.spec_from_file_location('securebackup',Path(__file__).with_name('secure-backup.py'))
secure=importlib.util.module_from_spec(spec); spec.loader.exec_module(secure)


def due(now,last_date,hour,minute):
    return last_date!=now.date().isoformat() and (now.hour,now.minute)>=(hour,minute)


def atomic_json(path,value):
    path=Path(path)
    descriptor,temporary=tempfile.mkstemp(prefix='.state-',dir=path.parent)
    try:
        with os.fdopen(descriptor,'w') as output:
            json.dump(value,output,indent=2); output.write('\n'); output.flush(); os.fsync(output.fileno())
        os.replace(temporary,path)
    finally:
        if Path(temporary).exists(): Path(temporary).unlink()


def run(command,root,allowed=(0,)):
    result=subprocess.run(command,cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=1800)
    if result.returncode not in allowed: raise RuntimeError('Operational subprocess failed.')
    return json.loads(result.stdout)


def execute(config,force=False,now=None):
    root=Path(config['root']).resolve()
    if not (root/'scripts/db-operations.py').is_file(): raise ValueError('Invalid application root.')
    project=config['project']
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]+',project): raise ValueError('Invalid Compose project.')
    state_dir=secure.private_directory(config['status_directory'])
    hour,minute=map(int,config.get('daily_time','01:15').split(':'))
    if not 0<=hour<=23 or not 0<=minute<=59: raise ValueError('Invalid daily time.')
    now=now or datetime.datetime.now(ZoneInfo(config.get('timezone','Asia/Jakarta')))
    if now.tzinfo is None: raise ValueError('Schedule time must include a timezone.')
    lock_path=state_dir/'job.lock'
    with os.fdopen(os.open(lock_path,os.O_CREAT|os.O_RDWR,0o600),'a') as lock:
        try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: return {'status':'busy','alerts':[]}
        state_path=state_dir/'state.json'
        state=json.loads(state_path.read_text()) if state_path.exists() else {}
        alerts=[]; backup_done=False
        python=config.get('python',sys.executable)
        base=[python,str(root/'scripts/db-operations.py'),'--docker',config.get('docker','docker'),'--project',project]
        for file in config.get('compose_files',['compose.yaml']): base+=['--file',str(file)]
        if config.get('env_file'): base+=['--env-file',config['env_file']]
        wanted=force or state.get('pending_archive') or due(now,state.get('last_success_date'),hour,minute)
        if wanted:
            try:
                recipient=Path(config['recipient_certificate']); openssl=config.get('openssl','openssl')
                if not Path(config['replica_directory']).is_dir(): raise ValueError('Replica destination is unavailable; do not create a missing mount.')
                if config.get('replica_mount') and not Path(config['replica_mount']).is_mount(): raise ValueError('Replica mount is unavailable.')
                secure.run([openssl,'x509','-in',str(recipient),'-checkend','0','-noout'])
                archive=state.get('pending_archive')
                if archive:
                    secure.ops.verify_archive(Path(archive))
                else:
                    backed=run(base+['backup','--directory',config['backup_directory']],root)
                    archive=backed['archive']; state['pending_archive']=archive; atomic_json(state_path,state)
                encrypted=Path(config['encrypted_directory'])/(Path(archive).name+'.cms')
                if encrypted.exists(): metadata=secure.verify(encrypted)
                else:
                    result=secure.seal(archive,recipient,config['encrypted_directory'],openssl)
                    encrypted=Path(result['archive']); metadata=secure.verify(encrypted)
                replica_dir=secure.private_directory(config['replica_directory'])
                if replica_dir.resolve()==Path(config['encrypted_directory']).resolve(): raise ValueError('Replica must have a separate location.')
                replica=replica_dir/encrypted.name
                if replica.exists():
                    if secure.verify(replica)['sha256']!=metadata['sha256']: raise ValueError('Existing replica does not match encrypted backup.')
                else: secure.publish_pair(encrypted,metadata,replica_dir)
                created=datetime.datetime.fromisoformat(metadata['created_utc'].replace('Z','+00:00'))
                state['last_success_date']=created.astimezone(now.tzinfo).date().isoformat()
                state['last_success_utc']=metadata['created_utc']; state['encrypted_sha256']=metadata['sha256']
                state.pop('pending_archive',None); atomic_json(state_path,state); backup_done=True
                # Retention runs only after a valid encrypted replica exists.
                keep=int(config.get('keep',7))
                secure.prune(config['encrypted_directory'],keep,True); secure.prune(config['replica_directory'],keep,True)
                run(base+['prune','--directory',config['backup_directory'],'--keep',str(keep),'--apply'],root)
            except (ValueError,RuntimeError,OSError,KeyError,subprocess.TimeoutExpired): alerts.append('backup_pipeline_failed')
        monitor=[python,str(root/'scripts/monitor.py'),'--docker',config.get('docker','docker'),'--project',project,'--backup-directory',config['backup_directory']]
        for file in config.get('compose_files',['compose.yaml']): monitor+=['--file',str(file)]
        if config.get('env_file'): monitor+=['--env-file',config['env_file']]
        try: alerts+=run(monitor,root,(0,1))['alerts']
        except (ValueError,RuntimeError,OSError,KeyError,subprocess.TimeoutExpired): alerts.append('monitor_probe_failed')
        # Also detect loss/corruption of the newest encrypted replica independently of plaintext backup freshness.
        if state.get('encrypted_sha256'):
            replicas=sorted(Path(config['replica_directory']).glob('inventory-*.sql.gz.cms'),reverse=True)
            try:
                if not replicas or secure.verify(replicas[0])['sha256']!=state['encrypted_sha256']: alerts.append('encrypted_replica_missing_or_corrupt')
            except (ValueError,OSError): alerts.append('encrypted_replica_missing_or_corrupt')
        else: alerts.append('encrypted_backup_missing')
        alerts=sorted(set(alerts)); result={'status':'alert' if alerts else 'healthy','project':project,'checked_at':now.isoformat(),'backup_completed':backup_done,'last_backup_utc':state.get('last_success_utc'),'alerts':alerts,'off_host_verified':False}
        previous=state.get('last_alerts')
        if previous!=alerts:
            events=state_dir/'alerts.jsonl'
            if events.exists() and events.stat().st_size>1048576: os.replace(events,state_dir/'alerts.previous.jsonl')
            with os.fdopen(os.open(events,os.O_CREAT|os.O_APPEND|os.O_WRONLY,0o600),'a') as output: output.write(json.dumps(result)+'\n')
        state['last_alerts']=alerts; atomic_json(state_path,state); atomic_json(state_dir/'status.json',result)
        return result


def main():
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--config',required=True); parser.add_argument('--force-backup',action='store_true'); args=parser.parse_args()
    try:
        result=execute(json.loads(Path(args.config).read_text()),args.force_backup)
        print(json.dumps(result)); return 1 if result['status']=='alert' else (2 if result['status']=='busy' else 0)
    except (ValueError,RuntimeError,OSError,KeyError,subprocess.TimeoutExpired) as error:
        print(json.dumps({'status':'failed','reason':str(error)}),file=sys.stderr); return 1

if __name__=='__main__': sys.exit(main())
