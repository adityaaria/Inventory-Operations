import http.cookiejar, json, re, sys, urllib.request, urllib.parse, urllib.error
URL='http://localhost:8080'; results=[]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a): return None
def client(email):
    o=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect()); state={'csrf':''}
    def req(path,data=None):
        if data is not None: data={'csrf_token':state['csrf'],**data}
        try: r=o.open(URL+path,None if data is None else urllib.parse.urlencode(data).encode(),timeout=20)
        except urllib.error.HTTPError as e: r=e
        body=r.read().decode(); m=re.search(r'name="csrf_token" value="([^"]+)"',body)
        if m: state['csrf']=m.group(1)
        return r.code,body
    req('/login'); code,_=req('/login',{'email':email,'password':'password'}); assert code==302,(email,code)
    return req
def check(name,cond,detail=''):
    results.append({'name':name,'passed':bool(cond),'detail':detail}); print(('PASS ' if cond else 'FAIL ')+name)
admin=client('admin@example.test'); sales=client('sales1@example.test'); wh=client('warehouse1@example.test')
code,api=admin('/api/products/BIS-0001/availability'); api=json.loads(api)
check('API: base fields in pcs plus base_unit and units', code==200 and api['base_unit']=='pcs' and [u['unit'] for u in api['units']]==['karton','pcs'] and api['units'][0]['factor_to_base']==24, {k:api[k] for k in ['total_quantity','total_reserved','base_unit','units']})
pid=re.search(r'href="/products/show\?id=(\d+)">[^<]*</a>',admin('/products?q=BIS-0001')[1]).group(1)
code,page=admin('/products/show?id='+pid)
check('Product detail: units table, breakdown and Admin forms', code==200 and 'Units of Measure' in page and '1 karton = 24 pcs' in page and re.search(r'<dt>Total Stock</dt><dd>[\d.]+ karton( \+ [\d.]+ pcs)? \([\d.]+ pcs\)</dd>',page) is not None and 'action="/products/units"' in page, re.search(r'<dt>Total Stock</dt><dd>[^<]*</dd>',page).group(0))
code,spage=sales('/products/show?id='+pid); check('Sales sees units but no unit forms', code==200 and '1 karton = 24 pcs' in spage and 'action="/products/units' not in spage)
code,_=sales('/products/units',{'id':pid,'unit_code':'box','factor_to_base':'12'}); check('Sales POST /products/units is 403', code==403, code)
code,_=wh('/products/units',{'id':pid,'unit_code':'box','factor_to_base':'12'}); check('Warehouse POST /products/units is 403', code==403, code)
code,body=admin('/products/units',{'id':pid,'unit_code':'box','factor_to_base':'1'}); check('Admin invalid factor is 422 (nothing saved)', code==422 and 'from 2 to' in body, code)
code,body=admin('/products/units',{'id':pid,'unit_code':'karton','factor_to_base':'12'}); check('Admin duplicate unit code is 422', code==422 and 'already exists' in body, code)
code,form=wh('/purchase-orders/create'); check('PO form: unit select, karton purchase default, per-unit price label', code==200 and 'name="unit_code"' in form and 'data-default-unit="karton"' in form and '(Rp per unit)' in form)
code,form=sales('/sales-orders/create'); check('SO form: unit select with per-unit selling prices', code==200 and 'name="unit_code"' in form and '&quot;selling_price&quot;' in form)
so='1'  # a converted seed order (karton lines)
code,sod=admin('/sales-orders/show?id='+so); check('SO detail: line unit with base equivalent, per-unit price and line total', code==200 and re.search(r'<td data-line-quantity>[\d.]+ (karton|bal) \([\d.]+ (pcs|pak)\)</td>',sod) is not None and 'data-line-total' in sod and '<small>per ' in sod, so)
po='1'
code,pod=admin('/purchase-orders/show?id='+po); check('PO detail: line unit, base equivalent and line total', code==200 and re.search(r'<td data-line-quantity>[\d.]+ (karton|bal|sak|galon|jeriken)',pod) is not None and 'data-line-total' in pod, po)
code,rec=wh('/replenishment'); check('Replenishment page renders with purchase-unit rounding text', code==200 and 'rounded up to whole purchase units' in rec)
code,dash=admin('/dashboard'); check('Dashboard renders', code==200 and 'Fatal error' not in dash and 'Warning:' not in dash)
for path in ['/products','/inventory-operations/create','/inventory-operations/in-transit','/reports','/work-queue']:
    code,b=admin(path); check('Admin '+path+' renders', code==200 and 'Fatal error' not in b and 'Warning:' not in b)
print(json.dumps({'checks':len(results),'passed':sum(r['passed'] for r in results),'failed':sum(not r['passed'] for r in results),'results':results},default=str))
sys.exit(0 if all(r['passed'] for r in results) else 1)
