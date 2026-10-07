#!/usr/bin/env python3
"""Draft revalidation endpoint: authentication, role matrix, input validation, no caching."""
import argparse,json,re,http.cookiejar,urllib.request,urllib.parse,urllib.error
parser=argparse.ArgumentParser();parser.add_argument('--url',default='http://localhost:18091');URL=parser.parse_args().url.rstrip('/')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a):return None
def session(email=None):
    o=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
    def req(path,data=None):
        try:r=o.open(URL+path,None if data is None else urllib.parse.urlencode(data).encode(),timeout=15)
        except urllib.error.HTTPError as e:r=e
        return r.code,r.read().decode(),dict(r.headers)
    if email:
        token=re.search(r'name="csrf_token" value="([^"]+)"',req('/login')[1]).group(1)
        assert req('/login',{'email':email,'password':'password','csrf_token':token})[0]==302
    return req
checks=[]
assert session()('/drafts/check?form=purchase-order')[0] in (302,401);checks.append('unauthenticated check refused')
matrix={'admin@example.test':{'purchase-order':200,'sales-order':200,'stock-proposal':200},'warehouse1@example.test':{'purchase-order':200,'sales-order':403,'stock-proposal':200},'sales1@example.test':{'purchase-order':403,'sales-order':200,'stock-proposal':403}}
for email,forms in matrix.items():
    req=session(email)
    for form,expected in forms.items():
        code,body,headers=req('/drafts/check?'+urllib.parse.urlencode({'form':form,'warehouse_id':'1','product_ids[]':'1'}))
        assert code==expected,(email,form,code)
        if code==200:
            data=json.loads(body);assert 'no-store' in headers.get('Cache-Control','') and data['warehouse']=={'id':1,'active':True} and data['items'][0]['product_id']==1 and isinstance(data['items'][0]['available'],int)
    checks.append(email.split('@')[0]+' role matrix matches create routes')
    for bad in ['form=users','form=purchase-order&warehouse_id=0','form=purchase-order&product_ids[]=x']:
        if email.startswith('sales'):bad=bad.replace('purchase-order','sales-order')
        assert req('/drafts/check?'+bad)[0]==422,(email,bad)
    checks.append(email.split('@')[0]+' malformed checks rejected with 422')
print(json.dumps({'passed':True,'checks':len(checks),'results':checks},indent=1))
