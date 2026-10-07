#!/usr/bin/env python3
"""Role-scoped queue acceptance on a previously verified disposable business project."""
import argparse,json,http.cookiejar,re,urllib.request,urllib.parse,urllib.error
from pathlib import Path
parser=argparse.ArgumentParser();parser.add_argument('--evidence',required=True);args=parser.parse_args();root=Path(args.evidence);context=json.loads((root/'context.json').read_text())
assert context['project'].startswith('inventory-e2e-business-') and context['url']=='http://localhost:18086'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
checks=[]
for role,email,valid,invalid in [('Admin','admin@example.test','OperationApproval','SalesDraft'),('WarehouseStaff','warehouse1@example.test','Receipt','SOApproval'),('Sales','sales1@example.test','SalesDraft','Posting')]:
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
    def request(path,data=None):
        try:r=opener.open(context['url']+path,None if data is None else urllib.parse.urlencode(data).encode(),timeout=15)
        except urllib.error.HTTPError as e:r=e
        return r.code,r.read().decode()
    assert request('/work-queue')[0]!=200
    checks.append(role+' queue requires login')
    code,body=request('/login');token=re.search(r'name="csrf_token" value="([^\"]+)"',body).group(1)
    assert request('/login',{'email':email,'password':'password','csrf_token':token})[0]==302
    code,body=request('/work-queue');assert code==200 and '<h1>Work Queue</h1>' in body and not re.search(r'(?:Warning|Fatal error):',body)
    assert 'Age (days)' in body and 'pagination' in body
    checks.append(role+' queue renders badges aging and server pagination')
    assert request('/work-queue?type='+valid)[0]==200
    assert request('/work-queue?type='+invalid)[0]==422
    checks.append(role+' task filters enforce role on server')
    code,body=request('/work-queue?q=%25');assert code==200 and 'No tasks match these filters.' in body
    checks.append(role+' wildcard search is literal and empty state works')
    if role=='Sales':
        body=request('/work-queue')[1];assert '/purchase-orders/show?id=' not in body and '/inventory-operations/show?id=' not in body
        checks.append('Sales receives no warehouse/approval task links')
(root/'queue-http.json').write_text(json.dumps({'passed':True,'checks':len(checks),'results':checks},indent=2)+'\n');print(json.dumps({'passed':True,'checks':len(checks)}))
