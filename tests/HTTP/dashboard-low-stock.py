#!/usr/bin/env python3
"""Read-only stock/dashboard acceptance on the local seeded app; login writes auth audit."""
import http.cookiejar,json,re,subprocess,urllib.error,urllib.parse,urllib.request
from pathlib import Path
D='/Applications/Docker.app/Contents/Resources/bin/docker'
base=[D,'compose','-p','inventory-operations','exec','-T','app','php']
php=r'''<?php
require '/var/www/html/vendor/autoload.php';
$config=require '/var/www/html/config/config.php';
$pdo=(new App\Support\DatabaseFactory($config))->create();
$stocks=$pdo->query('SELECT product_id,warehouse_id,quantity FROM product_stocks ORDER BY product_id,warehouse_id')->fetchAll(PDO::FETCH_NUM);
$count=$pdo->query('SELECT COUNT(*) FROM product_stocks ps INNER JOIN products p ON p.id=ps.product_id INNER JOIN warehouses w ON w.id=ps.warehouse_id WHERE p.is_active=1 AND w.is_active=1 AND ps.quantity<p.reorder_point')->fetchColumn();
echo json_encode(['stock_digest'=>hash('sha256',json_encode($stocks)),'ledger_rows'=>(int)$pdo->query('SELECT COUNT(*) FROM stock_ledger')->fetchColumn(),'low_stock_pairs'=>(int)$count]);
'''
def snapshot():
    return json.loads(subprocess.run(base,input=php,text=True,capture_output=True,check=True).stdout)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
checks=[]
before=snapshot()
for role,email in [('Admin','admin@example.test'),('WarehouseStaff','warehouse1@example.test'),('Sales','sales1@example.test')]:
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
    def request(path,data=None):
        try:r=opener.open('http://localhost:8080'+path,None if data is None else urllib.parse.urlencode(data).encode(),timeout=15)
        except urllib.error.HTTPError as e:r=e
        return r.code,r.read().decode()
    code,body=request('/login');assert code==200
    token=re.search(r'name="csrf_token" value="([^\"]+)"',body).group(1)
    assert request('/login',{'email':email,'password':'password','csrf_token':token})[0]==302
    checks.append(role+' login succeeds')
    code,body=request('/dashboard');assert code==200 and not re.search(r'(?:Fatal error|Warning):',body)
    checks.append(role+' dashboard renders without PHP errors')
    metric=re.search(r'Low Stock Rows</span>\s*<strong>(\d+)</strong>',body)
    if role=='Sales':
        assert metric is None and 'Inventory Value' not in body
        checks.append('Sales does not receive global inventory metrics')
    else:
        assert metric and int(metric.group(1))==before['low_stock_pairs']
        checks.append(role+' low-stock count matches active database warehouse pairs')
        if role=='WarehouseStaff':
            assert 'Inventory Value' not in body
            checks.append('Warehouse low-stock appears independently of Inventory Value')
after=snapshot();assert after==before
checks.append('Stock and ledger remain unchanged by acceptance')
output=Path('docs/testing/warehouse-dashboard-2026-10-07/http.json')
output.write_text(json.dumps({'passed':True,'checks':len(checks),'results':checks,'low_stock_pairs':before['low_stock_pairs'],'stock_mutations_performed':False},indent=2)+'\n')
print(json.dumps({'passed':True,'checks':len(checks),'low_stock_pairs':before['low_stock_pairs']}))
