#!/usr/bin/env python3
"""Render launchd/systemd schedules for a reviewed job config; does not install or start services."""
import argparse
import json
from pathlib import Path
import plistlib
import sys

parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--config',required=True); parser.add_argument('--output',default='var/scheduler'); args=parser.parse_args()
config_path=Path(args.config).resolve(); config=json.loads(config_path.read_text()); root=Path(config['root']).resolve(); output=Path(args.output); output.mkdir(parents=True,exist_ok=True,mode=0o700)
status=Path(config['status_directory'])
python=config.get('python',sys.executable)
job={'Label':'com.inventory.operations','ProgramArguments':[python,str(root/'scripts/operations-job.py'),'--config',str(config_path)],'WorkingDirectory':str(root),'StartInterval':300,'RunAtLoad':True,'ProcessType':'Background','StandardOutPath':'/dev/null','StandardErrorPath':str(status/'scheduler-error.log')}
(output/'com.inventory.operations.plist').write_bytes(plistlib.dumps(job))
def quoted(value):
    return '"'+str(value).replace('\\','\\\\').replace('"','\\"').replace('%','%%')+'"'
command=' '.join(quoted(v) for v in [python,str(root/'scripts/operations-job.py'),'--config',str(config_path)])
(output/'inventory-operations.service').write_text('[Unit]\nDescription=Inventory backup and monitoring\nAfter=docker.service\n[Service]\nType=oneshot\nWorkingDirectory='+quoted(root)+'\nExecStart='+command+'\nUMask=0077\n')
(output/'inventory-operations.timer').write_text('[Unit]\nDescription=Inventory operations every five minutes\n[Timer]\nOnBootSec=2min\nOnUnitActiveSec=5min\n[Install]\nWantedBy=timers.target\n')
print(json.dumps({'status':'rendered','output':str(output.resolve()),'installed':False,'daily_backup':'01:15 Asia/Jakarta (job config)','monitor_interval_seconds':300}))
