#!/usr/bin/env python3
"""D-19 smoke on the user's 8080 stack: pages render and one small price list → quotation → SO → surat jalan → invoice
flow on a dedicated smoke customer. Read-only SQL through the app container; writes only through the HTTP UI."""
import http.cookiejar, html.parser, json, re, subprocess, sys, urllib.error, urllib.parse, urllib.request
URL='http://localhost:8080'; ROOT='/Users/karyawan/Documents/Neuron/Tugas Akhir /Inventory-Operations'
results=[]
def check(name, ok, detail=None):
    results.append((name, bool(ok))); print(('PASS ' if ok else 'FAIL ')+name+('' if ok or detail is None else ' :: '+str(detail)[:300]), flush=True)
def db(sql, params=None):
    assert sql.lstrip().upper().startswith('SELECT ') and ';' not in sql
    php='$config=require "config/bootstrap.php"; $pdo=(new App\\Support\\DatabaseFactory($config))->create(); $in=json_decode(stream_get_contents(STDIN),true); $q=$pdo->prepare($in["sql"]); $q->execute($in["params"]); echo json_encode($q->fetchAll(PDO::FETCH_ASSOC));'
    out=subprocess.run(['docker','compose','exec','-T','app','php','-r',php],cwd=ROOT,input=json.dumps({'sql':sql,'params':params or []}),text=True,capture_output=True,check=True).stdout
    return json.loads(out)
def scalar(sql, params=None): return next(iter(db(sql, params)[0].values()))
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a): return None
class Client:
    def __init__(self):
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect()); self.csrf=''
    def req(self, path, data=None, fetch=False):
        if data is not None: data={'csrf_token': self.csrf, **data}
        r=urllib.request.Request(URL+path, urllib.parse.urlencode(data).encode() if data is not None else None, {'X-Requested-With':'fetch'} if fetch else {})
        try: resp=self.opener.open(r, timeout=30)
        except urllib.error.HTTPError as e: resp=e
        body=resp.read().decode(); m=re.search(r'name="csrf_token" value="([^"]+)"', body)
        if m: self.csrf=m.group(1)
        return resp.code, body, resp.headers
    def login(self, email):
        self.req('/login'); code,_,_=self.req('/login', {'email': email, 'password': 'password'}); check('login '+email, code==302, code)
        self.req('/dashboard')  # the session's CSRF token after login
admin, sales, warehouse = Client(), Client(), Client()
for c, e in [(admin,'admin@example.test'), (sales,'sales1@example.test'), (warehouse,'warehouse1@example.test')]: c.login(e)
for path in ['/price-lists','/quotations','/quotations/create','/delivery-notes','/invoices','/reports/receivables','/reports/sales-by-customer','/sales-orders/create']:
    code, body, _ = admin.req(path); check('Admin page '+path+' renders', code==200 and 'Fatal error' not in body and 'Warning:' not in body, code)
code, body, _ = admin.req('/dashboard'); check('Admin dashboard has the receivables card', code==200 and 'data-receivables-card' in body)
code, body, _ = admin.req('/sales-orders/create'); check('SO form resolves list prices live', 'data-price-resolution="/api/price-resolution"' in body)
first_dn = int(scalar('SELECT MIN(id) FROM delivery_notes'))
code, body, _ = warehouse.req('/delivery-notes/show?id='+str(first_dn)); check('Migrated surat jalan prints (origin Migration)', code==200 and 'SURAT JALAN' in body and 'before delivery notes existed' in body)
check('Role checks: Sales /price-lists 403, Warehouse /invoices 403, Warehouse /quotations 403', [sales.req('/price-lists')[0], warehouse.req('/invoices')[0], warehouse.req('/quotations')[0]]==[403,403,403])
# Smoke flow on a dedicated customer so no existing customer's prices change.
fx = db("""SELECT ps.product_id product, u.unit_code unit, u.factor_to_base factor FROM product_stocks ps
    JOIN product_units u ON u.product_id=ps.product_id AND u.is_sales_default=1 JOIN products p ON p.id=ps.product_id AND p.is_active=1 AND p.tracks_expiry=0
    WHERE ps.warehouse_id=1 AND ps.quantity-ps.reserved_quantity >= 10*u.factor_to_base ORDER BY ps.product_id LIMIT 1""")[0]
name='Smoke D-19 Toko Uji 2026-10-12'
None if db('SELECT id FROM customers WHERE name=?', [name]) else admin.req('/customers', {'name': name, 'email': '', 'phone': '021-000', 'address': 'Jl. Smoke No. 1', 'npwp': '01.234.567.8-901.2345', 'tax_name': 'PT Smoke Uji', 'tax_address': 'Jl. Pajak Smoke'})
customer = int(scalar('SELECT id FROM customers WHERE name=?', [name])); check('Smoke customer with NPWP created', scalar('SELECT npwp FROM customers WHERE id=?', [customer])=='0123456789012345')
cost = db('SELECT p.purchase_price, u.factor_to_base f, u.purchase_price up, u.selling_price us, p.selling_price ps FROM products p JOIN product_units u ON u.product_id=p.id AND u.unit_code=? WHERE p.id=?', [fx['unit'], fx['product']])[0]
master = float(cost['us']) if cost['us'] is not None else float(cost['ps'])*int(cost['f'])
floor = float(cost['purchase_price'])*int(cost['f'])
tier = max(round(master*0.97), int(floor)+1)
None if db("SELECT id FROM price_lists WHERE name='Smoke D-19 2026-10-12'") else admin.req('/price-lists', {'name': 'Smoke D-19 2026-10-12', 'description': 'Smoke test list', 'is_active': '1', 'valid_from': '', 'valid_to': ''})
plid = int(scalar("SELECT id FROM price_lists WHERE name='Smoke D-19 2026-10-12'"))
None if db('SELECT id FROM price_list_items WHERE price_list_id=?', [plid]) else admin.req('/price-lists/items', {'id': plid, 'product_id': fx['product'], 'unit_code': fx['unit'], 'min_quantity': '2', 'unit_price': str(tier)})
admin.req('/price-lists/assign', {'id': plid, 'customer_id': customer})
code, body, _ = sales.req('/api/price-resolution?'+urllib.parse.urlencode({'customer_id': customer, 'product_id': fx['product'], 'unit_code': fx['unit'], 'quantity': '2'}), fetch=True)
answer = json.loads(body); check('Price API resolves the smoke tier', code==200 and answer['source']=='PRICE_LIST' and answer['price']=='%.2f' % tier, answer)
check('Quantity 1 stays on master', json.loads(sales.req('/api/price-resolution?'+urllib.parse.urlencode({'customer_id': customer, 'product_id': fx['product'], 'unit_code': fx['unit'], 'quantity': '1'}), fetch=True)[1])['source']=='MASTER')
code, _, headers = sales.req('/quotations', {'customer_id': customer, 'warehouse_id': '1', 'product_id': fx['product'], 'quantity': '2', 'unit_code': fx['unit'], 'notes': 'Smoke D-19'})
qid = int(re.search(r'id=(\d+)', headers.get('Location', '')).group(1)); check('Quotation created', code==302)
for step in ['send', 'accept']: sales.req('/quotations/'+step, {'id': qid})
code, _, headers = sales.req('/quotations/convert', {'id': qid}); so = int(re.search(r'id=(\d+)', headers.get('Location', '')).group(1)); check('Quotation converted to an SO draft', code==302 and int(scalar('SELECT quotation_id FROM sales_orders WHERE id=?', [so]))==qid)
code, body, _ = sales.req('/quotations/print?id='+str(qid)); check('Quotation prints', code==200 and 'PENAWARAN HARGA' in body)
sales.req('/sales-orders/submit', {'id': so}); code,_,_ = admin.req('/sales-orders/approve', {'id': so}); check('SO approved', code==302, code)
code, body, _ = warehouse.req('/sales-orders/show?id='+str(so)); key = re.search(r'name="operation_key" value="([0-9a-f]{32})"', body).group(1)
code,_,_ = warehouse.req('/sales-orders/issue', {'id': so, 'operation_key': key, 'vehicle': 'B 1 SMK', 'driver': 'Smoke Driver'}); check('Shipment issued', code==302, code)
dn = int(scalar('SELECT id FROM delivery_notes WHERE sales_order_id=?', [so]))
code, body, _ = warehouse.req('/delivery-notes/show?id='+str(dn)); check('New surat jalan with vehicle and driver', code==200 and 'B 1 SMK' in body and 'Smoke Driver' in body)
code,_,headers = admin.req('/invoices', {'delivery_note_id': dn}); inv = int(re.search(r'id=(\d+)', headers.get('Location', '')).group(1)); check('Invoice created', code==302)
row = db('SELECT invoice_number, subtotal, dpp_amount, vat_amount, total_amount, invoice_date FROM invoices WHERE id=?', [inv])[0]
cents = round(float(row['subtotal'])*100); dpp = (cents*11+600)//1200; vat = (dpp*12+50)//100
check('Invoice PPN recomputes (12 % of DPP 11/12, whole Rupiah)', [row['dpp_amount'], row['vat_amount'], row['total_amount']]==['%d.00'%dpp, '%d.00'%vat, '%.2f'%((cents+vat*100)/100)] and cents==2*tier*100, row)
code,body,_ = admin.req('/invoices/payments', {'id': inv, 'paid_on': row['invoice_date'], 'amount': '%.2f' % (float(row['total_amount'])+0.01), 'method': 'Transfer'}); check('Overpayment refused', code==422 and 'Overpayment refused' in body)
code,_,_ = admin.req('/invoices/payments', {'id': inv, 'paid_on': row['invoice_date'], 'amount': row['total_amount'], 'method': 'Transfer', 'reference': 'SMOKE'}); check('Invoice paid in full', code==302 and scalar('SELECT paid_amount FROM invoices WHERE id=?', [inv])==row['total_amount'])
code, body, _ = admin.req('/invoices/print?id='+str(inv)); check('Invoice prints with NPWP', code==200 and 'NPWP 0123456789012345' in body)
php='$config=require "config/bootstrap.php"; $pdo=(new App\\Support\\DatabaseFactory($config))->create(); echo json_encode((new App\\Repository\\MySql\\SalesDocumentChecks($pdo))->report()+(new App\\Repository\\MySql\\BatchLotChecks($pdo))->report()+(new App\\Repository\\MySql\\PartialFulfilmentChecks($pdo))->report());'
checks = json.loads(subprocess.run(['docker','compose','exec','-T','app','php','-r',php], cwd=ROOT, text=True, capture_output=True, check=True).stdout)
check('All D-19, lot and fulfilment invariants are 0 after the smoke', all(v==0 for v in checks.values()), checks)
created = db('SELECT (SELECT quotation_number FROM quotations WHERE id=?) quotation, (SELECT order_number FROM sales_orders WHERE id=?) sales_order, (SELECT delivery_number FROM delivery_notes WHERE id=?) delivery_note, (SELECT invoice_number FROM invoices WHERE id=?) invoice', [qid, so, dn, inv])[0]
print('CREATED', json.dumps({'customer': {'id': customer, 'name': name}, 'price_list': {'id': plid, 'name': 'Smoke D-19 2026-10-12', 'tier': {'product_id': fx['product'], 'unit': fx['unit'], 'min_quantity': 2, 'price': tier}}, **created, 'payment': row['total_amount']}))
print(json.dumps({'checks': len(results), 'passed': sum(ok for _, ok in results), 'failed': sum(not ok for _, ok in results)}))
sys.exit(0 if all(ok for _, ok in results) else 1)
