#!/usr/bin/env python3
"""Role-scoped outstanding/aging report acceptance against a disposable seeded stack."""
import argparse
import csv,io,json,re,http.cookiejar,urllib.request,urllib.parse,urllib.error
parser=argparse.ArgumentParser();parser.add_argument('--url',default='http://localhost:18091');URL=parser.parse_args().url.rstrip('/')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a):return None
checks=[]
def session():
    o=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
    def req(path,data=None):
        try:r=o.open(URL+path,None if data is None else urllib.parse.urlencode(data).encode(),timeout=15)
        except urllib.error.HTTPError as e:r=e
        return r.code,r.read().decode(),dict(r.headers)
    return req
anon=session()
assert anon('/reports?type=outstanding')[0] in (302,401,403);checks.append('report requires login')
assert anon('/reports/outstanding.csv')[0] in (302,401,403);checks.append('csv requires login')
seen={}
for role,email in [('Admin','admin@example.test'),('WarehouseStaff','warehouse1@example.test'),('Sales','sales1@example.test')]:
    req=session();_,body,_=req('/login');tok=re.search(r'name="csrf_token" value="([^"]+)"',body).group(1)
    assert req('/login',{'email':email,'password':'password','csrf_token':tok})[0]==302
    code,body,_=req('/reports?type=outstanding')
    assert code==200 and 'Outstanding orders by age' in body and 'not a due date' in body and not re.search(r'(Warning|Fatal error|Notice):',body),role
    assert 'Outstanding by Age' in body and 'Age Days' in body and 'href="/reports/outstanding.csv?' in body
    checks.append(role+' page renders aging metrics, charts, columns and export link')
    code,body,h=req('/reports/outstanding.csv');rows=list(csv.reader(io.StringIO(body)))
    assert code==200 and rows[0]==['Type','OrderNumber','Party','Status','Created','AgeDays','AgeBucket','DaysSinceApproval','OutstandingQty']
    kinds={r[0]+' '+r[3] for r in rows[1:]};seen[role]=(len(rows)-1,kinds)
    allowed={'Admin':{'PO Draft','PO Ordered','PO PartiallyReceived','SO Draft','SO PendingApproval','SO Approved','OP PendingApproval','OP Approved'},'WarehouseStaff':{'PO Ordered','PO PartiallyReceived','SO Approved','OP Approved'},'Sales':{'SO Draft','SO PendingApproval','SO Approved'}}[role]
    assert kinds<=allowed,(role,kinds)
    assert all(r[6] in ('0-2 days','3-7 days','8-30 days','31+ days') and int(r[5])>=0 for r in rows[1:])
    assert all((r[7]!='')==(r[3]=='Approved' and r[0] in ('SO','OP')) and (r[7]=='' or int(r[7])>=0) for r in rows[1:]);checks.append(role+' days since approval only on approved SO/OP')
    checks.append(role+' CSV limited to role scope with age buckets')
    assert req('/reports?type=outstanding&from=2026-02-30')[0]==422;checks.append(role+' invalid date rejected')
    assert req('/reports?type=outstanding&age=1-2+days')[0]==422 and req('/reports/outstanding.csv?age=1-2+days')[0]==422;checks.append(role+' unknown age bucket rejected on page and CSV')
    if role=='Sales':
        assert req('/reports?type=outstanding&document=PO')[0]==422 and req('/reports/outstanding.csv?document=OP')[0]==422;checks.append('Sales cannot widen document scope')
    else:
        code,body,_=req('/reports?type=outstanding&document=SO&age=0-2+days');assert code==200 and 'name="document"' in body and 'document=SO' in body and 'age=0-2+days' in body
        rows=list(csv.reader(io.StringIO(req('/reports/outstanding.csv?document=SO&age=0-2+days')[1])))[1:]
        assert rows and all(r[0]=='SO' and r[6]=='0-2 days' for r in rows);checks.append(role+' document/age filters apply to page links and CSV')
    code,body,_=req('/reports?type=outstanding&from=2099-01-01');assert code==200 and 'No open documents match this period.' in body;checks.append(role+' empty state')
assert seen['Sales'][0]<seen['Admin'][0] and seen['WarehouseStaff'][0]<seen['Admin'][0];checks.append('Sales/Warehouse datasets are narrower than Admin')
print(json.dumps({'passed':True,'checks':len(checks),'results':checks,'counts':{k:v[0] for k,v in seen.items()}},indent=1))
