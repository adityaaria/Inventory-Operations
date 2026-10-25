#!/usr/bin/env python3
"""Real HTTP workflows + read-only SQL assertions against an explicitly disposable Docker project."""
import secrets, argparse, csv, http.cookiejar, html.parser, io, json, os, re, subprocess, time, urllib.error, urllib.parse, urllib.request

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
ORDER_NUMBER=re.compile(r'(PO|SO)-\d{4}-\d{4,}')
def latest_order(table):
    """Newest order row; numbers are server-assigned PREFIX-YYMM-NNNN (D-11, ADR-017), never the posted value."""
    row=db('SELECT id, order_number FROM '+table+' ORDER BY id DESC LIMIT 1')[0]
    return int(row['id']),row['order_number']
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
        if data is not None:
            data={'csrf_token':self.csrf,**data}
            if path in ['/purchase-orders/receive','/sales-orders/issue']:data.setdefault('operation_key',secrets.token_hex(16))
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
    check('New product is present in low-stock data',int(scalar('SELECT COUNT(*) FROM product_stocks ps INNER JOIN products p ON p.id=ps.product_id WHERE ps.product_id=? AND ps.quantity < COALESCE(ps.reorder_point_override, p.reorder_point)',[pid]))>0)
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

    _,po_form,_=warehouse.request('/purchase-orders/create')
    check('PO create form has no order number input',"name=\"order_number\"" not in po_form and 'Order number is assigned when the draft is saved' in po_form)
    # order_number is posted on purpose: the server must ignore it and assign PO-YYMM-NNNN.
    po_data={'order_number':prefix+'-PO','supplier_id':refs['suppliers'],'warehouse_id':refs['warehouses'],'product_id':pid,'quantity':'10','purchase_price':'12.50'}
    warehouse.post('/purchase-orders',po_data)
    po,po_number=latest_order('purchase_orders')
    check('PO number assigned by server, posted value ignored',ORDER_NUMBER.fullmatch(po_number) is not None and po_number.startswith('PO-') and int(scalar('SELECT COUNT(*) FROM purchase_orders WHERE order_number=?',[prefix+'-PO']))==0,po_number)
    item=int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[po]))
    warehouse.post('/purchase-orders/receive',{'id':po,'item_id':item,'quantity':1},422,'Draft PO receipt rejected')
    sales.post('/purchase-orders/order',{'id':po},403,'Sales cannot order PO')
    admin.post('/purchase-orders/order',{'id':po})
    admin.post('/purchase-orders/order',{'id':po},422,'Repeated mark-ordered is validation error')
    _,receipt_form,_=warehouse.request('/purchase-orders/show?id='+str(po))
    check('Receipt form includes generated operation key',bool(re.fullmatch('[a-f0-9]{32}',FormParser(receipt_form).values.get('operation_key',''))))
    warehouse.post('/purchase-orders/receive',{'id':po,'item_id':item,'quantity':4,'operation_key':''},422,'Missing receipt key rejected')
    receipt_key=secrets.token_hex(16)
    receipt_request={'id':po,'item_id':item,'quantity':4,'operation_key':receipt_key}
    warehouse.post('/purchase-orders/receive',receipt_request)
    warehouse.post('/purchase-orders/receive',receipt_request,label='Same receipt key replay succeeds')
    warehouse.post('/purchase-orders/receive',{**receipt_request,'quantity':5},409,'Same receipt key changed payload conflicts')
    check('Receipt replay/conflict leave received quantity unchanged',int(scalar('SELECT received_quantity FROM purchase_order_items WHERE id=?',[item]))==4)
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
    status,body,_=warehouse.request('/purchase-orders',{**po_data,'quantity':'0'})
    check('PO validation retains header/item input',status==422 and 'order_number' not in FormParser(body).values and FormParser(body).values.get('quantity')=='0' and FormParser(body).values.get('product_id')==str(pid))
    admin.post('/purchase-orders',po_data)
    cancelled_po,cancelled_number=latest_order('purchase_orders')
    check('Next PO number is consecutive',cancelled_number[:8]==po_number[:8] and int(cancelled_number[8:])==int(po_number[8:])+1,[po_number,cancelled_number])
    admin.post('/purchase-orders/cancel',{'id':cancelled_po})
    admin.post('/purchase-orders/order',{'id[]':po},422,'Malformed PO transition ID rejected')
    admin.post('/sales-orders/approve',{'id[]':po},422,'Malformed SO transition ID rejected')
    check('Malformed transition audit has no invented entity',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action IN ('purchase-orders.order','sales-orders.approve') AND status='failure' AND entity_id IS NULL"))>=2)
    check('Draft PO cancellation',scalar('SELECT status FROM purchase_orders WHERE id=?',[cancelled_po])=='Cancelled')

    so_data={'order_number':prefix+'-SO','customer_id':refs['customers'],'warehouse_id':refs['warehouses'],'product_id':pid,'quantity':'3','selling_price':'20.00'}
    sales.post('/sales-orders',so_data);so,so_number=latest_order('sales_orders')
    check('SO number assigned by server, posted value ignored',ORDER_NUMBER.fullmatch(so_number) is not None and so_number.startswith('SO-') and int(scalar('SELECT COUNT(*) FROM sales_orders WHERE order_number=?',[prefix+'-SO']))==0,so_number)
    check('Sales owner sees order',sales.request('/sales-orders/show?id='+str(so))[0]==200)
    check('Other Sales cannot view order',other.request('/sales-orders/show?id='+str(so))[0]==403)
    warehouse.post('/sales-orders/issue',{'id':so},422,'Draft SO cannot issue')
    other.post('/sales-orders/submit',{'id':so},403,'Other Sales cannot submit order')
    sales.post('/sales-orders/submit',{'id':so});sales.post('/sales-orders/approve',{'id':so},403,'Sales cannot approve own SO')
    admin.post('/sales-orders/approve',{'id':so});admin.post('/sales-orders/approve',{'id':so},422,'Repeated approval is validation error')
    check('Approver persisted',int(scalar('SELECT approved_by FROM sales_orders WHERE id=?',[so]))==1)
    sales.post('/sales-orders/issue',{'id':so},403,'Sales cannot issue')
    _,issue_form,_=warehouse.request('/sales-orders/show?id='+str(so))
    check('Issue form includes generated operation key',bool(re.fullmatch('[a-f0-9]{32}',FormParser(issue_form).values.get('operation_key',''))))
    warehouse.post('/sales-orders/issue',{'id':so,'operation_key':''},422,'Missing issue key rejected')
    issue_request={'id':so,'operation_key':secrets.token_hex(16)}
    warehouse.post('/sales-orders/issue',issue_request)
    warehouse.post('/sales-orders/issue',issue_request,label='Same issue key replay succeeds')
    warehouse.post('/sales-orders/issue',{'id':so},422,'Duplicate goods issue rejected')
    admin.post('/sales-orders/cancel',{'id':so},422,'Fulfilled SO cannot cancel')
    check('SO Fulfilled with exact remaining stock',scalar('SELECT status FROM sales_orders WHERE id=?',[so])=='Fulfilled' and int(scalar('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[pid,refs['warehouses']]))==7)
    check('Exactly one Issue ledger',int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[so]))==1)
    check('Exactly one atomic issue audit',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='sales-orders.issue' AND entity_id=? AND status='success'",[so]))==1)
    for quantity,suffix in [('8','INSUFFICIENT'),('2','CANCEL')]:
        sales.post('/sales-orders',{**so_data,'quantity':quantity});ident,_=latest_order('sales_orders');sales.post('/sales-orders/submit',{'id':ident})
        if suffix=='INSUFFICIENT':
            # D-12 / ADR-018: the shortage is refused at approval (nothing reserved); issue stays refused for a non-Approved order.
            admin.post('/sales-orders/approve',{'id':ident},422,'Insufficient available stock refuses approval');warehouse.post('/sales-orders/issue',{'id':ident},422,'Insufficient stock rejected')
            check('Refused approval preserves order/balance/reservation/ledger',scalar('SELECT status FROM sales_orders WHERE id=?',[ident])=='PendingApproval' and int(scalar('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[pid,refs['warehouses']]))==7 and int(scalar('SELECT reserved_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[pid,refs['warehouses']]))==0 and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[ident]))==0)
        else:
            admin.post('/sales-orders/cancel',{'id':ident});check('Pending SO cancellation',scalar('SELECT status FROM sales_orders WHERE id=?',[ident])=='Cancelled')
    # D-14 / ADR-020: partial shipments. 7 on hand at the pair; one order of 5 ships 2, then its remainder is closed.
    def pair_state():
        row=db('SELECT quantity, reserved_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[pid,refs['warehouses']])[0]
        return [int(row['quantity']),int(row['reserved_quantity'])]
    sales.post('/sales-orders',{**so_data,'quantity':'5'});partial,_=latest_order('sales_orders');sales.post('/sales-orders/submit',{'id':partial});admin.post('/sales-orders/approve',{'id':partial})
    line=int(scalar('SELECT id FROM sales_order_items WHERE sales_order_id=?',[partial]))
    _,partial_form,_=warehouse.request('/sales-orders/show?id='+str(partial))
    check('Issue form offers per-line quantity inputs prefilled with remaining','name="quantities['+str(line)+']"' in partial_form and FormParser(partial_form).values.get('quantities['+str(line)+']')=='5')
    warehouse.post('/sales-orders/issue',{'id':partial,'quantities['+str(line)+']':'0'},422,'All-zero issue rejected')
    warehouse.post('/sales-orders/issue',{'id':partial,'quantities['+str(line)+']':'6'},422,'Issue above remaining rejected')
    shipment={'id':partial,'operation_key':secrets.token_hex(16),'quantities['+str(line)+']':'2'}
    warehouse.post('/sales-orders/issue',shipment,label='Partial shipment issued')
    warehouse.post('/sales-orders/issue',shipment,label='Partial shipment replay succeeds')
    warehouse.post('/sales-orders/issue',{**shipment,'quantities['+str(line)+']':'3'},409,'Same key with different quantities conflicts')
    check('Partial shipment: PartiallyFulfilled, issued 2, remainder stays reserved',scalar('SELECT status FROM sales_orders WHERE id=?',[partial])=='PartiallyFulfilled' and int(scalar('SELECT issued_quantity FROM sales_order_items WHERE id=?',[line]))==2 and pair_state()==[5,3] and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[partial]))==1,pair_state())
    _,detail,_=sales.request('/sales-orders/show?id='+str(partial))
    check('Detail shows Ordered/Issued/Remaining and shipment history','<th scope="col">Issued</th>' in detail and 'Shipment History' in detail and 'Issued 2 of 5 units' in detail)
    admin.post('/sales-orders/cancel',{'id':partial},422,'PartiallyFulfilled SO cannot cancel')
    sales.post('/sales-orders/close-remaining',{'id':partial,'reason':'Customer refused the rest'},403,'Sales cannot close remaining fulfilment')
    admin.post('/sales-orders/close-remaining',{'id':partial,'reason':''},422,'Close remaining needs a reason')
    admin.post('/sales-orders/close-remaining',{'id':partial,'reason':'Customer refused the rest'},label='Admin closes remaining fulfilment')
    check('Closure released the remainder and kept PartiallyFulfilled',pair_state()==[5,0] and scalar('SELECT status FROM sales_orders WHERE id=?',[partial])=='PartiallyFulfilled' and int(scalar('SELECT closed_units FROM sales_order_closures WHERE sales_order_id=?',[partial]))==3,pair_state())
    warehouse.post('/sales-orders/issue',{'id':partial,'quantities['+str(line)+']':'1'},422,'Issue after closure rejected')
    check('Closure audited once',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='sales-orders.close-remainder' AND entity_id=? AND status='success'",[partial]))==1)
    sales.post('/sales-orders',{**so_data,'quantity':'2'});twice,_=latest_order('sales_orders');sales.post('/sales-orders/submit',{'id':twice});admin.post('/sales-orders/approve',{'id':twice})
    twice_line=int(scalar('SELECT id FROM sales_order_items WHERE sales_order_id=?',[twice]))
    for label in ['first','second']: warehouse.post('/sales-orders/issue',{'id':twice,'quantities['+str(twice_line)+']':'1'},label='Two-shipment order: '+label+' shipment')
    check('Two shipments complete the order',scalar('SELECT status FROM sales_orders WHERE id=?',[twice])=='Fulfilled' and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[twice]))==2 and pair_state()==[3,0],pair_state())
    # D-15 / ADR-021: a transfer is dispatched (goods in transit), then received with damage and shortage written off.
    tsku=prefix+'-TRANSIT';admin.post('/products',{'sku':tsku,'name':prefix+' transit','unit':'pcs','purchase_price':'10','selling_price':'20','reorder_point':'2','category_id':'1'})
    tpid=int(scalar('SELECT id FROM products WHERE sku=?',[tsku]))
    def tpair(warehouse_id):return int(scalar('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[tpid,warehouse_id]))
    admin.post('/purchase-orders',{'supplier_id':'1','warehouse_id':'1','product_id':tpid,'quantity':'10','purchase_price':'10'});tpo,_=latest_order('purchase_orders');admin.post('/purchase-orders/order',{'id':tpo})
    warehouse.post('/purchase-orders/receive',{'id':tpo,'item_id':int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[tpo])),'quantity':'10'},label='Transit product received into warehouse 1')
    treason=prefix+' e2e transit';warehouse.post('/inventory-operations',{'kind':'Transfer','warehouse_id':'1','destination_id':'2','product_id':tpid,'quantity':'5','reason':treason},label='Warehouse proposes transfer 1 -> 2')
    tr=int(scalar('SELECT id FROM inventory_operations WHERE reason=?',[treason]))
    warehouse.post('/inventory-operations/dispatch',{'id':tr},422,'Dispatch before approval refused')
    admin.post('/inventory-operations/decide',{'id':tr,'decision':'Approved','reason':'Rebalance approved'},label='Admin approves transfer')
    warehouse.post('/inventory-operations/dispatch',{'id':tr},label='Transfer dispatched')
    check('Dispatched: source -5, destination unchanged, 5 in transit',[tpair(1),tpair(2)]==[5,0] and int(scalar('SELECT dispatched_quantity FROM inventory_operation_items WHERE operation_id=?',[tr]))==5,[tpair(1),tpair(2)])
    _,in_transit,_=warehouse.request('/inventory-operations/in-transit');check('In Transit page lists it','Transfer #'+str(tr) in in_transit)
    warehouse.post('/inventory-operations/decide',{'id':tr,'decision':'Cancelled','reason':'Too late'},422,'Dispatched transfer cannot be cancelled')
    warehouse.post('/inventory-operations/receive',{'id':tr,'received['+str(tpid)+']':'3','damaged['+str(tpid)+']':'1','receipt_reason':''},422,'Receipt discrepancy needs a reason')
    warehouse.post('/inventory-operations/receive',{'id':tr,'received['+str(tpid)+']':'3','damaged['+str(tpid)+']':'1','receipt_reason':'Satu dus basah, satu dus hilang'},label='Transfer received with damage and shortage')
    warehouse.post('/inventory-operations/receive',{'id':tr},label='Repeated receipt is a no-op')
    row=db('SELECT received_quantity r, damaged_quantity d, short_quantity s FROM inventory_operation_items WHERE operation_id=?',[tr])[0]
    check('Received: 3 good at destination, 1 damaged + 1 short written off',[tpair(1),tpair(2)]==[5,3] and [int(row['r']),int(row['d']),int(row['s'])]==[3,1,1] and scalar('SELECT status FROM inventory_operations WHERE id=?',[tr])=='Received',[tpair(1),tpair(2),row])
    check('Transfer ledger: one dispatch row, one receipt row',[int(v) for v in [r['quantity_delta'] for r in db("SELECT quantity_delta FROM stock_ledger WHERE reference_type='TRANSFER' AND reference_id=? ORDER BY id",[tr])]]==[-5,3])
    # D-16 / ADR-022: a seed product kept in pcs and sold by the karton; reservation, shipment and ledger in base units.
    unit_row=db("SELECT p.id pid, p.sku sku, u.factor_to_base f, u.selling_price price FROM products p JOIN product_units u ON u.product_id=p.id AND u.unit_code='karton' JOIN product_stocks ps ON ps.product_id=p.id AND ps.warehouse_id=1 WHERE p.is_active=1 AND u.is_active=1 AND u.selling_price IS NOT NULL AND CAST(ps.quantity AS SIGNED)-CAST(ps.reserved_quantity AS SIGNED)>=3*u.factor_to_base ORDER BY p.id LIMIT 1")[0]
    upid,ufactor=int(unit_row['pid']),int(unit_row['f'])
    def upair():
        row=db('SELECT quantity, reserved_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=1',[upid])[0];return [int(row['quantity']),int(row['reserved_quantity'])]
    ubefore=upair()
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':upid,'quantity':'2','unit_code':'karton'},label='Sales orders 2 karton of a seed product')
    uso,_=latest_order('sales_orders');uline=db('SELECT id, quantity, unit_quantity, unit_code, unit_factor, selling_price FROM sales_order_items WHERE sales_order_id=?',[uso])[0]
    check('Karton line stores base quantity, unit snapshot and the karton master price',[int(uline['quantity']),int(uline['unit_quantity']),uline['unit_code'],int(uline['unit_factor']),uline['selling_price']]==[2*ufactor,2,'karton',ufactor,unit_row['price']],uline)
    sales.post('/sales-orders/submit',{'id':uso});admin.post('/sales-orders/approve',{'id':uso},label='Admin approves the karton order')
    check('Approval reserves 2 karton in base units',upair()==[ubefore[0],ubefore[1]+2*ufactor],upair())
    warehouse.post('/sales-orders/issue',{'id':uso,'quantities['+str(uline['id'])+']':'1'},label='Warehouse ships 1 karton')
    check('One karton shipped: stock and reservation down by the factor',upair()==[ubefore[0]-ufactor,ubefore[1]+ufactor] and scalar('SELECT status FROM sales_orders WHERE id=?',[uso])=='PartiallyFulfilled',upair())
    warehouse.post('/sales-orders/issue',{'id':uso,'quantities['+str(uline['id'])+']':'1'},label='Warehouse ships the last karton')
    check('Order fulfilled; ledger rows are whole karton in base units',upair()==[ubefore[0]-2*ufactor,ubefore[1]] and scalar('SELECT status FROM sales_orders WHERE id=?',[uso])=='Fulfilled' and [int(r['quantity']) for r in db("SELECT quantity FROM stock_ledger WHERE reference_type='SO' AND reference_id=? ORDER BY id",[uso])]==[ufactor,ufactor],upair())
    _,updetail,_=admin.request('/products/show?id='+str(upid));check('Product detail shows the unit table and breakdown','Units of Measure' in updetail and '1 karton = '+str(ufactor)+' pcs' in updetail)
    _,uapi,_=sales.request('/api/products/'+unit_row['sku']+'/availability');uapi=json.loads(uapi)
    check('API keeps base quantities and lists units',uapi['base_unit']=='pcs' and any(u['unit']=='karton' and u['factor_to_base']==ufactor for u in uapi['units']))
    # D-17 / ADR-023: a product that tracks expiry is received in lots, shipped FEFO, reported and traced.
    lot_product={'sku':prefix+'-LOT','name':prefix+' lot product','unit':'pcs','purchase_price':'1000.00','selling_price':'1500.00','reorder_point':'1','category_id':refs['categories'],'tracks_expiry':'1'}
    admin.post('/products',lot_product,label='Admin creates a product that tracks expiry')
    lpid=int(scalar('SELECT id FROM products WHERE sku=?',[lot_product['sku']]))
    check('Product stores tracks_expiry',int(scalar('SELECT tracks_expiry FROM products WHERE id=?',[lpid]))==1)
    warehouse.post('/purchase-orders',{'supplier_id':refs['suppliers'],'warehouse_id':'1','product_id':lpid,'quantity':'6'},label='PO for the tracked product')
    lpo,_=latest_order('purchase_orders');admin.post('/purchase-orders/order',{'id':lpo})
    litem=int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[lpo]))
    soon=str(scalar('SELECT DATE(UTC_TIMESTAMP() + INTERVAL 7 HOUR) + INTERVAL 20 DAY'));later=str(scalar('SELECT DATE(UTC_TIMESTAMP() + INTERVAL 7 HOUR) + INTERVAL 300 DAY'))
    _,podetail,_=warehouse.request('/purchase-orders/show?id='+str(lpo));check('PO receipt form asks for lots and expiry','name="lot_code[]"' in podetail and 'name="expiry_date[]"' in podetail and 'expiry required' in podetail)
    warehouse.post('/purchase-orders/receive',{'id':lpo,'item_id':litem,'quantity':'6'},422,'Tracked receipt without lot and expiry rejected')
    status,body,_=warehouse.request('/purchase-orders/receive',{'id':lpo,'item_id':litem,'quantity':'6','lot_code[0]':'E2E-A','expiry_date[0]':later,'lot_quantity[0]':'4','lot_code[1]':'E2E-B','expiry_date[1]':soon,'lot_quantity[1]':'1'})
    check('Lot quantities that do not add up are rejected and kept',status==422 and 'add up' in body and 'value="E2E-B"' in body,status)
    warehouse.post('/purchase-orders/receive',{'id':lpo,'item_id':litem,'quantity':'6','lot_code[0]':'E2E-A','expiry_date[0]':later,'lot_quantity[0]':'4','lot_code[1]':'E2E-B','expiry_date[1]':soon,'lot_quantity[1]':'2'},label='Receipt split into two lots')
    lots=db('SELECT lot_code, quantity, expiry_date, unit_cost FROM stock_lots WHERE product_id=? AND warehouse_id=1 ORDER BY expiry_key',[lpid])
    check('Two lots with expiry and cost per base',[(r['lot_code'],int(r['quantity']),str(r['expiry_date'])) for r in lots]==[('E2E-B',2,soon),('E2E-A',4,later)] and lots[0]['unit_cost']=='1000.00',lots)
    check('One Receipt ledger row per lot',int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='PO' AND reference_id=? AND lot_id IS NOT NULL",[lpo]))==2)
    sales.post('/sales-orders',{'customer_id':refs['customers'],'warehouse_id':'1','product_id':lpid,'quantity':'3'},label='Sales orders 3 of the tracked product')
    lso,_=latest_order('sales_orders');sales.post('/sales-orders/submit',{'id':lso});admin.post('/sales-orders/approve',{'id':lso})
    _,sodetail,_=warehouse.request('/sales-orders/show?id='+str(lso));check('SO issue form explains FEFO','data-fefo-hint' in sodetail)
    warehouse.post('/sales-orders/issue',{'id':lso},label='Warehouse issues the order (FEFO)')
    issued=db("SELECT l.lot_code, s.quantity FROM stock_ledger s JOIN stock_lots l ON l.id=s.lot_id WHERE s.reference_type='SO' AND s.reference_id=? ORDER BY s.id",[lso])
    check('FEFO: the soon-expiring lot ships first, then the later lot',[(r['lot_code'],int(r['quantity'])) for r in issued]==[('E2E-B',2),('E2E-A',1)],issued)
    _,sodetail,_=warehouse.request('/sales-orders/show?id='+str(lso));check('SO detail names the lots shipped','E2E-B' in sodetail and 'E2E-A' in sodetail)
    _,pdetail,_=sales.request('/products/show?id='+str(lpid));check('Product detail lists lots; Sales sees no lot cost','Product lots table' in pdetail and 'E2E-A' in pdetail and 'Unit cost' not in pdetail)
    _,pdetail,_=admin.request('/products/show?id='+str(lpid));check('Admin sees lot cost','Unit cost' in pdetail)
    lid=int(scalar("SELECT id FROM stock_lots WHERE product_id=? AND lot_code='E2E-A'",[lpid]))
    status,trace,_=warehouse.request('/lots/show?id='+str(lid));check('Recall trace shows receipt PO and the customer shipment',status==200 and 'Receipt from' in trace and 'Issue to' in trace)
    status,search,_=warehouse.request('/lots?q=E2E-A');check('Lot code search finds the lot',status==200 and '/lots/show?id='+str(lid) in search)
    check('Sales cannot open lot trace or the expiry report',sales.request('/lots?q=E2E-A')[0]==403 and sales.request('/reports/expiry')[0]==403 and sales.request('/reports/expiry.csv')[0]==403)
    status,expiry,_=warehouse.request('/reports/expiry?status=near&q='+urllib.parse.quote(prefix));check('Expiry report renders for Warehouse',status==200 and 'Near-expiry lots' in expiry)
    status,body,headers=admin.request('/reports/expiry.csv?status=all');check('Expiry CSV export',status==200 and 'text/csv' in headers.get('Content-Type','') and body.startswith('SKU,Product,Warehouse,Lot,ExpiryDate'))
    check('Invalid expiry filter is 422',admin.request('/reports/expiry?status=rotten')[0]==422)
    _,dash,_=admin.request('/dashboard');check('Dashboard shows near-expiry and expired lot card','data-expiry-card' in dash)
    _,lapi,_=sales.request('/api/products/'+lot_product['sku']+'/availability');lapi=json.loads(lapi)
    check('API adds expiry fields and keeps existing ones',lapi['total_quantity']==3 and lapi['total_expired']==0 and lapi['total_available_excluding_expired']==3 and lapi['nearest_expiry']==later and 'available_quantity' in lapi['warehouses'][0],lapi)
    check('Every lot equals its ledger and the pair equals its lots',int(scalar("SELECT COUNT(*) FROM stock_lots l LEFT JOIN (SELECT lot_id, SUM(CASE movement_type WHEN 'Receipt' THEN CAST(quantity AS SIGNED) WHEN 'Issue' THEN -CAST(quantity AS SIGNED) ELSE quantity_delta END) m FROM stock_ledger WHERE lot_id IS NOT NULL GROUP BY lot_id) x ON x.lot_id=l.id WHERE CAST(l.quantity AS SIGNED)<>CAST(l.opening_quantity AS SIGNED)+COALESCE(x.m,0)"))==0 and int(scalar('SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(quantity) q FROM stock_lots GROUP BY product_id, warehouse_id) l ON l.product_id=ps.product_id AND l.warehouse_id=ps.warehouse_id WHERE ps.quantity<>COALESCE(l.q,0)'))==0)
    # Expired write-off of the seed's expired lots in warehouse 3: proposed by Warehouse, approved by another Admin, posted.
    expired_before=int(scalar("SELECT COUNT(*) FROM stock_lots WHERE warehouse_id=3 AND quantity>0 AND expiry_date < DATE(UTC_TIMESTAMP() + INTERVAL 7 HOUR)"))
    if expired_before>0:
        wreason=prefix+' expired write-off'
        warehouse.post('/inventory-operations/expired-write-off',{'warehouse_id':'3','reason':wreason},label='Warehouse proposes the expired write-off')
        wop=int(scalar('SELECT id FROM inventory_operations WHERE reason=?',[wreason]))
        check('Write-off is an Adjustment with one line per expired lot',scalar('SELECT purpose FROM inventory_operations WHERE id=?',[wop])=='ExpiredWriteOff' and int(scalar('SELECT COUNT(*) FROM inventory_operation_items WHERE operation_id=? AND lot_id IS NOT NULL AND quantity=0',[wop]))==min(100,expired_before))
        warehouse.post('/inventory-operations/decide',{'id':wop,'decision':'Approved','reason':'Self approval attempt'},403,'Warehouse cannot approve the write-off')
        admin.post('/inventory-operations/decide',{'id':wop,'decision':'Approved','reason':'Pemusnahan disaksikan'},label='Another Admin approves the write-off')
        status,_,_=warehouse.request('/inventory-operations/post',{'id':wop})
        if status==302:
            check('Posted write-off empties every listed lot with one ledger row each',int(scalar('SELECT COUNT(*) FROM inventory_operation_items i JOIN stock_lots l ON l.id=i.lot_id WHERE i.operation_id=? AND l.quantity>0',[wop]))==0 and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='ADJUSTMENT' AND reference_id=?",[wop]))==int(scalar('SELECT COUNT(*) FROM inventory_operation_items WHERE operation_id=?',[wop])))
        else:
            check('A refused write-off (reservation guard) changes nothing',status==422 and scalar('SELECT status FROM inventory_operations WHERE id=?',[wop])=='Approved' and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='ADJUSTMENT' AND reference_id=?",[wop]))==0,status)
    check('No Issue row ever took units of an expired lot',int(scalar("SELECT COUNT(*) FROM stock_ledger s JOIN stock_lots l ON l.id=s.lot_id WHERE s.movement_type='Issue' AND l.expiry_date < DATE(s.created_at + INTERVAL 7 HOUR)"))==0)
    # D-18 / ADR-024: barcode lookup and a blind stock count of the tracked product (exact count, no variance to post).
    seeded=db('SELECT u.barcode, u.factor_to_base, p.sku FROM product_units u JOIN products p ON p.id=u.product_id WHERE u.barcode IS NOT NULL AND u.is_base=0 AND u.is_active=1 ORDER BY u.id DESC LIMIT 1')[0]
    status,body,headers=warehouse.request('/api/barcodes/'+seeded['barcode'],fetch=True)
    check('Barcode API returns product and unit factor as JSON',status==200 and 'application/json' in headers.get('Content-Type','') and json.loads(body)['unit']['factor_to_base']==int(seeded['factor_to_base']) and json.loads(body)['product']['sku']==seeded['sku'])
    check('Barcode API refuses Sales (403) and malformed codes (422)',sales.request('/api/barcodes/'+seeded['barcode'],fetch=True)[0]==403 and warehouse.request('/api/barcodes/ab',fetch=True)[0]==422)
    wid=int(scalar("SELECT id FROM users WHERE email='warehouse1@example.test'"))
    ereason=prefix+' e2e count'
    admin.post('/stock-counts',{'warehouse_id':'1','scope':'Products','product_ids[]':str(lpid),'counter_ids[]':str(wid),'reason':ereason},label='Admin creates a stock count')
    ecount=int(scalar('SELECT id FROM stock_counts WHERE reason=?',[ereason]));admin.post('/stock-counts/start',{'id':ecount},label='Admin starts the count')
    elines=db('SELECT l.id, s.quantity FROM stock_count_lines l JOIN stock_lots s ON s.id=l.lot_id WHERE l.count_id=?',[ecount])
    _,sheet,_=warehouse.request('/stock-counts/show?id='+str(ecount));check('Counting sheet is blind and scannable','data-scan-count' in sheet and 'data-expected' not in sheet and len(elines)==1)
    warehouse.post('/stock-counts/count',{'id':ecount,**{'counts['+str(r['id'])+'][quantity]':str(r['quantity']) for r in elines},**{'counts['+str(r['id'])+'][unit]':'pcs' for r in elines}},label='Counter counts the lots exactly')
    warehouse.post('/stock-counts/submit',{'id':ecount},label='Counter submits')
    warehouse.post('/stock-counts/approve',{'id':ecount},403,label='Warehouse cannot approve a count')
    admin.post('/stock-counts/approve',{'id':ecount},label='Admin approves the count')
    warehouse.post('/stock-counts/post',{'id':ecount},label='Warehouse posts the count')
    check('An exact count posts without ledger rows',scalar('SELECT status FROM stock_counts WHERE id=?',[ecount])=='Posted' and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='STOCK_COUNT' AND reference_id=?",[ecount]))==0 and int(scalar('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=1',[lpid]))==3)
    status,body,_=sales.request('/sales-orders',{**so_data,'quantity':'-1'})
    check('SO validation retains header/item input',status==422 and 'order_number' not in FormParser(body).values and FormParser(body).values.get('quantity')=='-1')

    for client,role in [(admin,'Admin'),(sales,'Sales'),(warehouse,'Warehouse')]:
        for path in ['/dashboard','/products','/products/show?id='+str(pid),'/purchase-orders','/sales-orders','/reports']:
            check(role+' read '+path,client.request(path)[0]==200)
        status,body,headers=client.request('/api/products/'+product['sku']+'/availability')
        check(role+' API matches stock',status==200 and 'application/json' in headers.get('Content-Type','') and json.loads(body)['total_quantity']==3)
        check(role+' unknown SKU API 404',client.request('/api/products/unknown-e2e/availability')[0]==404)
    for module,term in [('products',prefix),('purchase-orders',po_number),('sales-orders',so_number)]:
        status,body,_=admin.request('/'+module+'?q='+urllib.parse.quote(term)+'&page=1&sort=order_date&direction=asc')
        check(module+' filtered list has test data',status==200 and term in body)
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
    # D-19 / ADR-025: on the seeded data, a Grosir customer's price list prices a quotation that becomes an SO, its shipment
    # gets a surat jalan and Admin bills it with PPN and records the payment; every role sees only what it may.
    fx=db("""SELECT c.id customer, pli.product_id product, pli.unit_code unit, pli.unit_price price FROM customers c
        JOIN price_lists pl ON pl.id=c.price_list_id AND pl.name='Grosir' AND pl.is_active=1
        JOIN price_list_items pli ON pli.price_list_id=pl.id AND pli.min_quantity=10
        JOIN product_units u ON u.product_id=pli.product_id AND u.unit_code=pli.unit_code
        JOIN product_stocks ps ON ps.product_id=pli.product_id AND ps.warehouse_id=1
        JOIN products p ON p.id=pli.product_id AND p.is_active=1 AND p.tracks_expiry=0
        WHERE c.is_active=1 AND ps.quantity-ps.reserved_quantity >= 20*u.factor_to_base ORDER BY ps.quantity DESC, c.id LIMIT 1""")[0]
    code,body,_=sales.request('/api/price-resolution?'+urllib.parse.urlencode({'customer_id':fx['customer'],'product_id':fx['product'],'unit_code':fx['unit'],'quantity':'10'}),fetch=True)
    check('Seeded Grosir tier resolves through the API',code==200 and json.loads(body)['source']=='PRICE_LIST' and json.loads(body)['price']==fx['price'],body)
    code,_,headers=sales.request('/quotations',{'customer_id':fx['customer'],'warehouse_id':'1','product_id':fx['product'],'quantity':'10','unit_code':fx['unit'],'notes':prefix+' e2e quote'})
    qid=int(scalar('SELECT id FROM quotations WHERE notes=?',[prefix+' e2e quote']))
    check('Sales creates a quotation at the tier price',code==302 and scalar('SELECT unit_price FROM quotation_items WHERE quotation_id=?',[qid])==fx['price'])
    check('Quotations are hidden from other Sales and Warehouse',[other.request('/quotations/show?id='+str(qid))[0],warehouse.request('/quotations/show?id='+str(qid))[0]]==[403,403])
    sales.post('/quotations/send',{'id':qid});sales.post('/quotations/accept',{'id':qid});sales.post('/quotations/convert',{'id':qid},label='Accepted quotation converted to an SO draft')
    qso=int(scalar('SELECT id FROM sales_orders WHERE quotation_id=?',[qid]))
    sales.post('/sales-orders/submit',{'id':qso});admin.post('/sales-orders/approve',{'id':qso},label='Converted SO approved')
    warehouse.post('/sales-orders/issue',{'id':qso,'vehicle':'B 7777 E2E','driver':'Driver E2E'},label='Shipment creates its surat jalan')
    dn=int(scalar('SELECT id FROM delivery_notes WHERE sales_order_id=?',[qso]))
    status,body,_=warehouse.request('/delivery-notes/show?id='+str(dn));check('Warehouse prints the surat jalan',status==200 and 'SURAT JALAN' in body and 'B 7777 E2E' in body)
    check('Other Sales cannot see the delivery note',other.request('/delivery-notes/show?id='+str(dn))[0]==403)
    admin.post('/invoices',{'delivery_note_id':dn},label='Admin invoices the delivery note')
    inv=db('SELECT id, subtotal, dpp_amount, vat_amount, total_amount FROM invoices WHERE delivery_note_id=? AND voided_at IS NULL',[dn])[0]
    cents=round(float(inv['subtotal'])*100);dpp=(cents*11+50*12)//(100*12);vat=(dpp*12+50)//100
    check('Invoice totals recompute from 12 % of DPP 11/12',[inv['subtotal'],inv['dpp_amount'],inv['vat_amount'],inv['total_amount']]==['%.2f'%(cents/100),'%d.00'%dpp,'%d.00'%vat,'%.2f'%((cents+vat*100)/100)] and cents==10*round(float(fx['price'])*100),inv)
    paid_on=str(scalar('SELECT invoice_date FROM invoices WHERE id=?',[inv['id']]))
    code,body,_=admin.request('/invoices/payments',{'id':inv['id'],'paid_on':paid_on,'amount':'%.2f'%(float(inv['total_amount'])+0.01),'method':'Transfer'});check('Overpayment refused over HTTP',code==422 and 'Overpayment refused' in body)
    admin.post('/invoices/payments',{'id':inv['id'],'paid_on':paid_on,'amount':inv['total_amount'],'method':'Transfer','reference':'E2E'},label='Invoice paid in full')
    check('Invoice paid: owner Sales sees it read-only, Warehouse is refused',scalar('SELECT paid_amount FROM invoices WHERE id=?',[inv['id']])==inv['total_amount'] and sales.request('/invoices/show?id='+str(inv['id']))[0]==200 and warehouse.request('/invoices/show?id='+str(inv['id']))[0]==403)
    # D-20 / ADR-026: the shipment's COGS is the cost of the lots it took (= its surat jalan lots × lot cost); Admin sees COGS
    # and margin on the invoice, Sales and Warehouse never see costs or margin; valuation reports reconcile to the cent.
    cogs=int(scalar("SELECT COALESCE(SUM(-CAST(ROUND(cost_amount*100) AS SIGNED)),0) FROM stock_ledger WHERE movement_type='Issue' AND reference_type='SO' AND reference_id=?",[qso]))
    lots_cost=int(scalar('SELECT COALESCE(SUM(CAST(ROUND(x.quantity*l.unit_cost*100) AS SIGNED)),0) FROM delivery_note_lots x JOIN stock_lots l ON l.id=x.lot_id WHERE x.delivery_note_id=?',[dn]))
    check('COGS of the shipment = its delivery-note lots x lot cost',cogs==lots_cost>0,[cogs,lots_cost])
    _,ishow,_=admin.request('/invoices/show?id='+str(inv['id']))
    check('Admin invoice page shows COGS and margin = net - COGS','data-invoice-cogs="%d"'%cogs in ishow and 'data-invoice-margin="%d"'%(cents-cogs) in ishow)
    check('Sales invoice page and Warehouse delivery note show no cost','data-costing' not in sales.request('/invoices/show?id='+str(inv['id']))[1] and 'data-costing' not in warehouse.request('/delivery-notes/show?id='+str(dn))[1] and 'data-costing' not in sales.request('/sales-orders/show?id='+str(qso))[1])
    check('Admin SO page shows the COGS of its shipments','data-costing-cogs="%d"'%cogs in admin.request('/sales-orders/show?id='+str(qso))[1])
    code,body,_=admin.request('/reports/gross-margin.csv?'+urllib.parse.urlencode({'from':paid_on,'to':paid_on,'customer_id':fx['customer'],'group':'line'}));mrows=[r for r in csv.reader(io.StringIO(body)) if len(r)>2 and r[0]=='invoiced' and r[2]==scalar('SELECT invoice_number FROM invoices WHERE id=?',[inv['id']])]
    check('Gross margin CSV: net revenue, COGS and margin of the invoice line',code==200 and len(mrows)==1 and mrows[0][12:15]==['%.2f'%(cents/100),'%.2f'%(cogs/100),'%.2f'%((cents-cogs)/100)],mrows)
    check('Valuation and margin reports: Sales 403, Warehouse 403, Admin 200',[sales.request('/reports/gross-margin')[0],warehouse.request('/reports/gross-margin')[0],sales.request('/reports/valuation')[0],warehouse.request('/reports/valuation')[0],admin.request('/reports/valuation')[0]]==[403,403,403,403,200])
    _,recon,_=admin.request('/reports/valuation-reconciliation?from='+paid_on+'&to='+paid_on);check('Reconciliation of today balances to the cent','data-reconciled="1"' in recon)
    lot_value=int(scalar('SELECT COALESCE(SUM(CAST(ROUND(quantity*unit_cost*100) AS SIGNED)),0) FROM stock_lots'));transit=int(scalar("SELECT COALESCE(SUM(CAST(ROUND(t.dispatched_quantity*t.unit_cost*100) AS SIGNED)),0) FROM inventory_operation_lots t JOIN inventory_operations o ON o.id=t.operation_id WHERE o.status='Dispatched'"))
    check('Dashboard Inventory Value = lots at cost + in transit','data-inventory-value="%d"'%(lot_value+transit) in admin.request('/dashboard')[1],lot_value+transit)
    for path in ['/price-lists','/quotations','/delivery-notes','/invoices','/reports/receivables','/reports/sales-by-customer','/reports/valuation?by=lot','/reports/valuation-reconciliation','/reports/gross-margin','/reports/inventory-losses']:
        code,body,_=admin.request(path);check('Sales-document page renders '+path,code==200 and 'Warning:' not in body and 'Fatal error' not in body)
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
