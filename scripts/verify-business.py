#!/usr/bin/env python3
"""Run six business-flow HTTP checks against a fresh disposable project only."""
import argparse,json,os,subprocess,sys,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--docker',default='docker');parser.add_argument('--evidence',default='var/business-evidence');parser.add_argument('--keep-running',action='store_true');args=parser.parse_args()
    os.chdir(ROOT);project='inventory-e2e-business-'+str(time.time_ns());work=ROOT/'var'/project;work.mkdir(parents=True,mode=0o700);evidence=Path(args.evidence).resolve();evidence.mkdir(parents=True,exist_ok=True)
    override=work/'runtime.yaml';override.write_text('services:\n  app:\n    image: inventory-operations-app:latest\n')
    base=[args.docker,'compose','-p',project,'-f','compose.yaml','-f',str(override)];env=os.environ.copy();env.update({'APP_PORT':'18086','DB_HOST_PORT':'13308'});success=False
    try:
        subprocess.run(base+['up','-d','--no-build','--wait','--wait-timeout','120','app'],env=env,check=True)
        subprocess.run(base+['exec','-T','app','php','scripts/migrate-business-operations.php'],env=env,check=True)
        subprocess.run([sys.executable,'tests/HTTP/business-enhancements.py','--project',project,'--url','http://localhost:18086','--output',str(evidence/'http.json')],env=env,check=True)
        (evidence/'context.json').write_text(json.dumps({'project':project,'url':'http://localhost:18086','compose_override':str(override),'kept_running':args.keep_running},indent=2)+'\n');success=True
        print(json.dumps({'passed':True,'project':project,'evidence':str(evidence)}))
    finally:
        if not(success and args.keep_running):
            result=subprocess.run(base+['down','-v'],env=env,check=True);(evidence/'cleanup.json').write_text(json.dumps({'project':project,'passed':result.returncode==0})+'\n')
if __name__=='__main__':main()
