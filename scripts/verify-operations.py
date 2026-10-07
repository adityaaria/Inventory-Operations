#!/usr/bin/env python3
"""Real isolated backup/restore, production TLS, alert and load verification. No main-data mutation."""
import argparse
import secrets
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request
import ssl

ROOT=Path(__file__).resolve().parents[1]

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker',default=os.environ.get('DOCKER_BIN','docker'))
    parser.add_argument('--evidence',default='var/operations-evidence')
    parser.add_argument('--recovery-only', action='store_true', help='Run recovery slice while production images are being built')
    parser.add_argument('--business-flows', action='store_true', help='Populate optional operations before recovery verification')
    args=parser.parse_args()
    os.chdir(ROOT)
    stamp=str(time.time_ns()); source='inventory-e2e-ops-'+stamp; restore='inventory-restore-ops-'+stamp; production='inventory-load-ops-'+stamp
    work=ROOT/'var'/('operations-'+stamp); work.mkdir(parents=True,mode=0o700)
    evidence=Path(args.evidence).resolve(); evidence.mkdir(parents=True,exist_ok=True)
    backupdir=work/'backups'; tls=work/'tls'; tls.mkdir(mode=0o700)
    override=work/'source.yaml'; override.write_text('services:\n  app:\n    image: inventory-operations-app:latest\n')
    restore_password=secrets.token_urlsafe(18)  # per-run credential for the disposable stack
    restore_root_password=secrets.token_urlsafe(18)  # per-run credential for the disposable stack
    env=os.environ.copy(); env.update({'APP_PORT':'18087','DB_HOST_PORT':'13309','RESTORE_DATABASE':'inventory_order_management_restore','RESTORE_USERNAME':'inventory_restore','RESTORE_PASSWORD':restore_password,'RESTORE_ROOT_PASSWORD':restore_root_password})
    prod_env=env.copy(); prod_env.update({'DB_DATABASE':'inventory_order_management_restore','DB_USERNAME':'inventory_restore','DB_PASSWORD':restore_password,'DB_ROOT_PASSWORD':restore_root_password,'DB_VOLUME_NAME':restore+'_mysql-data','TLS_DIRECTORY':str(tls),'HTTP_PORT':'18088','HTTPS_PORT':'18443'})
    def run(command,environment=env,expect=0,input=None):
        result=subprocess.run(command,env=environment,input=input,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=1800)
        if result.returncode!=expect:
            raise RuntimeError('Command failed: '+ ' '.join(command[:5]) + '; inspect local container/build logs. '+result.stderr.decode()[-1200:])
        return result.stdout
    def compose(project,files):
        command=[args.docker,'compose','-p',project]
        for f in files: command+=['-f',str(f)]
        return command
    sourcecmd=compose(source,['compose.yaml',override]); restorecmd=compose(restore,['compose.restore.yaml']); prodcmd=compose(production,['compose.production.yaml'])
    checks=[]
    def check(name,condition):
        if not condition: raise AssertionError(name)
        checks.append(name)
    def http(path,secure=True):
        context=ssl.create_default_context(cafile=str(tls/'fullchain.pem')); context.minimum_version=ssl.TLSVersion.TLSv1_2
        url=('https://localhost:18443' if secure else 'http://localhost:18088')+path
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self,*unused): return None
        opener=urllib.request.build_opener(NoRedirect(),urllib.request.HTTPSHandler(context=context))
        try: response=opener.open(url,timeout=15)
        except urllib.error.HTTPError as error: response=error
        return response.code,response.headers,response.read().decode()
    started=[]
    try:
        started.append((sourcecmd,env)); run(sourcecmd+['up','-d','--no-build','--wait','--wait-timeout','120','app'])
        migrated=json.loads(run(sourcecmd+['exec','-T','app','php','scripts/migrate-operation-requests.php']))
        check('packaged additive migration safely runs on initialized schema',migrated['status']=='ready')
        business_migration=json.loads(run(sourcecmd+['exec','-T','app','php','scripts/migrate-business-operations.php']))
        check('packaged business migration safely repeats',business_migration['status']=='ready')
        # Real HTTP mutations create representative orders/receipts/issues before the recovery drill.
        run([sys.executable,'tests/HTTP/end-to-end.py','--project',source,'--url','http://localhost:18087','--output',str(evidence/'http.json')])
        check('business HTTP flows pass before backup',json.loads((evidence/'http.json').read_text())['failed']==0)
        if args.business_flows:
            run([sys.executable,'tests/HTTP/business-enhancements.py','--project',source,'--url','http://localhost:18087','--output',str(evidence/'business-http.json')])
            check('six optional business flows pass before backup',json.loads((evidence/'business-http.json').read_text())['failed']==0)
        before=json.loads(run(sourcecmd+['exec','-T','app','php','scripts/database-fingerprint.php']))
        command=[sys.executable,'scripts/db-operations.py','--docker',args.docker,'--project',source,'--file','compose.yaml','--file',str(override)]
        backed=json.loads(run(command+['backup','--directory',str(backupdir)])); archive=backed['archive']
        check('backup permissions restrict local access', (Path(archive).stat().st_mode & 0o777)==0o600)
        after=json.loads(run(sourcecmd+['exec','-T','app','php','scripts/database-fingerprint.php']))
        check('backup does not mutate any application table',before==after)
        # Test recipient keys remain in ignored private workspace; never ship them as evidence.
        crypto=work/'crypto'; crypto.mkdir(mode=0o700)
        recipient=crypto/'recipient.pem'; key=crypto/'recipient.key'
        run(['openssl','req','-x509','-nodes','-newkey','rsa:2048','-days','1','-subj','/CN=Isolated recovery verification','-keyout',str(key),'-out',str(recipient)])
        os.chmod(key,0o600)
        sealed=json.loads(run([sys.executable,'scripts/secure-backup.py','seal',archive,'--recipient',str(recipient),'--directory',str(crypto/'encrypted'),'--replica-directory',str(crypto/'replica')]))
        check('encrypted replica checksum matches source',sealed['sha256']==sealed['replica_sha256'])
        decrypted=json.loads(run([sys.executable,'scripts/secure-backup.py','unseal',sealed['replica'],'--recipient',str(recipient),'--key',str(key),'--directory',str(crypto/'decrypted')]))
        import hashlib
        digest=lambda path: hashlib.sha256(Path(path).read_bytes()).hexdigest()
        check('authenticated decryption preserves original dump bytes',digest(archive)==digest(decrypted['archive']))
        archive=decrypted['archive']
        (evidence/'encryption.json').write_text(json.dumps({'passed':True,'cipher':'CMS AES-256-GCM / RSA-OAEP SHA256','replica_checksum_matches':True,'decrypted_dump_matches':True,'off_host_verified':False,'key_scope':'one-day disposable test recipient; not production'},indent=2)+'\n')
        started.append((restorecmd,env)); run(restorecmd+['up','-d','--no-build','--wait','--wait-timeout','120','app'])
        recovery=[sys.executable,'scripts/db-operations.py','--docker',args.docker,'--project',restore,'--file','compose.restore.yaml','restore',archive]
        recovery_start=time.perf_counter(); run(recovery)
        restored=json.loads(run(restorecmd+['exec','-T','app','php','scripts/database-fingerprint.php']))
        recovery_seconds=round(time.perf_counter()-recovery_start,3)
        check('every table count and row digest survives restore',before==restored)
        check('stock ledger and source-order digests survive restore',all(before[t]==restored[t] for t in ('product_stocks','stock_ledger','purchase_orders','sales_orders','audit_logs')))
        if args.business_flows:
            check('populated closure rejection operations and return allowances survive restore',all(before[t]['rows']>0 and before[t]==restored[t] for t in ('purchase_order_closures','sales_order_rejections','inventory_operations','inventory_operation_items','inventory_return_totals')))
        run(recovery,expect=1); check('second restore refuses populated target',True)
        run([sys.executable,'scripts/db-operations.py','--docker',args.docker,'--project','inventory-operations','restore',archive],expect=1); check('restore refuses main application project',True)
        (evidence/'restore.json').write_text(json.dumps({'checks':checks.copy(),'tables':len(before),'source':before,'restored':restored,'restore_seconds':recovery_seconds,'backup_bytes':Path(archive).stat().st_size},indent=2)+'\n')
        if args.recovery_only:
            (evidence/'recovery-summary.json').write_text(json.dumps({'checks':len(checks),'passed':checks,'failed':0},indent=2)+'\n')
            print(json.dumps({'checks':len(checks),'failed':0,'scope':'recovery only','evidence':str(evidence)}))
            return
        # A stopped restored DB volume is promoted into the isolated production topology.
        run(restorecmd+['stop'])
        run(['openssl','req','-x509','-nodes','-newkey','rsa:2048','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1','-keyout',str(tls/'privkey.pem'),'-out',str(tls/'fullchain.pem')])
        started.append((prodcmd,prod_env)); run(prodcmd+['up','-d','--no-build','--wait','--wait-timeout','120'],environment=prod_env)
        status,headers,body=http('/login'); check('production TLS certificate validates and login renders',status==200 and 'csrf_token' in body)
        check('production session cookie Secure HttpOnly SameSite',all(v in headers.get('Set-Cookie','') for v in ('secure','HttpOnly','SameSite=Lax')))
        check('HTTPS security headers',headers.get('X-Content-Type-Options')=='nosniff' and 'max-age=' in headers.get('Strict-Transport-Security',''))
        check('HTTP redirects to HTTPS',http('/login',secure=False)[0]==308)
        check('internal readiness is not public',http('/health/ready')[0]==403)
        check('arbitrary PHP files are not exposed',http('/router.php')[0]==404)
        check('dotfiles are blocked',http('/.env')[0]==403)
        check('anonymous API JSON 401',http('/api/products/SKU-DEMO-001/availability')[0]==401)
        inspect=json.loads(run([args.docker,'inspect',production+'-db-1']))[0]
        check('production database has no published host port',not inspect['NetworkSettings']['Ports'].get('3306/tcp'))
        run(prodcmd+['exec','-T','app','php','-r','exit(extension_loaded("xdebug") || extension_loaded("pcov") || is_dir("vendor/phpunit") ? 1 : 0);'],environment=prod_env)
        check('production excludes debugger coverage and test packages',True)
        monitor=[sys.executable,'scripts/monitor.py','--docker',args.docker,'--project',production,'--file','compose.production.yaml','--backup-directory',str(backupdir)]
        healthy=json.loads(run(monitor,environment=prod_env)); check('monitor healthy baseline',healthy['status']=='healthy')
        run(prodcmd+['exec','-T','app','php','-r','require "vendor/autoload.php"; (new App\\Support\\JsonFileLogger("var/log/app.log"))->log("error", "isolated alert verification");'],environment=prod_env)
        alerted=json.loads(run(monitor,environment=prod_env,expect=1)); check('monitor alerts on application errors','application_error_threshold' in alerted['alerts'])
        run(prodcmd+['exec','-T','app','php','-r','unlink("var/log/app.log");'],environment=prod_env)
        run(prodcmd+['stop','db'],environment=prod_env)
        notready=json.loads(run(monitor,environment=prod_env,expect=1)); check('monitor alerts on database loss','application_not_ready' in notready['alerts'])
        run(prodcmd+['start','db'],environment=prod_env)
        # Wait for real readiness, not an assumed successful restart.
        for attempt in range(30):
            probe=subprocess.run(prodcmd+['exec','-T','app','php','scripts/health-check.php'],env=prod_env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            if probe.returncode==0: break
            time.sleep(1)
        else: raise RuntimeError('Database did not recover readiness.')
        check('readiness recovers after database restart',True)
        run([sys.executable,'tests/HTTP/load.py','--docker',args.docker,'--project',production,'--url','https://localhost:18443','--ca-file',str(tls/'fullchain.pem'),'--users','8','--requests-per-user','40','--output',str(evidence/'load-burst.json')])
        check('8 concurrent sessions / 320 read requests within latency budget',json.loads((evidence/'load-burst.json').read_text())['passed'])
        run([sys.executable,'tests/HTTP/load.py','--docker',args.docker,'--project',production,'--url','https://localhost:18443','--ca-file',str(tls/'fullchain.pem'),'--users','8','--requests-per-user','400','--duration-seconds','60','--output',str(evidence/'load-sustained.json')])
        check('60-second paced load / 3200 reads within latency budget',json.loads((evidence/'load-sustained.json').read_text())['passed'])
        (evidence/'monitor.json').write_text(json.dumps({'baseline':healthy,'error_injection':alerted,'database_stopped':notready},indent=2)+'\n')
        (evidence/'summary.json').write_text(json.dumps({'checks':len(checks),'passed':checks,'failed':0,'device_scope':'Chrome emulation covered separately; Android/iPhone physical checks pending user execution','certificate':'one-day self-signed local CA trusted explicitly; not a publicly issued production certificate'},indent=2)+'\n')
        print(json.dumps({'checks':len(checks),'failed':0,'evidence':str(evidence)}))
    finally:
        # Never remove the main application project or an untracked volume.
        cleanup_failures=[]
        for command,environment in reversed(started):
            result=subprocess.run(command+['down','-v'],env=environment,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=120)
            if result.returncode: cleanup_failures.append(command[3])
        (evidence/'cleanup.json').write_text(json.dumps({'failed':cleanup_failures,'projects':len(started)},indent=2)+'\n')
        if cleanup_failures: raise RuntimeError('Disposable cleanup failed; inspect the recorded project list before deleting any volumes.')
        if not cleanup_failures:
            shutil.rmtree(work)

if __name__=='__main__': main()
