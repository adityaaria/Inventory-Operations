#!/usr/bin/env python3
"""Bounded read-only load benchmark against a disposable local inventory-load-* project."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import http.cookiejar
import json
import math
from pathlib import Path
import re
import statistics
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *unused): return None


def percentile(values, fraction):
    return sorted(values)[max(0, math.ceil(len(values) * fraction) - 1)]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', default='http://localhost:18086')
    parser.add_argument('--project', required=True)
    parser.add_argument('--docker', default='docker')
    parser.add_argument('--users', type=int, default=8)
    parser.add_argument('--requests-per-user', type=int, default=40)
    parser.add_argument('--p95-budget-ms', type=float, default=2000)
    parser.add_argument('--duration-seconds', type=float, default=0, help='Pace the bounded request count across 0..300 seconds')
    parser.add_argument('--output', required=True)
    parser.add_argument('--profile', choices=['default','capacity'], default='default')
    parser.add_argument('--role', choices=['admin','sales','warehouse'], default='admin')
    parser.add_argument('--ca-file', help='Trusted local TLS test certificate; certificate validation stays enabled')
    args = parser.parse_args()
    parsed = urllib.parse.urlparse(args.url)
    if parsed.scheme not in ('http','https') or parsed.fragment or not re.fullmatch(r'inventory-load-[a-z0-9-]+', args.project) or parsed.hostname not in ('localhost','127.0.0.1') or parsed.username is not None or parsed.password is not None or parsed.query or parsed.path not in ('','/'):
        parser.error('Load tests require a disposable inventory-load-* project at localhost.')
    if not 1 <= args.users <= 32 or not 1 <= args.requests_per_user <= 500 or args.p95_budget_ms <= 0 or not 0 <= args.duration_seconds <= 300:
        parser.error('Use 1..32 users, 1..500 requests each and a positive latency budget.')
    inspected = json.loads(subprocess.check_output([args.docker,'inspect', args.project + '-web-1' if parsed.scheme=='https' else args.project+'-app-1']))[0]
    if inspected['Config']['Labels']['com.docker.compose.project'] != args.project: parser.error('Container project mismatch.')
    port = parsed.port or (443 if parsed.scheme=='https' else 80)
    if not any(binding['HostPort']==str(port) for bindings in inspected['NetworkSettings']['Ports'].values() if bindings for binding in bindings): parser.error('URL does not map to the target project.')
    import ssl
    context = ssl.create_default_context(cafile=args.ca_file) if args.ca_file else ssl.create_default_context()
    paths=['/dashboard','/products?q=Tirta','/purchase-orders','/sales-orders','/reports?type=orders','/reports/orders.csv','/api/products/BIS-0001/availability']
    if args.profile=='capacity':
        paths=['/dashboard','/products?q=Capacity&page=1000','/sales-orders?q=CAPACITY&page=1000','/api/products/CAPACITY-000000/availability']
        if args.role!='sales': paths += ['/purchase-orders?q=CAPACITY&page=1000']
        if args.role!='warehouse': paths += ['/reports?type=orders&page=2000','/reports/orders.csv']
        else: paths += ['/reports?type=stock-ledger&page=2000','/reports/stock-ledger.csv']
    email={'admin':'admin@example.test','sales':'sales1@example.test','warehouse':'warehouse1@example.test'}[args.role]
    def worker(index):
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),NoRedirect(),urllib.request.HTTPSHandler(context=context))
        def request(path, data=None):
            req = urllib.request.Request(args.url.rstrip('/')+path,data=urllib.parse.urlencode(data).encode() if data else None)
            try:
                response = opener.open(req, timeout=20)
                return response.status,response.read().decode()
            except urllib.error.HTTPError as error: return error.code,error.read().decode()
        status,body=request('/login')
        if status!=200: return [],['login_get_failed']
        token=re.search(r'name="csrf_token" value="([a-f0-9]+)"',body)
        if token is None: return [],['csrf_missing']
        status,_=request('/login',{'email':email,'password':'password','csrf_token':token.group(1)})
        if status!=302: return [],['login_post_failed']
        samples=[]; failures=[]
        schedule=time.perf_counter()
        for i in range(args.requests_per_user):
            if args.duration_seconds:
                time.sleep(max(0, schedule+i*args.duration_seconds/args.requests_per_user-time.perf_counter()))
            path=paths[(i+index)%len(paths)]; start=time.perf_counter()
            try:
                status,body=request(path)
                samples.append((time.perf_counter()-start)*1000)
                if status!=200: failures.append({'path':path,'status':status})
                elif path.startswith('/api/'):
                    if not isinstance(json.loads(body),dict): failures.append({'path':path,'reason':'invalid_api'})
                elif path.endswith('.csv'):
                    if not body.startswith(('Type,','Date,Movement,')): failures.append({'path':path,'reason':'invalid_csv'})
                elif '<!doctype html>' not in body.lower(): failures.append({'path':path,'reason':'invalid_html'})
            except (OSError,ValueError) as error: failures.append({'path':path,'reason':type(error).__name__})
        return samples,failures
    start=time.perf_counter()
    with ThreadPoolExecutor(max_workers=args.users) as pool: results=list(pool.map(worker,range(args.users)))
    elapsed=time.perf_counter()-start
    samples=[v for values,_ in results for v in values]; failures=[v for _,errors in results for v in errors]
    expected=args.users*args.requests_per_user
    p95=percentile(samples,.95) if samples else None
    passed=len(samples)==expected and not failures and p95<=args.p95_budget_ms
    report={'project':args.project,'url':args.url,'users':args.users,'profile':args.profile,'role':args.role,'paced_duration_seconds':args.duration_seconds,'expected_requests':expected,'completed_requests':len(samples),'elapsed_seconds':round(elapsed,3),'requests_per_second':round(len(samples)/elapsed,2),'p50_ms':round(statistics.median(samples),2) if samples else None,'p95_ms':round(p95,2) if samples else None,'max_ms':round(max(samples),2) if samples else None,'p95_budget_ms':args.p95_budget_ms,'failures':failures,'passed':passed,'scope':'authenticated reads; independent sessions; login time excluded from latency but included in elapsed; no stock mutations'}
    output=Path(args.output); output.parent.mkdir(parents=True,exist_ok=True); output.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
    return 0 if passed else 1

if __name__=='__main__': raise SystemExit(main())
