#!/usr/bin/env python3
"""Read-only timeline acceptance on a guarded disposable fixture; login writes auth audit."""
import argparse,json,http.cookiejar,re,urllib.request,urllib.parse,urllib.error
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--evidence',required=True);args=p.parse_args();root=Path(args.evidence);context=json.loads((root/'context.json').read_text());fixtures=json.loads((root/'http.json').read_text())['fixtures']
assert context['project'].startswith('inventory-e2e-business-') and context['url']=='http://localhost:18086'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
checks=[]
for role,email in [('Admin','admin@example.test'),('WarehouseStaff','warehouse1@example.test'),('Sales','sales1@example.test')]:
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
    def request(path,data=None):
        try:r=opener.open(context['url']+path,None if data is None else urllib.parse.urlencode(data).encode(),timeout=15)
        except urllib.error.HTTPError as e:r=e
        return r.code,r.read().decode()
    assert request('/timeline?kind=SO&id='+str(fixtures['so_id']))[0]!=200;checks.append(role+' timeline requires authentication')
    _,body=request('/login');token=re.search(r'name="csrf_token" value="([^\"]+)"',body).group(1);assert request('/login',{'email':email,'password':'password','csrf_token':token})[0]==302
    for kind,id in [('PO',fixtures['po_id']),('SO',fixtures['so_id']),('Operation',fixtures['posted_operation'])]:
        code,body=request('/timeline?kind='+kind+'&id='+str(id));expected=403 if role=='Sales' and kind!='SO' else 200;assert code==expected,(role,kind,code)
        if code==200:assert 'Transaction Timeline' in body and 'Document created' in body and not re.search(r'(?:Warning|Fatal error):',body)
        checks.append(role+' '+kind+' visibility correct')
        if kind=='PO' and code==200:
            assert 'PO remainder closed' in body and 'Receipt ledger #' in body and 'SupplierReturn posted' in body
            related=re.search(r'href="/inventory-operations/show\?id=(\d+)"',body).group(1)
            c,b=request('/timeline?kind=Operation&id='+related);assert c==200 and 'Original PO #' in b and 'Stock operation posted' in b
            checks.append(role+' closure receipt return and original linkage visible')
        if kind=='SO' and code==200:
            assert 'SO approved' in body and 'Issue ledger #' in body
            if role=='Sales':assert 'View related return' not in body
            checks.append(role+' SO approval issue and related-operation scope correct')
    if role=='Sales':
        assert request('/timeline?kind=SO&id=1')[0]==403
        checks.append('Sales cannot read another actor SO timeline')
    assert request('/timeline?kind=Unknown&id=1')[0]==404;checks.append(role+' invalid kind rejected')
(root/'timeline-http.json').write_text(json.dumps({'passed':True,'checks':len(checks),'results':checks},indent=2)+'\n');print(json.dumps({'passed':True,'checks':len(checks)}))
