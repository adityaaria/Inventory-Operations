#!/usr/bin/env python3
"""Large native-service fixture and TLS capacity tests; never uses the main database."""
import argparse
import secrets
import csv
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
BASE_URL='https://localhost:18445'
CA_FILE='fullchain.pem'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*unused): return None


class TlsClient:
    """Cookie-keeping HTTPS client for the disposable stack; never follows redirects."""
    def __init__(self,ca_file):
        context=ssl.create_default_context(cafile=str(ca_file)); context.minimum_version=ssl.TLSVersion.TLSv1_2
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect(),urllib.request.HTTPSHandler(context=context))

    def get(self,path,data=None):
        start=time.perf_counter()
        req=urllib.request.Request(BASE_URL+path,data=urllib.parse.urlencode(data).encode() if data else None)
        try: response=self.opener.open(req,timeout=30)
        except urllib.error.HTTPError as error: response=error
        body=response.read().decode(); return response.code,body,round((time.perf_counter()-start)*1000,3)


def run_command(cmd,env,stdout=subprocess.PIPE):
    result=subprocess.run(cmd,env=env,stdout=stdout,stderr=subprocess.PIPE,timeout=3600)
    if result.returncode: raise RuntimeError('Capacity command failed: '+ ' '.join(cmd[:6])+' '+result.stderr.decode()[-1500:])
    return result.stdout


def page_checks(client):
    checks=[]
    for path in ['/products?q=Capacity&page=1000','/purchase-orders?q=CAPACITY&page=1000','/sales-orders?q=CAPACITY&page=1000','/reports?type=orders&page=2000']:
        status,body,elapsed=client.get(path); assert status==200,path
        # Server page body must contain at most 10 data rows, independent of dataset size.
        tbody=re.search(r'<tbody>(.*?)</tbody>',body,re.S)
        count=len(re.findall(r'<tr[\s>]',tbody.group(1))) if tbody else 0
        assert 1<=count<=10,(path,count)
        checks.append({'path':path,'status':status,'rows':count,'bytes':len(body.encode()),'elapsed_ms':elapsed})
    return checks


def csv_checks(client,metrics):
    checks=[]
    for path,expected in [('/reports/orders.csv',metrics['purchase_orders_rows']+metrics['sales_orders_rows']),('/reports/stock-ledger.csv',metrics['stock_ledger_rows'])]:
        status,body,elapsed=client.get(path); count=sum(1 for _ in csv.reader(io.StringIO(body)))-1
        assert status==200 and count==expected,(path,count,expected)
        checks.append({'path':path,'status':status,'rows':count,'bytes':len(body.encode()),'elapsed_ms':elapsed})
    return checks

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker',default=os.environ.get('DOCKER_BIN','docker'))
    parser.add_argument('--products',type=int,default=10000)
    parser.add_argument('--order-pairs',type=int,default=10000)
    parser.add_argument('--evidence',default='var/capacity-evidence')
    args=parser.parse_args(); os.chdir(ROOT)
    if not 2<=args.products<=50000 or not 1<=args.order_pairs<=50000: parser.error('Fixture counts out of bounds.')
    project='inventory-load-capacity-'+str(time.time_ns()); volume=project+'_mysql-data'
    work=ROOT/'var'/project; work.mkdir(parents=True,mode=0o700)
    evidence=Path(args.evidence).resolve(); evidence.mkdir(parents=True,exist_ok=True)
    tls=work/'tls'; tls.mkdir(mode=0o700)
    db_password=secrets.token_urlsafe(18)  # per-run credential for the disposable stack
    db_root_password=secrets.token_urlsafe(18)  # per-run credential for the disposable stack
    env=os.environ.copy(); env.update({'DB_DATABASE':'inventory_capacity_benchmark','DB_USERNAME':'inventory_capacity','DB_PASSWORD':db_password,'DB_ROOT_PASSWORD':db_root_password,'DB_VOLUME_NAME':volume,'TLS_DIRECTORY':str(tls),'HTTP_PORT':'18090','HTTPS_PORT':'18445','CAPACITY_PROJECT':project})
    command=[args.docker,'compose','-p',project,'-f','compose.production.yaml','-f','compose.capacity.yaml']
    def run(cmd,stdout=subprocess.PIPE): return run_command(cmd,env,stdout)
    created=False
    try:
        run([args.docker,'volume','create',volume]); created=True
        run(['openssl','req','-x509','-nodes','-newkey','rsa:2048','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1','-keyout',str(tls/'privkey.pem'),'-out',str(tls/CA_FILE)])
        run(command+['up','-d','--no-build','--wait','--wait-timeout','180'])
        result=run(command+['exec','-T','app','php','tests/Support/large-dataset.php',str(args.products),str(args.order_pairs)])
        metrics=json.loads(result); (evidence/'dataset.json').write_text(json.dumps(metrics,indent=2)+'\n')
        assert metrics['passed']
        client=TlsClient(tls/CA_FILE)
        _,body,_=client.get('/login'); token=re.search(r'name="csrf_token" value="([a-f0-9]+)"',body).group(1)
        assert client.get('/login',{'email':'admin@example.test','password':'password','csrf_token':token})[0]==302
        checks=page_checks(client)+csv_checks(client,metrics)
        (evidence/'functional.json').write_text(json.dumps({'passed':True,'checks':checks},indent=2)+'\n')
        loadbase=[sys.executable,'tests/HTTP/load.py','--docker',args.docker,'--project',project,'--url',BASE_URL,'--ca-file',str(tls/CA_FILE),'--profile','capacity']
        run(loadbase+['--users','8','--requests-per-user','400','--duration-seconds','60','--output',str(evidence/'load-admin.json')])
        run(loadbase+['--role','warehouse','--users','4','--requests-per-user','40','--output',str(evidence/'load-warehouse.json')])
        # PHP peak memory and timings use the same repository queries as report/dashboard HTTP.
        php='require "vendor/autoload.php"; $c=require "config/config.php"; $p=(new App\\Support\\DatabaseFactory($c))->create(); $q=new App\\Repository\\MySql\\MySqlOperationalQueryRepository($p); $t=microtime(true); $q->adminDashboard(); $q->warehouseDashboard(); $q->reportSummary("orders",null,null,null); $n=0; foreach($q->iterateReportRows("stock-ledger",null,null,null) as $row) $n++; echo json_encode(["ledger_rows"=>$n,"elapsed_ms"=>round((microtime(true)-$t)*1000,3),"peak_php_bytes"=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR);'
        profile=json.loads(run(command+['exec','-T','app','php','-r',php])); assert profile['peak_php_bytes']<128*1024*1024
        (evidence/'memory.json').write_text(json.dumps(profile,indent=2)+'\n')
        (evidence/'summary.json').write_text(json.dumps({'passed':True,'project':project,'scope':'10000 synthetic catalog records / 20000 business-service orders by default, localhost TLS, independent sessions; no production capacity SLA'},indent=2)+'\n')
        print(json.dumps({'passed':True,'evidence':str(evidence),'project':project}))
    finally:
        if created:
            result=subprocess.run(command+['down','-v'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=120)
            removal=subprocess.run([args.docker,'volume','rm',volume],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=60)
            (evidence/'cleanup.json').write_text(json.dumps({'passed':result.returncode==0 and removal.returncode==0,'project':project},indent=2)+'\n')
            if result.returncode or removal.returncode: raise RuntimeError('Capacity cleanup failed; inspect recorded disposable project only.')

if __name__=='__main__': main()
