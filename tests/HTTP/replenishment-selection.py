#!/usr/bin/env python3
"""Multi-product replenishment selection -> reviewed multi-item PO draft, against a disposable seeded stack."""
import argparse,html,json,re,time,http.cookiejar,urllib.request,urllib.parse,urllib.error
parser=argparse.ArgumentParser();parser.add_argument('--url',default='http://localhost:18091');URL=parser.parse_args().url.rstrip('/')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a):return None
checks=[]
def login(email):
    o=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
    def req(path,data=None):
        try:r=o.open(URL+path,None if data is None else urllib.parse.urlencode(data,doseq=True).encode(),timeout=15)
        except urllib.error.HTTPError as e:r=e
        return r.code,r.read().decode(),dict(r.headers)
    body=req('/login')[1];token=re.search(r'name="csrf_token" value="([^"]+)"',body).group(1)
    assert req('/login',{'email':email,'password':'password','csrf_token':token})[0]==302
    return req
sales=login('sales1@example.test')
assert sales('/replenishment')[0]==403 and sales('/purchase-orders/create?warehouse_id=1&pick[]=1:1')[0]==403;checks.append('Sales cannot open replenishment or prefilled PO')
for email in ['warehouse1@example.test','admin@example.test']:
    req=login(email);role=email.split('@')[0]
    code,body,_=req('/replenishment');assert code==200 and 'name="pick[]"' not in body and 'Choose one warehouse' in body;checks.append(role+' selection hidden until one warehouse is chosen')
    wid=picks=None
    for option in re.findall(r'<option value="(\d+)"',body):
        code,page,_=req('/replenishment?warehouse_id='+option);found=re.findall(r'name="pick\[\]" value="(\d+:\d+)"',page)
        assert code==200
        if len(found)>=2 and wid is None:wid,picks,selection=option,found,page
    assert wid and 'Review PO for selected' in selection and 'name="warehouse_id" value="'+wid+'"' in selection;checks.append(role+' warehouse filter enables multi-select')
    assert req('/replenishment?warehouse_id=abc')[0]==422;checks.append(role+' invalid warehouse filter rejected')
    query=urllib.parse.urlencode({'warehouse_id':wid,'pick[]':picks[:2]},doseq=True)
    code,body,_=req('/purchase-orders/create?'+query);assert code==200 and 'name="items[1][product_id]"' in body and 'data-order-form' in body
    first,second=[p.split(':') for p in picks[:2]]
    assert re.search(r'name="items\[1\]\[quantity\]"[^>]*value="'+second[1]+'"',body);checks.append(role+' selection prefills reviewable lines')
    before=req('/purchase-orders')[1].count('PO-MULTI-')
    assert req('/purchase-orders/create?warehouse_id='+wid+'&pick[]=1:1&pick[]=1:2')[0]==422;checks.append(role+' duplicate selection rejected')
    token=re.search(r'name="csrf_token" value="([^"]+)"',body).group(1);number='PO-MULTI-'+role.upper()+'-'+str(int(time.time()*1000))
    code,_,headers=req('/purchase-orders',{'csrf_token':token,'order_number':number,'supplier_id':'1','warehouse_id':wid,'product_id':first[0],'quantity':first[1],'purchase_price':'1000','items[1][product_id]':second[0],'items[1][quantity]':second[1],'items[1][purchase_price]':'250'})
    assert code==302,code
    code,body,_=req('/purchase-orders?q='+number);ids=re.findall(r'/purchase-orders/show\?id=(\d+)',body);assert ids
    detail=req('/purchase-orders/show?id='+ids[0])[1];assert number in detail
    checks.append(role+' one draft PO saved with reviewed items')
    code,body,_=req('/purchase-orders',{'csrf_token':token,'order_number':number+'-DUP','supplier_id':'1','warehouse_id':'1','product_id':first[0],'quantity':'1','purchase_price':'1','items[1][product_id]':first[0],'items[1][quantity]':'1','items[1][purchase_price]':'1'})
    assert code==422 and 'items[1][product_id]' in body;checks.append(role+' duplicate items rejected with lines retained')
print(json.dumps({'passed':True,'checks':len(checks),'results':checks},indent=1))
