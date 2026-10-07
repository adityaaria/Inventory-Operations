#!/usr/bin/env python3
"""Real HTTP workflows + read-only SQL assertions against an explicitly disposable Docker project."""
import argparse, csv, http.cookiejar, html.parser, io, json, os, re, subprocess, time, urllib.error, urllib.parse, urllib.request

parser=argparse.ArgumentParser()
parser.add_argument('--project',required=True)
parser.add_argument('--url',default='http://localhost:18085')
parser.add_argument('--output',required=True)
args=parser.parse_args()
if not args.project.startswith('inventory-e2e-') or urllib.parse.urlparse(args.url).hostname not in ['localhost','127.0.0.1']:
    raise SystemExit('Refusing writes outside a disposable inventory-e2e-* project on localhost.')
docker='/Applications/Docker.app/Contents/Resources/bin/docker' if os.path.exists('/Applications/Docker.app/Contents/Resources/bin/docker') else 'docker'
base=[docker,'compose','-p',args.project]
container=subprocess.check_output(base+['ps','-q','app'],text=True).strip()
info=json.loads(subprocess.check_output([docker,'inspect',container],text=True))[0]
assert info['Config']['Labels']['com.docker.compose.project']==args.project
port=urllib.parse.urlparse(args.url).port
assert any(int(binding['HostPort'])==port for binding in info['NetworkSettings']['Ports'].get('8080/tcp',[])), 'URL must target the disposable app'
checks=[]; prefix='E2E'+str(time.time_ns())

def check(name,condition,detail=None):
    checks.append({'name':name,'passed':bool(condition), **({'detail':detail} if detail is not None else {})})
    print(('PASS ' if condition else 'FAIL ')+name,flush=True)

def db(sql,params=None):
    assert sql.lstrip().upper().startswith('SELECT ') and ';' not in sql
    php='$config=require "config/bootstrap.php"; $pdo=(new App\\Support\\DatabaseFactory($config))->create(); $in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); $q=$pdo->prepare($in["sql"]); $q->execute($in["params"]); echo json_encode($q->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);'
    result=subprocess.run(base+['exec','-T','app','php','-r',php],input=json.dumps({'sql':sql,'params':params or []}),text=True,capture_output=True,check=True)
    return json.loads(result.stdout)

def scalar(sql,params=None): return next(iter(db(sql,params)[0].values()))
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*unused):return None
class FormParser(html.parser.HTMLParser):
    def __init__(self,body):
        super().__init__(); self.values={};self.options={};self.select=None;self.textarea=None;self.feed(body)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='input' and 'name' in a:self.values[a['name']]=a.get('value','')
        elif tag=='select': self.select=a.get('name'); self.options[self.select]=[]
        elif tag=='option' and self.select:
            self.options[self.select].append(a.get('value',''))
            if 'selected' in a or self.select not in self.values:self.values[self.select]=a.get('value','')
        elif tag=='textarea':self.textarea=a.get('name');self.values[self.textarea]=''
    def handle_endtag(self,tag):
        if tag=='select':self.select=None
        if tag=='textarea':self.textarea=None
    def handle_data(self,data):
        if self.textarea:self.values[self.textarea]+=data
class Client:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar),NoRedirect());self.csrf=''
    def request(self,path,data=None,fetch=False):
        headers={'X-Requested-With':'fetch'} if fetch else {}
        if data is not None:data={'csrf_token':self.csrf,**data}
        request=urllib.request.Request(args.url+path,urllib.parse.urlencode(data).encode() if data is not None else None,headers)
        try:r=self.opener.open(request,timeout=20)
        except urllib.error.HTTPError as error:r=error
        body=r.read().decode();parsed=FormParser(body)
        if parsed.values.get('csrf_token'):self.csrf=parsed.values['csrf_token']
        return r.code,body,r.headers
    def login(self,email,password='password'):
        self.request('/login');status,body,_=self.request('/login',{'email':email,'password':password})
        check(email+' login',status==302,status)
        status,body,_=self.request('/dashboard');check(email+' dashboard',status==200,status)
        return body
    def post(self,path,data,status=302,label=None):
        actual,body,_=self.request(path,data)
        check(label or path,actual==status,actual)
        return body

try:
    admin=Client();sales=Client();warehouse=Client();other=Client()
    for client,email in [(admin,'admin@example.test'),(sales,'sales1@example.test'),(warehouse,'warehouse1@example.test'),(other,'sales2@example.test')]:client.login(email)
    for client,role in [(sales,'Sales'),(warehouse,'Warehouse')]:
        for path in ['/users','/users/create','/categories/create','/products/create','/warehouses/create','/suppliers/create','/customers/create']:
            check(role+' server-denied '+path,client.request(path)[0]==403)
        client.post('/users',{'name':'Denied','email':prefix+'denied@test','role':'Admin','password':'password'},403,role+' denied forged user create')
    user_data={'name':prefix+' user','email':prefix.lower()+'@example.test','password':'password','role':'Sales'}
    admin.post('/users',user_data)
    uid=int(scalar('SELECT id FROM users WHERE email=?',[user_data['email']]))
    admin.post('/users',{**user_data,'name':prefix+' retained','role':'WarehouseStaff'},422,'Duplicate email validation')
    _,body,_=admin.request('/users/create')
    status,body,_=admin.request('/users',{**user_data,'name':prefix+' retained','role':'WarehouseStaff'})
    values=FormParser(body).values
    check('User validation retains safe name/email/role but never password',status==422 and values.get('name')==prefix+' retained' and values.get('email')==user_data['email'] and values.get('role')=='WarehouseStaff' and not values.get('password'))
    fresh=Client();fresh.login(user_data['email'])
    admin.post('/users/update',{'id':uid,'name':prefix+' changed','email':user_data['email'],'role':'WarehouseStaff'})
    status,body,_=fresh.request('/dashboard');check('Existing session uses changed role',status==200 and 'Role: WarehouseStaff' in body)
    admin.post('/users/deactivate',{'id':uid});check('Existing deactivated session revoked',fresh.request('/dashboard')[0]==302)
    inactive=Client();inactive.request('/login');status,body,_=inactive.request('/login',{'email':user_data['email'],'password':'password'});check('Inactive user cannot login',status==200 and 'Invalid email' in body)
    admin.post('/users/activate',{'id':uid});fresh.login(user_data['email'])

    refs={}
    for module,fields in [('categories',{'name':prefix+' category','description':'E2E category description'}),('warehouses',{'name':prefix+' warehouse','location':'E2E zone'}),('suppliers',{'name':prefix+' supplier','email':'supplier@example.test','phone':'02112345','address':'E2E supplier address'}),('customers',{'name':prefix+' customer','email':'customer@example.test','phone':'02154321','address':'E2E customer address'})]:
        admin.post('/'+module,fields)
        ident=int(scalar('SELECT id FROM '+module+' WHERE name=?',[fields['name']]))
        refs[module]=ident
        if module=='warehouses':check('New warehouse has zero balances for every existing product',int(scalar('SELECT COUNT(*) FROM product_stocks WHERE warehouse_id=? AND quantity=0',[ident]))==int(scalar('SELECT COUNT(*) FROM products')))
        admin.post('/'+module+'/update',{**fields,'id':ident,'name':fields['name']+' edited'})
        check(module+' edit persisted',scalar('SELECT name FROM '+module+' WHERE id=?',[ident])==fields['name']+' edited')
        retained_field=next(key for key in ['description','location','address'] if key in fields)
        attempted={**fields,'name':'',retained_field:prefix+' retained detail'}
        status,body,_=admin.request('/'+module,attempted)
        check(module+' invalid create retains safe detail',status==422 and FormParser(body).values.get(retained_field)==prefix+' retained detail')
        status,body,_=admin.request('/'+module+'/update',{**attempted,'id':ident})
        check(module+' invalid edit retains attempted detail',status==422 and FormParser(body).values.get(retained_field)==prefix+' retained detail')
        check(module+' validation leaves record unchanged',scalar('SELECT '+retained_field+' FROM '+module+' WHERE id=?',[ident])==fields[retained_field])

        admin.post('/'+module+'/deactivate',{'id':ident});check(module+' deactivation persisted',int(scalar('SELECT is_active FROM '+module+' WHERE id=?',[ident]))==0)
        admin.post('/'+module+'/activate',{'id':ident})
    product={'sku':prefix+'-SKU','name':prefix+' product','unit':'pcs','purchase_price':'12.50','selling_price':'20.00','reorder_point':'5','category_id':refs['categories']}
    admin.post('/products',product);pid=int(scalar('SELECT id FROM products WHERE sku=?',[product['sku']]))
    check('New product has zero balances for every warehouse',int(scalar('SELECT COUNT(*) FROM product_stocks WHERE product_id=? AND quantity=0',[pid]))==int(scalar('SELECT COUNT(*) FROM warehouses')))
    check('New product is present in low-stock data',int(scalar('SELECT COUNT(*) FROM product_stocks ps INNER JOIN products p ON p.id=ps.product_id WHERE ps.product_id=? AND ps.quantity < p.reorder_point',[pid]))>0)
    admin.post('/products',product,422,'Duplicate SKU validation')
    admin.post('/products/update',{**product,'id':pid,'name':product['name']+' edited'})
    admin.post('/products/deactivate',{'id':pid})
    for client,module in [(warehouse,'purchase-orders'),(sales,'sales-orders')]:
        _,body,_=client.request('/'+module+'/create');parsed=FormParser(body)
        check(module+' picker excludes inactive product',str(pid) not in parsed.options.get('product_id',[]))
    admin.post('/products/activate',{'id':pid})
    for client,module in [(warehouse,'purchase-orders'),(sales,'sales-orders')]:
        _,body,_=client.request('/'+module+'/create');parsed=FormParser(body)
        expected=int(scalar('SELECT COUNT(*) FROM products WHERE is_active=1'))
        check(module+' picker exposes ALL active products',len(parsed.options.get('product_id',[]))==expected,{'expected':expected,'actual':len(parsed.options.get('product_id',[]))})
        check(module+' newly created product selectable',str(pid) in parsed.options.get('product_id',[]))
    status,body,_=admin.request('/products',{**product,'name':prefix+' retained','reorder_point':'-1'})
    check('Invalid product retains safe input',status==422 and FormParser(body).values.get('name')==prefix+' retained')

    po_data={'order_number':prefix+'-PO','supplier_id':refs['suppliers'],'warehouse_id':refs['warehouses'],'product_id':pid,'quantity':'10','purchase_price':'12.50'}
    warehouse.post('/purchase-orders',po_data)
    po=int(scalar('SELECT id FROM purchase_orders WHERE order_number=?',[po_data['order_number']]))
    item=int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[po]))
    warehouse.post('/purchase-orders/receive',{'id':po,'item_id':item,'quantity':1},422,'Draft PO receipt rejected')
    sales.post('/purchase-orders/order',{'id':po},403,'Sales cannot order PO')
    admin.post('/purchase-orders/order',{'id':po})
    admin.post('/purchase-orders/order',{'id':po},422,'Repeated mark-ordered is validation error')
    warehouse.post('/purchase-orders/receive',{'id':po,'item_id':item,'quantity':4})
    check('Partial receipt status',scalar('SELECT status FROM purchase_orders WHERE id=?',[po])=='PartiallyReceived')
    check('Partial receipt balance',int(scalar('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[pid,refs['warehouses']]))==4)
    warehouse.post('/purchase-orders/receive',{'id':po,'item_id':item,'quantity':7},422,'Over-receipt rejected')
    check('Rejected over-receipt rolls back',int(scalar('SELECT received_quantity FROM purchase_order_items WHERE id=?',[item]))==4)
    warehouse.post('/purchase-orders/receive',{'id':po,'item_id':item,'quantity':6})
    check('Full receipt status',scalar('SELECT status FROM purchase_orders WHERE id=?',[po])=='Received')
    warehouse.post('/purchase-orders/receive',{'id':po,'item_id':item,'quantity':1},422,'Duplicate completed receipt rejected')
    admin.post('/purchase-orders/cancel',{'id':po},422,'Received PO cannot cancel')
    check('Receipt ledger totals match quantity',int(scalar("SELECT COALESCE(SUM(quantity),0) FROM stock_ledger WHERE reference_type='PO' AND reference_id=?",[po]))==10)
    check('Exactly two atomic receipt audits',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='purchase-orders.receive' AND entity_id=? AND status='success'",[po]))==2)
    status,body,_=warehouse.request('/purchase-orders',{**po_data,'order_number':prefix+'-KEEP-PO','quantity':'0'})
    check('PO validation retains header/item input',status==422 and FormParser(body).values.get('order_number')==prefix+'-KEEP-PO' and FormParser(body).values.get('quantity')=='0' and FormParser(body).values.get('product_id')==str(pid))
    admin.post('/purchase-orders',{**po_data,'order_number':prefix+'-CANCEL-PO'})
    cancelled_po=int(scalar('SELECT id FROM purchase_orders WHERE order_number=?',[prefix+'-CANCEL-PO']))
    admin.post('/purchase-orders/cancel',{'id':cancelled_po})
    admin.post('/purchase-orders/order',{'id[]':po},422,'Malformed PO transition ID rejected')
    admin.post('/sales-orders/approve',{'id[]':po},422,'Malformed SO transition ID rejected')
    check('Malformed transition audit has no invented entity',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action IN ('purchase-orders.order','sales-orders.approve') AND status='failure' AND entity_id IS NULL"))>=2)
    check('Draft PO cancellation',scalar('SELECT status FROM purchase_orders WHERE id=?',[cancelled_po])=='Cancelled')

    so_data={'order_number':prefix+'-SO','customer_id':refs['customers'],'warehouse_id':refs['warehouses'],'product_id':pid,'quantity':'3','selling_price':'20.00'}
    sales.post('/sales-orders',so_data);so=int(scalar('SELECT id FROM sales_orders WHERE order_number=?',[so_data['order_number']]))
    check('Sales owner sees order',sales.request('/sales-orders/show?id='+str(so))[0]==200)
    check('Other Sales cannot view order',other.request('/sales-orders/show?id='+str(so))[0]==403)
    warehouse.post('/sales-orders/issue',{'id':so},422,'Draft SO cannot issue')
    other.post('/sales-orders/submit',{'id':so},403,'Other Sales cannot submit order')
    sales.post('/sales-orders/submit',{'id':so});sales.post('/sales-orders/approve',{'id':so},403,'Sales cannot approve own SO')
    admin.post('/sales-orders/approve',{'id':so});admin.post('/sales-orders/approve',{'id':so},422,'Repeated approval is validation error')
    check('Approver persisted',int(scalar('SELECT approved_by FROM sales_orders WHERE id=?',[so]))==1)
    sales.post('/sales-orders/issue',{'id':so},403,'Sales cannot issue')
    warehouse.post('/sales-orders/issue',{'id':so})
    warehouse.post('/sales-orders/issue',{'id':so},422,'Duplicate goods issue rejected')
    admin.post('/sales-orders/cancel',{'id':so},422,'Fulfilled SO cannot cancel')
    check('SO Fulfilled with exact remaining stock',scalar('SELECT status FROM sales_orders WHERE id=?',[so])=='Fulfilled' and int(scalar('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[pid,refs['warehouses']]))==7)
    check('Exactly one Issue ledger',int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[so]))==1)
    check('Exactly one atomic issue audit',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='sales-orders.issue' AND entity_id=? AND status='success'",[so]))==1)
    for quantity,suffix in [('8','INSUFFICIENT'),('2','CANCEL')]:
        number=prefix+'-'+suffix;sales.post('/sales-orders',{**so_data,'order_number':number,'quantity':quantity});ident=int(scalar('SELECT id FROM sales_orders WHERE order_number=?',[number]));sales.post('/sales-orders/submit',{'id':ident})
        if suffix=='INSUFFICIENT':
            admin.post('/sales-orders/approve',{'id':ident});warehouse.post('/sales-orders/issue',{'id':ident},422,'Insufficient stock rejected')
            check('Insufficient issue preserves order/balance/ledger',scalar('SELECT status FROM sales_orders WHERE id=?',[ident])=='Approved' and int(scalar('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[pid,refs['warehouses']]))==7 and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[ident]))==0)
        else:
            admin.post('/sales-orders/cancel',{'id':ident});check('Pending SO cancellation',scalar('SELECT status FROM sales_orders WHERE id=?',[ident])=='Cancelled')
    status,body,_=sales.request('/sales-orders',{**so_data,'order_number':prefix+'-KEEP-SO','quantity':'-1'})
    check('SO validation retains header/item input',status==422 and FormParser(body).values.get('order_number')==prefix+'-KEEP-SO' and FormParser(body).values.get('quantity')=='-1')

    for client,role in [(admin,'Admin'),(sales,'Sales'),(warehouse,'Warehouse')]:
        for path in ['/dashboard','/products','/products/show?id='+str(pid),'/purchase-orders','/sales-orders','/reports']:
            check(role+' read '+path,client.request(path)[0]==200)
        status,body,headers=client.request('/api/products/'+product['sku']+'/availability')
        check(role+' API matches stock',status==200 and 'application/json' in headers.get('Content-Type','') and json.loads(body)['total_quantity']==7)
        check(role+' unknown SKU API 404',client.request('/api/products/unknown-e2e/availability')[0]==404)
    for module in ['products','purchase-orders','sales-orders']:
        status,body,_=admin.request('/'+module+'?q='+urllib.parse.quote(prefix)+'&page=1&sort=order_date&direction=asc')
        check(module+' filtered list has test data',status==200 and prefix in body)
        status,body,_=admin.request('/'+module+'?q=NO_MATCH_'+prefix);check(module+' empty state',status==200 and ('No ' in body or 'empty-state' in body))
    status,body,headers=admin.request('/reports/orders.csv');rows=list(csv.reader(io.StringIO(body)))
    count=int(scalar('SELECT (SELECT COUNT(*) FROM purchase_orders)+(SELECT COUNT(*) FROM sales_orders)'))
    check('Admin CSV exports full DB dataset',status==200 and 'text/csv' in headers.get('Content-Type','') and len(rows)==count+1)
    status,body,_=sales.request('/reports/orders.csv');rows=list(csv.reader(io.StringIO(body)))
    mine={r['order_number'] for r in db('SELECT order_number FROM sales_orders WHERE created_by=2')}
    check('Sales report exports only own SO',status==200 and {r[1] for r in rows[1:]}==mine)
    check('Sales stock CSV forbidden',sales.request('/reports/stock-ledger.csv')[0]==403)
    check('Sales stock preview forbidden',sales.request('/reports?type=stock-ledger')[0]==403)
    today=str(scalar('SELECT CURRENT_DATE()'));status,body,_=warehouse.request('/reports/stock-ledger.csv?from='+today+'&to='+today)
    check('Warehouse date-filtered ledger contains movements',status==200 and product['sku'] in body)
    status,body,_=admin.request('/reports/orders.csv?from=2099-01-01&to=2099-01-02');check('Empty CSV keeps header',status==200 and len(list(csv.reader(io.StringIO(body))))==1)
    check('Malformed report date safe 422',admin.request('/reports?from[]=bad')[0]==422)
    for module in ['users','categories','warehouses','suppliers','customers','products']:
        status,body,_=admin.request('/'+module+'?page=2')
        check(module+' pagination page 2 remains usable',status==200 and 'pagination' in body)
    admin.post('/products/import',{'csv_data':'sku,name,unit,purchase_price,selling_price,reorder_point,category_id\n'+prefix+'-IMPORTED,Imported product,pcs,10,20,1,'+str(refs['categories'])},302,'Product import initializes stock in enclosing transaction')
    imported=int(scalar('SELECT id FROM products WHERE sku=?',[prefix+'-IMPORTED']))
    check('Imported product has all warehouse balances',int(scalar('SELECT COUNT(*) FROM product_stocks WHERE product_id=?',[imported]))==int(scalar('SELECT COUNT(*) FROM warehouses')))
    admin.post('/categories/import',{'csv_data':'name,description\n'+prefix+' imported,Imported description'},302,'Successful CSV import')
    admin.post('/categories/import',{'csv_data':'name,description\n'+prefix+' rollback,First valid row\n,Invalid empty name'},422,'Invalid CSV import rejected atomically')
    check('Failed CSV import leaves no earlier rows',int(scalar('SELECT COUNT(*) FROM categories WHERE name=?',[prefix+' rollback']))==0)
    status,body,_=admin.request('/products/update',{**product,'id':pid,'name':prefix+' edit retained','reorder_point':'-1'})
    check('Invalid product edit retains attempted values',status==422 and FormParser(body).values.get('name')==prefix+' edit retained')
    check('Invalid product edit does not update DB',scalar('SELECT name FROM products WHERE id=?',[pid])==product['name']+' edited')
    status,_,_=admin.request('/categories',{'csrf_token':'wrong','name':'should-not-save'});check('CSRF forgery denied',status==403)
    check('CSRF failure does not persist',int(scalar('SELECT COUNT(*) FROM categories WHERE name=?',['should-not-save']))==0)
    for client,role in [(admin,'Admin'),(sales,'Sales'),(warehouse,'Warehouse')]:
        client.post('/logout',{},302,role+' logout');check(role+' protected URL blocked after logout',client.request('/dashboard')[0]==302);check(role+' API blocked after logout',client.request('/api/products/'+product['sku']+'/availability')[0]==401)
except Exception as error:
    checks.append({'name':'Unexpected test setup/workflow exception','passed':False,'detail':str(error)})
finally:
    report={'project':args.project,'url':args.url,'checks':len(checks),'passed':sum(c['passed'] for c in checks),'failed':sum(not c['passed'] for c in checks),'results':checks}
    os.makedirs(os.path.dirname(args.output) or '.',exist_ok=True)
    with open(args.output,'w') as file:json.dump(report,file,indent=2)
    print(json.dumps({key:report[key] for key in ['checks','passed','failed']}))
raise SystemExit(1 if report['failed'] else 0)
