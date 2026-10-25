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
def valuation_checks():
    """D-20 / ADR-026 invariants from the application's own read-only checker inside the app container."""
    php='$config=require "config/bootstrap.php"; $pdo=(new App\\Support\\DatabaseFactory($config))->create(); echo json_encode((new App\\Repository\\MySql\\ValuationChecks($pdo))->report());'
    return json.loads(subprocess.run(base+['exec','-T','app','php','-r',php],text=True,capture_output=True,check=True).stdout)
def sales_document_checks():
    """D-19 / ADR-025 invariants computed by the application's own read-only checker inside the app container."""
    php='$config=require "config/bootstrap.php"; $pdo=(new App\\Support\\DatabaseFactory($config))->create(); echo json_encode((new App\\Repository\\MySql\\SalesDocumentChecks($pdo))->report());'
    return json.loads(subprocess.run(base+['exec','-T','app','php','-r',php],text=True,capture_output=True,check=True).stdout)
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
    admin=Client();warehouse=Client();sales=Client()
    for client,email in [(admin,'admin@example.test'),(warehouse,'warehouse1@example.test'),(sales,'sales1@example.test')]:client.login(email)
    for path in ['/inventory-operations','/inventory-operations/create','/replenishment','/inventory-operations/balance?product_id=1&warehouse_id=1']:
        check('Sales denied '+path,sales.request(path)[0]==403)
    sku=prefix+'-BUSINESS'
    admin.post('/products',{'sku':sku,'name':prefix+' business','unit':'pcs','purchase_price':'10','selling_price':'20','reorder_point':'15','category_id':'1'})
    product=int(scalar('SELECT id FROM products WHERE sku=?',[sku]))
    def quantity(warehouse_id=1):return int(scalar('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[product,warehouse_id]))
    def operation(client,kind,qty,**extra):
        reason=prefix+' '+kind+' '+secrets.token_hex(4)
        data={'kind':kind,'warehouse_id':'1','product_id':product,'quantity':qty,'reason':reason,**extra}
        client.post('/inventory-operations',data)
        return int(scalar('SELECT id FROM inventory_operations WHERE reason=?',[reason]))
    def approve(id):admin.post('/inventory-operations/decide',{'id':id,'decision':'Approved','reason':'Inspected and verified'})
    def post(id,status=302):warehouse.post('/inventory-operations/post',{'id':id},status)
    # D-15 / ADR-021: posting a transfer dispatches it; the destination then receives it (all good unless given).
    def receive(id,lines=None,reason='',status=302,client=None):
        data={'id':id,'receipt_reason':reason}
        for pid,(good,damaged) in (lines or {}).items():data['received['+str(pid)+']']=str(good);data['damaged['+str(pid)+']']=str(damaged)
        return (client or warehouse).post('/inventory-operations/receive',data,status,label='Receive transfer #'+str(id)+' -> '+str(status))
    # Order numbers are server-assigned (D-11, ADR-017); the newest row is the one just created.
    admin.post('/purchase-orders',{'supplier_id':'1','warehouse_id':'1','product_id':product,'quantity':'10','purchase_price':'10'})
    po=int(scalar('SELECT MAX(id) FROM purchase_orders'));item=int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[po]));admin.post('/purchase-orders/order',{'id':po})
    receipt={'id':po,'item_id':item,'quantity':'3','operation_key':secrets.token_hex(16)};warehouse.post('/purchase-orders/receive',receipt)
    check('Inbound recommendation includes open remainder',admin.request('/replenishment?q='+sku)[0]==200)
    warehouse.post('/purchase-orders/close-remainder',{'id':po,'reason':'Stop remaining supply'},403)
    admin.post('/purchase-orders/close-remainder',{'id':po,'reason':'Supplier stopped delivering'})
    warehouse.post('/purchase-orders/receive',receipt)
    warehouse.post('/purchase-orders/receive',{'id':po,'item_id':item,'quantity':'1'},422)
    check('Close preserves stock and official PO status',quantity()==3 and scalar('SELECT status FROM purchase_orders WHERE id=?',[po])=='PartiallyReceived')
    _,closed,_=admin.request('/purchase-orders/show?id='+str(po));check('Closed PO reason visible and new receipt form absent','Supplier stopped delivering' in closed and 'action="/purchase-orders/receive"' not in closed)
    _,recommendations,_=warehouse.request('/replenishment?q='+sku);check('Replenishment shows reviewed PO shortcut','Review PO' in recommendations and 'warehouse_id=1' in recommendations)
    _,prefill,_=warehouse.request('/purchase-orders/create?product_id='+str(product)+'&warehouse_id=1&quantity=12');parsed=FormParser(prefill);check('PO shortcut prefills product warehouse quantity',parsed.values.get('product_id')==str(product) and parsed.values.get('warehouse_id')=='1' and parsed.values.get('quantity')=='12')
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':product,'quantity':'1','selling_price':'20'})
    rejected=int(scalar('SELECT MAX(id) FROM sales_orders'));sales.post('/sales-orders/submit',{'id':rejected})
    sales.post('/sales-orders/reject',{'id':rejected,'reason':'Forged reject'},403);admin.post('/sales-orders/reject',{'id':rejected,'reason':'Credit review incomplete'})
    _,detail,_=sales.request('/sales-orders/show?id='+str(rejected));check('Sales sees rejection reason with Cancelled status','Credit review incomplete' in detail and scalar('SELECT status FROM sales_orders WHERE id=?',[rejected])=='Cancelled')
    adjustment=operation(warehouse,'Adjustment',5,baseline='3');post(adjustment,422);warehouse.post('/inventory-operations/decide',{'id':adjustment,'decision':'Approved','reason':'Forged approval'},403);approve(adjustment);post(adjustment);post(adjustment);check('Adjustment posted once',quantity()==5 and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='ADJUSTMENT' AND reference_id=?",[adjustment]))==1)
    transfer=operation(warehouse,'Transfer',2,destination_id='2');approve(transfer);post(transfer);post(transfer);check('Transfer dispatched once: goods in transit',quantity()==3 and quantity(2)==0 and scalar('SELECT status FROM inventory_operations WHERE id=?',[transfer])=='Dispatched' and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='TRANSFER' AND reference_id=?",[transfer]))==1)
    receive(transfer);receive(transfer);check('Transfer receipt conserves total once',quantity()==3 and quantity(2)==2 and int(scalar("SELECT SUM(quantity_delta) FROM stock_ledger WHERE reference_type='TRANSFER' AND reference_id=?",[transfer]))==0 and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='TRANSFER' AND reference_id=?",[transfer]))==2)
    source=int(scalar("SELECT id FROM stock_ledger WHERE reference_type='PO' AND reference_id=?",[po]))
    returned=operation(warehouse,'SupplierReturn',1,source_ledger_id=str(source));approve(returned);post(returned);post(returned);check('Supplier return decreases stock once',quantity()==2)
    over=operation(warehouse,'SupplierReturn',3,source_ledger_id=str(source));approve(over);post(over,422);check('Supplier over-return leaves stock untouched',quantity()==2)
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':product,'quantity':'2','selling_price':'20'});so=int(scalar('SELECT MAX(id) FROM sales_orders'));sales.post('/sales-orders/submit',{'id':so});admin.post('/sales-orders/approve',{'id':so});warehouse.post('/sales-orders/issue',{'id':so})
    issue=int(scalar("SELECT id FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[so]))
    warehouse.post('/inventory-operations',{'kind':'CustomerReturn','source_ledger_id':issue,'quantity':'1','reason':'Missing condition confirmation'},422)
    customer=operation(warehouse,'CustomerReturn',1,source_ledger_id=str(issue),fit_for_stock='1');approve(customer);post(customer);post(customer);check('Fit customer return increases stock and preserves Fulfilled',quantity()==1 and scalar('SELECT status FROM sales_orders WHERE id=?',[so])=='Fulfilled')
    # D-12 / ADR-018: approval reserves (no movement), other decreases cannot cut into it, cancellation releases it.
    def reserved(warehouse_id=1):return int(scalar('SELECT reserved_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[product,warehouse_id]))
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':product,'quantity':str(quantity()),'selling_price':'20'});reserving=int(scalar('SELECT MAX(id) FROM sales_orders'));sales.post('/sales-orders/submit',{'id':reserving})
    ledger_rows=int(scalar('SELECT COUNT(*) FROM stock_ledger WHERE product_id=?',[product]));admin.post('/sales-orders/approve',{'id':reserving})
    check('Approval reserves without a ledger movement',reserved()==quantity()==1 and int(scalar('SELECT COUNT(*) FROM stock_ledger WHERE product_id=?',[product]))==ledger_rows and scalar('SELECT status FROM sales_orders WHERE id=?',[reserving])=='Approved')
    _,api,_=warehouse.request('/api/products/'+sku+'/availability');api=json.loads(api);check('Availability API adds reserved and available',api['total_quantity']==quantity()+quantity(2) and api['total_reserved']==1 and api['total_available']==api['total_quantity']-1)
    _,product_page,_=warehouse.request('/products/show?id='+str(product));check('Product detail shows reserved and available columns','<th>Reserved</th><th>Available</th>' in product_page)
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':product,'quantity':'1','selling_price':'20'});competing=int(scalar('SELECT MAX(id) FROM sales_orders'));sales.post('/sales-orders/submit',{'id':competing})
    code,body,_=admin.request('/sales-orders/approve',{'id':competing});check('Second approval refused with 422 when stock is reserved',code==422 and 'Insufficient available stock' in body and scalar('SELECT status FROM sales_orders WHERE id=?',[competing])=='PendingApproval' and reserved()==1)
    out=operation(warehouse,'Transfer',1,destination_id='2');approve(out);post(out,422);check('Transfer cannot cut into reserved stock',quantity()==1 and quantity(2)==2 and reserved()==1)
    admin.post('/sales-orders/cancel',{'id':reserving});check('Cancelling the Approved SO releases its reservation',reserved()==0 and scalar('SELECT status FROM sales_orders WHERE id=?',[reserving])=='Cancelled')
    _,timeline,_=admin.request('/timeline?kind=SO&id='+str(reserving));check('Timeline traces reservation and release','Reserved 1 unit at the source warehouse' in timeline and 'Released reservation of 1 unit' in timeline)
    post(out);check('Transfer dispatches after the release',quantity()==0 and quantity(2)==2);receive(out);check('Transfer received after the release',quantity(2)==3)
    back=operation(warehouse,'Transfer',1,warehouse_id='2',destination_id='1');approve(back);post(back);receive(back);check('Reverse transfer restores the earlier balances',quantity()==1 and quantity(2)==2)
    admin.post('/sales-orders/cancel',{'id':competing})
    # D-13 / ADR-019: negotiated SO prices (product purchase 10, master selling 20, Sales cap 10% -> lowest 18).
    count=lambda:int(scalar('SELECT COUNT(*) FROM sales_orders'))
    before=count();line={'customer_id':'1','warehouse_id':'1','product_id':product,'quantity':'2'}
    code,body,_=sales.request('/sales-orders',{**line,'selling_price':'18.50'});check('Different price without reason refused',code==422 and 'reason of 3' in body and count()==before)
    code,body,_=sales.request('/sales-orders',{**line,'selling_price':'17','price_reason':'Diskon terlalu besar'});check('Sales discount above the cap refused',code==422 and 'at most 10%' in body and count()==before)
    code,body,_=admin.request('/sales-orders',{**line,'selling_price':'9.99','price_reason':'Cuci gudang'});check('Below-cost price refused even for Admin',code==422 and 'below its current purchase price' in body and count()==before)
    sales.post('/sales-orders',{**line,'selling_price':'19','price_reason':'Promo akhir bulan HTTP','list_price':'19'});negotiated=int(scalar('SELECT MAX(id) FROM sales_orders'))
    stored=scalar("SELECT CONCAT(selling_price,'|',list_price,'|',price_reason) FROM sales_order_items WHERE sales_order_id=?",[negotiated]);check('Negotiated price stored with server list price and reason',stored=='19.00|20.00|Promo akhir bulan HTTP',stored)
    sales.post('/sales-orders/submit',{'id':negotiated});_,detail,_=admin.request('/sales-orders/show?id='+str(negotiated))
    check('Approval page shows override and notice','-5,00% · -Rp 1,00 per unit' in detail and 'Promo akhir bulan HTTP' in detail and '1 line priced away from master; total difference -Rp 2,00' in detail)
    _,other,_=Client().request('/sales-orders/show?id='+str(negotiated));check('Anonymous detail request is not served',('Promo akhir bulan HTTP' not in other))
    admin.post('/sales-orders/cancel',{'id':negotiated})
    # D-14 / ADR-020: reserved = unissued remainder of Approved/PartiallyFulfilled orders without a closed remainder.
    invariant="SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT so.source_warehouse_id w,soi.product_id p,SUM(soi.quantity-soi.issued_quantity) r FROM sales_orders so JOIN sales_order_items soi ON soi.sales_order_id=so.id LEFT JOIN sales_order_closures c ON c.sales_order_id=so.id WHERE so.status IN ('Approved','PartiallyFulfilled') AND c.sales_order_id IS NULL GROUP BY so.source_warehouse_id,soi.product_id) e ON e.p=ps.product_id AND e.w=ps.warehouse_id WHERE ps.product_id=? AND ps.reserved_quantity<>COALESCE(e.r,0)"
    check('Reservation invariant holds for this product',int(scalar(invariant,[product]))==0)
    # D-14 / ADR-020: partial shipment of a separate product, customer return bounded by that Issue row, work queue,
    # outstanding report, closure and timeline.
    psku=prefix+'-PARTIAL';admin.post('/products',{'sku':psku,'name':prefix+' partial','unit':'pcs','purchase_price':'10','selling_price':'20','reorder_point':'1','category_id':'1'})
    pproduct=int(scalar('SELECT id FROM products WHERE sku=?',[psku]))
    def pstate():
        row=db('SELECT quantity, reserved_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=1',[pproduct])[0];return [int(row['quantity']),int(row['reserved_quantity'])]
    admin.post('/purchase-orders',{'supplier_id':'1','warehouse_id':'1','product_id':pproduct,'quantity':'10','purchase_price':'10'});ppo=int(scalar('SELECT MAX(id) FROM purchase_orders'));admin.post('/purchase-orders/order',{'id':ppo})
    warehouse.post('/purchase-orders/receive',{'id':ppo,'item_id':int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[ppo])),'quantity':'10'})
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':pproduct,'quantity':'6','selling_price':'20'});pso=int(scalar('SELECT MAX(id) FROM sales_orders'));pnumber=scalar('SELECT order_number FROM sales_orders WHERE id=?',[pso])
    sales.post('/sales-orders/submit',{'id':pso});admin.post('/sales-orders/approve',{'id':pso});pline=int(scalar('SELECT id FROM sales_order_items WHERE sales_order_id=?',[pso]))
    warehouse.post('/sales-orders/issue',{'id':pso,'quantities['+str(pline)+']':'4'},label='Partial shipment of 4 of 6')
    check('PartiallyFulfilled keeps 2 reserved',scalar('SELECT status FROM sales_orders WHERE id=?',[pso])=='PartiallyFulfilled' and pstate()==[6,2],pstate())
    _,queue,_=warehouse.request('/work-queue?type=Issue&q='+urllib.parse.quote(pnumber));check('Work queue lists the remaining issue',pnumber in queue and 'Issue remaining goods' in queue)
    code,outstanding,_=warehouse.request('/reports/outstanding.csv?document=SO')
    prow=[row for row in csv.reader(io.StringIO(outstanding)) if len(row)>1 and row[1]==pnumber]
    check('Outstanding report shows 2 unshipped units',code==200 and len(prow)==1 and prow[0][3]=='PartiallyFulfilled' and prow[0][-1]=='2',prow)
    pissue=int(scalar("SELECT id FROM stock_ledger WHERE reference_type='SO' AND reference_id=? AND movement_type='Issue'",[pso]))
    preturn=operation(warehouse,'CustomerReturn',3,source_ledger_id=str(pissue),fit_for_stock='1');approve(preturn);post(preturn)
    pover=operation(warehouse,'CustomerReturn',2,source_ledger_id=str(pissue),fit_for_stock='1');approve(pover);post(pover,422)
    check('Customer return is bounded by the partial Issue row',pstate()==[9,2] and int(scalar('SELECT returned_quantity FROM inventory_return_totals WHERE source_ledger_id=?',[pissue]))==3,pstate())
    admin.post('/sales-orders/close-remaining',{'id':pso,'reason':'Customer refused the rest'},label='Admin closes the unshipped remainder')
    check('Closure released the remainder',pstate()==[9,0] and scalar('SELECT status FROM sales_orders WHERE id=?',[pso])=='PartiallyFulfilled' and int(scalar(invariant,[pproduct]))==0,pstate())
    _,queue,_=warehouse.request('/work-queue?type=Issue&q='+urllib.parse.quote(pnumber));check('Closed remainder leaves the work queue','Issue remaining goods' not in queue and 'href="/sales-orders/show?id='+str(pso)+'"' not in queue)
    _,ptimeline,_=sales.request('/timeline?kind=SO&id='+str(pso));check('Timeline shows shipment and closure','Goods issued (shipment)' in ptimeline and 'Shipped 4 units; 2 units still to ship' in ptimeline and 'SO remaining fulfilment closed' in ptimeline and 'Customer refused the rest' in ptimeline)
    # D-15 / ADR-021: goods in transit with damage and shortage on receipt, separate product so the totals above stay valid.
    tsku=prefix+'-TRANSIT';admin.post('/products',{'sku':tsku,'name':prefix+' transit','unit':'pcs','purchase_price':'10','selling_price':'20','reorder_point':'15','category_id':'1'})
    tproduct=int(scalar('SELECT id FROM products WHERE sku=?',[tsku]))
    def tq(warehouse_id):return int(scalar('SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?',[tproduct,warehouse_id]))
    admin.post('/purchase-orders',{'supplier_id':'1','warehouse_id':'1','product_id':tproduct,'quantity':'40','purchase_price':'10'});tpo=int(scalar('SELECT MAX(id) FROM purchase_orders'));admin.post('/purchase-orders/order',{'id':tpo})
    warehouse.post('/purchase-orders/receive',{'id':tpo,'item_id':int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[tpo])),'quantity':'40'})
    treason=prefix+' transit '+secrets.token_hex(4);warehouse.post('/inventory-operations',{'kind':'Transfer','warehouse_id':'1','destination_id':'2','product_id':tproduct,'quantity':'6','reason':treason})
    tr=int(scalar('SELECT id FROM inventory_operations WHERE reason=?',[treason]));approve(tr)
    _,tpage,_=warehouse.request('/inventory-operations/show?id='+str(tr));check('Approved transfer offers Dispatch, not Post','Dispatch Transfer' in tpage and 'Post Stock Changes' not in tpage)
    _,queue,_=warehouse.request('/work-queue?type=TransferDispatch&q='+urllib.parse.quote('Transfer #'+str(tr)+' '));check('Work queue lists the dispatch task','Dispatch approved transfer' in queue)
    sales.post('/inventory-operations/dispatch',{'id':tr},403,label='Sales cannot dispatch')
    warehouse.post('/inventory-operations/dispatch',{'id':tr},label='Source warehouse dispatches the transfer')
    warehouse.post('/inventory-operations/dispatch',{'id':tr},label='Repeated dispatch is a no-op')
    check('Dispatch moves 6 units into transit once',[tq(1),tq(2)]==[34,0] and scalar('SELECT status FROM inventory_operations WHERE id=?',[tr])=='Dispatched' and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='TRANSFER' AND reference_id=?",[tr]))==1,[tq(1),tq(2)])
    _,tpage,_=warehouse.request('/inventory-operations/show?id='+str(tr));check('Transfer detail shows in-transit badge and receive form','In transit (Dispatched)' in tpage and 'name="received['+str(tproduct)+']"' in tpage and 'Cancel Proposal' not in tpage)
    _,tlist,_=warehouse.request('/inventory-operations/in-transit');check('In Transit page lists the open transfer','Transfer #'+str(tr) in tlist)
    check('Sales cannot open the In Transit page',sales.request('/inventory-operations/in-transit')[0]==403)
    _,ppage,_=warehouse.request('/products/show?id='+str(tproduct));check('Product detail shows inbound in transit separately','In Transit (inbound)' in ppage and '<dt>In Transit</dt><dd>6 pcs</dd>' in ppage)
    _,rec,_=warehouse.request('/replenishment?warehouse_id=2&q='+tsku);check('Replenishment subtracts goods in transit','+ 6 pcs in transit' in rec)
    _,queue,_=warehouse.request('/work-queue?type=TransferReceive&q='+urllib.parse.quote('Transfer #'+str(tr)+' '));check('Work queue lists the receive task','Receive transfer' in queue)
    code,body,_=warehouse.request('/inventory-operations/decide',{'id':tr,'decision':'Cancelled','reason':'Too late'});check('Dispatched transfer cannot be cancelled',code==422 and 'cannot be cancelled' in body)
    code,body,_=warehouse.request('/inventory-operations/receive',{'id':tr,'received['+str(tproduct)+']':'4','damaged['+str(tproduct)+']':'1','receipt_reason':''});check('Discrepancy without reason refused',code==422 and 'Enter a reason for the damaged or missing units' in body and tq(2)==0)
    code,body,_=warehouse.request('/inventory-operations/receive',{'id':tr,'received['+str(tproduct)+']':'6','damaged['+str(tproduct)+']':'1','receipt_reason':'x'});check('Good plus damaged above dispatched refused',code==422 and 'cannot exceed' in body and tq(2)==0)
    receive(tr,{tproduct:(4,1)},'Satu karton penyok, satu karton hilang')
    receive(tr,{tproduct:(6,0)},'')
    row=db('SELECT received_quantity r, damaged_quantity d, short_quantity s FROM inventory_operation_items WHERE operation_id=?',[tr])[0]
    check('Receipt: 4 good enter stock, 1 damaged and 1 short written off, once',[tq(1),tq(2)]==[34,4] and [int(row['r']),int(row['d']),int(row['s'])]==[4,1,1] and scalar('SELECT status FROM inventory_operations WHERE id=?',[tr])=='Received',[tq(1),tq(2),row])
    _,tpage,_=warehouse.request('/inventory-operations/show?id='+str(tr));check('Detail shows discrepancy and reason','Received with discrepancy' in tpage and 'Satu karton penyok, satu karton hilang' in tpage)
    _,ttimeline,_=admin.request('/timeline?kind=Operation&id='+str(tr));check('Timeline shows dispatch, receipt and write-off','Transfer dispatched (in transit)' in ttimeline and 'Received 4 good units; written off 1 damaged and 1 short' in ttimeline and 'transfer out' in ttimeline and 'transfer in' in ttimeline)
    tbalance=int(scalar("SELECT SUM(CASE WHEN movement_type='Receipt' THEN quantity WHEN movement_type='Issue' THEN -quantity ELSE quantity_delta END) FROM stock_ledger WHERE product_id=?",[tproduct]));check('Stock equals ledger; write-off is not a movement',tbalance==tq(1)+tq(2)==38)
    # D-16 / ADR-022: units of measure. Stock stays in pcs; PO in karton, SO in karton and pcs, operations in karton.
    usku=prefix+'-UNITS';admin.post('/products',{'sku':usku,'name':prefix+' units (24 pcs)','unit':'pcs','purchase_price':'1000','selling_price':'1500','reorder_point':'100','category_id':'1'})
    uproduct=int(scalar('SELECT id FROM products WHERE sku=?',[usku]))
    def ustate():
        row=db('SELECT quantity, reserved_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=1',[uproduct])[0];return [int(row['quantity']),int(row['reserved_quantity'])]
    check('New product has exactly one base unit pcs',db('SELECT unit_code, factor_to_base, is_base FROM product_units WHERE product_id=?',[uproduct])==[{'unit_code':'pcs','factor_to_base':1,'is_base':1}])
    sales.post('/products/units',{'id':uproduct,'unit_code':'karton','factor_to_base':'24'},403,label='Sales cannot add units')
    warehouse.post('/products/units',{'id':uproduct,'unit_code':'karton','factor_to_base':'24'},403,label='Warehouse cannot add units')
    code,body,_=admin.request('/products/units',{'id':uproduct,'unit_code':'karton','factor_to_base':'1'});check('Factor 1 for a non-base unit refused',code==422 and 'from 2 to' in body)
    code,body,_=admin.request('/products/units',{'id':uproduct,'unit_code':'karton','factor_to_base':'24','barcode':'8991000202615'});check('Invalid EAN-13 check digit refused',code==422 and 'check digit' in body)
    admin.post('/products/units',{'id':uproduct,'unit_code':'karton','factor_to_base':'24','purchase_price':'22000','selling_price':'30000','is_purchase_default':'1','is_sales_default':'1'},label='Admin adds karton (24 pcs) with own prices and defaults')
    admin.post('/products/units',{'id':uproduct,'unit_code':'box','factor_to_base':'6'},label='Admin adds box (6 pcs) priced base x factor')
    _,upage,_=sales.request('/products/show?id='+str(uproduct))
    check('Product page lists units with factors and derived prices','1 karton = 24 pcs' in upage and '1 box = 6 pcs' in upage and 'Rp 9.000,00 (base × factor)' in upage and 'action="/products/units' not in upage)
    check('Each successful unit change is audited once by the service',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='products.units' AND entity_id=? AND status='success'",[uproduct]))==2)
    _,form,_=warehouse.request('/purchase-orders/create')
    check('PO form offers units per product with the purchase default',('data-default-unit="karton"' in form) and 'name="unit_code"' in form)
    warehouse.post('/purchase-orders',{'supplier_id':'1','warehouse_id':'1','product_id':uproduct,'quantity':'4','unit_code':'karton'},label='PO in karton at the karton master price')
    upo=int(scalar('SELECT MAX(id) FROM purchase_orders'));uline=db('SELECT id, quantity, unit_quantity, unit_code, unit_factor, purchase_price, list_price FROM purchase_order_items WHERE purchase_order_id=?',[upo])[0]
    check('PO line stores base quantity and the unit snapshot',[int(uline['quantity']),int(uline['unit_quantity']),uline['unit_code'],int(uline['unit_factor']),uline['purchase_price'],uline['list_price']]==[96,4,'karton',24,'22000.00','22000.00'],uline)
    admin.post('/purchase-orders/order',{'id':upo})
    warehouse.post('/purchase-orders/receive',{'id':upo,'item_id':uline['id'],'quantity':'3'},label='Receive 3 karton')
    code,body,_=warehouse.request('/purchase-orders/receive',{'id':upo,'item_id':uline['id'],'quantity':'2'});check('Receipt above the remaining karton refused',code==422 and 'cannot exceed remaining' in body)
    _,podetail,_=warehouse.request('/purchase-orders/show?id='+str(upo))
    check('Receipt of 3 karton adds 72 pcs and the detail shows units and totals',ustate()==[72,0] and '4 karton (96 pcs)' in podetail and '3 karton (72 pcs)' in podetail and 'Rp 88.000,00' in podetail,ustate())
    code,body,_=sales.request('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':uproduct,'quantity':'1','unit_code':'karton','selling_price':'26999','price_reason':'Diskon volume'});check('Sales discount cap applies per karton',code==422 and 'at most 10%' in body)
    code,body,_=admin.request('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':uproduct,'quantity':'1','unit_code':'karton','selling_price':'23999','price_reason':'Cuci gudang'});check('Cost floor per karton = 24 x base purchase price',code==422 and 'below its current purchase price' in body)
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':uproduct,'quantity':'2','unit_code':'karton','selling_price':'27000','price_reason':'Diskon volume 10%'},label='SO in karton within the cap')
    uso=int(scalar('SELECT MAX(id) FROM sales_orders'));usline=int(scalar('SELECT id FROM sales_order_items WHERE sales_order_id=?',[uso]))
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':uproduct,'quantity':'5','unit_code':'pcs'},label='SO in pieces for a minimarket')
    upcs=int(scalar('SELECT MAX(id) FROM sales_orders'))
    check('Piece line priced per piece',db('SELECT quantity, unit_code, selling_price FROM sales_order_items WHERE sales_order_id=?',[upcs])==[{'quantity':5,'unit_code':'pcs','selling_price':'1500.00'}])
    sales.post('/sales-orders/submit',{'id':uso});admin.post('/sales-orders/approve',{'id':uso})
    check('Approval reserves 48 pcs (2 karton)',ustate()==[72,48],ustate())
    warehouse.post('/sales-orders/issue',{'id':uso,'quantities['+str(usline)+']':'1'},label='Ship 1 of 2 karton')
    code,body,_=warehouse.request('/sales-orders/issue',{'id':uso,'quantities['+str(usline)+']':'2'});check('Shipping more karton than remain refused',code==422 and '1 left on line' in body)
    check('Partial issue in karton moves 24 pcs',ustate()==[48,24] and int(scalar("SELECT quantity FROM stock_ledger WHERE reference_type='SO' AND reference_id=? AND movement_type='Issue'",[uso]))==24,ustate())
    _,sodetail,_=sales.request('/sales-orders/show?id='+str(uso));check('SO detail shows the line unit, per-unit price and Rupiah total','2 karton (48 pcs)' in sodetail and 'per karton' in sodetail and 'Rp 54.000,00' in sodetail)
    karton=int(scalar("SELECT id FROM product_units WHERE product_id=? AND unit_code='karton'",[uproduct]))
    code,body,_=admin.request('/products/units/update',{'id':uproduct,'unit_id':karton,'unit_code':'karton','factor_to_base':'12','is_active':'1'});check('Factor of a used unit cannot change',code==422 and 'Deactivate it and add a new unit' in body)
    code,body,_=admin.request('/api/products/'+usku+'/availability',fetch=True);api=json.loads(body)
    check('Availability API keeps base fields and adds units',code==200 and api['total_quantity']==48 and api['base_unit']=='pcs' and [u['unit'] for u in api['units']]==['karton','box','pcs'] and api['units'][0]['factor_to_base']==24,api)
    _,rec,_=warehouse.request('/replenishment?warehouse_id=1&q='+usku)
    check('Replenishment rounds 52 pcs (100 - 24 available - 24 open PO) up to 3 karton',str(uproduct)+':3:karton' in rec and '3 karton' in rec and 'need 52 pcs' in rec)
    code,prefill,_=warehouse.request('/purchase-orders/create?warehouse_id=1&pick[]='+str(uproduct)+'%3A3%3Akarton')
    check('Replenishment prefill selects karton and 3 units',code==200 and re.search(r'name="unit_code"[^>]*>\s*<option value="karton" data-factor="24" data-price="22000.00" selected',prefill) is not None and 'name="quantity" type="number" min="1" required value="3"' in prefill)
    ureason=prefix+' units transfer';warehouse.post('/inventory-operations',{'kind':'Transfer','warehouse_id':'1','destination_id':'2','product_id':uproduct,'quantity':'1','unit_code':'karton','reason':ureason},label='Transfer proposed in karton')
    uop=int(scalar('SELECT id FROM inventory_operations WHERE reason=?',[ureason]))
    check('Operation line stores 24 pcs with the karton snapshot',db('SELECT quantity, unit_code, unit_factor, unit_quantity FROM inventory_operation_items WHERE operation_id=?',[uop])==[{'quantity':24,'unit_code':'karton','unit_factor':24,'unit_quantity':1}])
    check('Unit invariants hold',int(scalar('SELECT (SELECT COUNT(*) FROM purchase_order_items WHERE quantity<>unit_quantity*unit_factor)+(SELECT COUNT(*) FROM sales_order_items WHERE quantity<>unit_quantity*unit_factor)+(SELECT COUNT(*) FROM inventory_operation_items WHERE quantity<>unit_quantity*unit_factor)'))==0)
    self_approval=operation(admin,'Adjustment',2,baseline='1');admin.post('/inventory-operations/decide',{'id':self_approval,'decision':'Approved','reason':'Self approval'},403)
    reject_proposal=operation(warehouse,'Transfer',1,destination_id='2');admin.post('/inventory-operations/decide',{'id':reject_proposal,'decision':'Rejected','reason':'Not needed'});post(reject_proposal,422)
    cancel_proposal=operation(warehouse,'Transfer',1,destination_id='2');warehouse.post('/inventory-operations/decide',{'id':cancel_proposal,'decision':'Cancelled','reason':'Count changed'});post(cancel_proposal,422)
    # Check aggregate delta equals stock for this newly created, zero-start catalog product.
    balance=int(scalar("SELECT SUM(CASE WHEN movement_type='Receipt' THEN quantity WHEN movement_type='Issue' THEN -quantity ELSE quantity_delta END) FROM stock_ledger WHERE product_id=?",[product]));check('All new movements reconcile with both warehouse balances',balance==quantity()+quantity(2))
    status,csv_body,_=warehouse.request('/reports/stock-ledger.csv');check('Signed Adjustment exported in stock report',status==200 and 'Adjustment' in csv_body and 'TRANSFER' in csv_body and 'SUPPLIER_RETURN' in csv_body and 'CUSTOMER_RETURN' in csv_body)
    for path in ['/inventory-operations','/inventory-operations?kind=Transfer&status=Received','/inventory-operations?kind=Transfer&status=Dispatched','/inventory-operations/in-transit','/inventory-operations/create','/inventory-operations/show?id='+str(adjustment),'/replenishment']:
        code,body,_=admin.request(path);check('New page renders '+path,code==200 and 'Warning:' not in body and 'Fatal error' not in body)
    check('Successful operation replay does not duplicate audit',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='inventory-operations.post' AND entity_id=? AND status='success'",[adjustment]))==1 and int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='inventory-operations.dispatch' AND entity_id=? AND status='success'",[transfer]))==1 and int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='inventory-operations.receive' AND entity_id=? AND status='success'",[transfer]))==1)
    check('Invalid stock filters rejected',admin.request('/inventory-operations?status=Invented')[0]==422)
    # D-17 / ADR-023: every movement above has a lot; returns use the original movement's lot; counts are per lot.
    check('Every new ledger row of the product has a lot',int(scalar('SELECT COUNT(*) FROM stock_ledger WHERE product_id=? AND lot_id IS NULL',[product]))==0)
    check('Customer return went back to the lot of the original issue',scalar('SELECT lot_id FROM stock_ledger WHERE reference_type=? AND reference_id=?',['CUSTOMER_RETURN',customer])==scalar('SELECT lot_id FROM stock_ledger WHERE id=?',[issue]))
    check('Supplier return took units from the original receipt lot',scalar("SELECT lot_id FROM stock_ledger WHERE reference_type='SUPPLIER_RETURN' AND reference_id=?",[returned])==scalar('SELECT lot_id FROM stock_ledger WHERE id=?',[source]))
    lot=db('SELECT id, quantity FROM stock_lots WHERE product_id=? AND warehouse_id=1 AND quantity>0 ORDER BY id LIMIT 1',[product])[0]
    code,balance_json,_=warehouse.request('/inventory-operations/balance?product_id='+str(product)+'&warehouse_id=1',fetch=True);balance_data=json.loads(balance_json)
    check('Balance endpoint lists the lots for the count form',code==200 and any(int(row['id'])==int(lot['id']) for row in balance_data['lots']))
    code,_,_=warehouse.request('/inventory-operations',{'kind':'Adjustment','warehouse_id':'1','product_id':product,'quantity':str(int(lot['quantity'])+1),'baseline':str(int(lot['quantity'])+5),'lot_id':str(lot['id']),'reason':prefix+' wrong lot baseline'})
    check('A lot count against a stale baseline is refused (409)',code==409,code)
    counted=operation(warehouse,'Adjustment',int(lot['quantity'])+1,baseline=str(lot['quantity']),lot_id=str(lot['id']))
    approve(counted);post(counted)
    check('Count of one lot changes only that lot, one ledger row',int(scalar('SELECT quantity FROM stock_lots WHERE id=?',[lot['id']]))==int(lot['quantity'])+1 and db("SELECT lot_id, quantity_delta FROM stock_ledger WHERE reference_type='ADJUSTMENT' AND reference_id=?",[counted])==[{'lot_id':int(lot['id']),'quantity_delta':1}])
    found=operation(warehouse,'Adjustment',2,baseline='0',lot_id='new',lot_code=prefix[-12:]+'-FOUND',expiry_date='')
    approve(found);post(found)
    check('Units found at a count become a new lot at the current purchase price',db('SELECT quantity, unit_cost FROM stock_lots WHERE product_id=? AND lot_code=?',[product,prefix[-12:]+'-FOUND'])==[{'quantity':2,'unit_cost':'10.00'}])
    check('Pairs equal their lots and lots equal their ledger',int(scalar('SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(quantity) q FROM stock_lots GROUP BY product_id, warehouse_id) l ON l.product_id=ps.product_id AND l.warehouse_id=ps.warehouse_id WHERE ps.product_id=? AND ps.quantity<>COALESCE(l.q,0)',[product]))==0 and int(scalar("SELECT COUNT(*) FROM stock_lots l LEFT JOIN (SELECT lot_id, SUM(CASE movement_type WHEN 'Receipt' THEN CAST(quantity AS SIGNED) WHEN 'Issue' THEN -CAST(quantity AS SIGNED) ELSE quantity_delta END) m FROM stock_ledger WHERE lot_id IS NOT NULL GROUP BY lot_id) x ON x.lot_id=l.id WHERE l.product_id=? AND CAST(l.quantity AS SIGNED)<>CAST(l.opening_quantity AS SIGNED)+COALESCE(x.m,0)",[product]))==0)
    _,shown,_=admin.request('/inventory-operations/show?id='+str(found));check('Operation detail names the new lot','New lot '+prefix[-12:]+'-FOUND' in shown)
    # D-18 / ADR-024: barcode lookup API and a stock count session with stock moving between snapshot, count and posting.
    code_row=db('SELECT u.barcode, u.unit_code, u.factor_to_base, p.sku FROM product_units u JOIN products p ON p.id=u.product_id WHERE u.barcode IS NOT NULL AND u.is_base=0 AND u.is_active=1 AND p.is_active=1 ORDER BY u.id LIMIT 1')[0]
    code,body,headers=warehouse.request('/api/barcodes/'+code_row['barcode'],fetch=True);scanned=json.loads(body)
    check('Barcode lookup resolves product, unit and factor',code==200 and 'application/json' in headers.get('Content-Type','') and scanned['product']['sku']==code_row['sku'] and scanned['unit']['code']==code_row['unit_code'] and scanned['unit']['factor_to_base']==int(code_row['factor_to_base']) and scanned['usable'] is True,scanned)
    check('Barcode lookup: Sales 403, anonymous 401, unknown 404, wrong check digit 422',[sales.request('/api/barcodes/'+code_row['barcode'],fetch=True)[0],Client().request('/api/barcodes/'+code_row['barcode'],fetch=True)[0],warehouse.request('/api/barcodes/0000000000000',fetch=True)[0],warehouse.request('/api/barcodes/8991000202613',fetch=True)[0]]==[403,401,404,422])
    warehouse2=Client();warehouse2.login('warehouse2@example.test')
    csku=prefix+'-COUNT';admin.post('/products',{'sku':csku,'name':prefix+' count','unit':'pcs','purchase_price':'10','selling_price':'20','reorder_point':'1','category_id':'1'})
    cproduct=int(scalar('SELECT id FROM products WHERE sku=?',[csku]))
    admin.post('/purchase-orders',{'supplier_id':'1','warehouse_id':'1','product_id':cproduct,'quantity':'30','purchase_price':'10'});cpo=int(scalar('SELECT MAX(id) FROM purchase_orders'));admin.post('/purchase-orders/order',{'id':cpo})
    _,cpodetail,_=warehouse.request('/purchase-orders/show?id='+str(cpo));check('PO detail offers scan to receive with line data','data-scan-receive' in cpodetail and 'data-scan-line=' in cpodetail and 'data-unit-factor="1"' in cpodetail and 'aria-live="polite"' in cpodetail)
    warehouse.post('/purchase-orders/receive',{'id':cpo,'item_id':int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[cpo])),'quantity':'30','lot_code[0]':'CNT-A','expiry_date[0]':'','lot_quantity[0]':'20','lot_code[1]':'CNT-B','expiry_date[1]':'','lot_quantity[1]':'10'},label='Count product received in lots CNT-A 20 and CNT-B 10')
    lot_a=int(scalar("SELECT id FROM stock_lots WHERE product_id=? AND lot_code='CNT-A'",[cproduct]));lot_b=int(scalar("SELECT id FROM stock_lots WHERE product_id=? AND lot_code='CNT-B'",[cproduct]))
    check('Sales cannot open stock counts',sales.request('/stock-counts')[0]==403 and sales.request('/reports/stock-counts')[0]==403)
    warehouse.post('/stock-counts',{'warehouse_id':'1','scope':'Products','product_ids[]':str(cproduct),'counter_ids[]':'4','reason':'x'},403,label='Warehouse cannot create a count')
    creason=prefix+' cycle count'
    admin.post('/stock-counts',{'warehouse_id':'1','scope':'Products','product_ids[]':str(cproduct),'counter_ids[0]':'4','counter_ids[1]':'5','reason':creason},label='Admin creates a count with two counters')
    count=int(scalar('SELECT id FROM stock_counts WHERE reason=?',[creason]))
    warehouse.post('/stock-counts/start',{'id':count},403,label='Warehouse cannot start a count')
    admin.post('/stock-counts/start',{'id':count},label='Admin starts the count (snapshot)')
    line_a=int(scalar('SELECT id FROM stock_count_lines WHERE count_id=? AND lot_id=?',[count,lot_a]));line_b=int(scalar('SELECT id FROM stock_count_lines WHERE count_id=? AND lot_id=?',[count,lot_b]))
    _,blind,_=warehouse.request('/stock-counts/show?id='+str(count));check('Counter sees a blind count sheet with scan support','data-scan-count' in blind and 'Blind count' in blind and 'data-expected' not in blind and 'counts['+str(line_a)+'][quantity]' in blind)
    _,review,_=admin.request('/stock-counts/show?id='+str(count));check('Reviewer sees expected quantities','data-expected' in review and 'counts['+str(line_a)+'][quantity]' not in review)
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':cproduct,'quantity':'5'});cso=int(scalar('SELECT MAX(id) FROM sales_orders'));sales.post('/sales-orders/submit',{'id':cso});admin.post('/sales-orders/approve',{'id':cso})
    _,csodetail,_=warehouse.request('/sales-orders/show?id='+str(cso));check('SO detail offers scan to pick with line data','data-scan-pick' in csodetail and 'data-pick-line=' in csodetail and 'Start scan to pick' in csodetail)
    warehouse.post('/sales-orders/issue',{'id':cso},label='5 units ship between the snapshot and the count (FEFO from CNT-A)')
    warehouse.post('/stock-counts/count',{'id':count,'counts['+str(line_a)+'][quantity]':'13','counts['+str(line_a)+'][unit]':'pcs','counts['+str(line_b)+'][quantity]':'10','counts['+str(line_b)+'][unit]':'pcs'},label='Counter saves the counts')
    row=db('SELECT snapshot_quantity s, expected_quantity e, variance v FROM stock_count_lines WHERE id=?',[line_a])[0]
    check('Expected = snapshot + movements between snapshot and count; variance = counted - expected',[int(row['s']),int(row['e']),int(row['v'])]==[20,15,-2],row)
    warehouse.post('/stock-counts/found-lot',{'id':count,'product_id':cproduct,'lot_code':'CNT-FOUND','expiry_date':'','quantity':'2','unit_code':''},label='Counter enters a found lot')
    warehouse.post('/stock-counts/submit',{'id':count},label='Counter submits the count')
    _,queue,_=admin.request('/work-queue?type=CountReview');check('Work queue lists the count to review','Stock count #'+str(count) in queue and 'Review variances' in queue)
    warehouse2.post('/stock-counts/recount',{'id':count,'line_ids[]':str(line_a),'reason':'x'},403,label='Warehouse cannot request a recount')
    admin.post('/stock-counts/recount',{'id':count,'line_ids[]':str(line_a),'reason':'Two missing: recount CNT-A'},label='Admin requests a recount of CNT-A')
    code,body,_=warehouse.request('/stock-counts/count',{'id':count,'counts['+str(line_a)+'][quantity]':'14','counts['+str(line_a)+'][unit]':'pcs'});check('The first counter cannot recount the line',code==422 and 'different counter' in body)
    _,queue,_=warehouse2.request('/work-queue?type=CountToDo');check('Work queue lists the recount for the second counter','Stock count #'+str(count) in queue and 'Recount flagged lines' in queue)
    warehouse2.post('/stock-counts/count',{'id':count,'counts['+str(line_a)+'][quantity]':'12','counts['+str(line_a)+'][unit]':'pcs'},label='Second counter recounts CNT-A')
    warehouse2.post('/stock-counts/submit',{'id':count},label='Second counter submits the recount')
    admin.post('/stock-counts/approve',{'id':count,'note':'Recount confirmed'},label='Admin who did not count approves')
    sales.post('/sales-orders',{'customer_id':'1','warehouse_id':'1','product_id':cproduct,'quantity':'25'});rso=int(scalar('SELECT MAX(id) FROM sales_orders'));sales.post('/sales-orders/submit',{'id':rso});admin.post('/sales-orders/approve',{'id':rso})
    code,body,_=warehouse.request('/stock-counts/post',{'id':count})
    check('Posting that would go below the reservation is refused (422) and lists the line',code==422 and 'reserved' in body and 'line #'+str(line_a) in body and int(scalar('SELECT quantity FROM stock_lots WHERE id=?',[lot_a]))==15 and scalar('SELECT status FROM stock_counts WHERE id=?',[count])=='Approved',code)
    admin.post('/sales-orders/cancel',{'id':rso});warehouse.post('/stock-counts/post',{'id':count},label='Count posts after the reservation is released')
    lots=db('SELECT lot_code, quantity, source_type, unit_cost FROM stock_lots WHERE product_id=? AND warehouse_id=1 ORDER BY lot_seq',[cproduct])
    check('Posting applies the variances to the current lots and creates the found lot',[(r['lot_code'],int(r['quantity'])) for r in lots]==[('CNT-A',12),('CNT-B',10),('CNT-FOUND',2)] and lots[2]['source_type']=='STOCK_COUNT' and lots[2]['unit_cost']=='10.00',lots)
    check('Ledger delta per lot equals the approved variance',sorted((int(r['lot_id']) if int(r['lot_id']) in (lot_a,lot_b) else 0,int(r['d'])) for r in db("SELECT lot_id, SUM(quantity_delta) d FROM stock_ledger WHERE reference_type='STOCK_COUNT' AND reference_id=? GROUP BY lot_id",[count]))==[(0,2),(lot_a,-3)])
    check('Stock equals ledger and lots after the count',int(scalar("SELECT quantity FROM product_stocks WHERE product_id=? AND warehouse_id=1",[cproduct]))==24==int(scalar("SELECT SUM(CASE movement_type WHEN 'Receipt' THEN quantity WHEN 'Issue' THEN -CAST(quantity AS SIGNED) ELSE quantity_delta END) FROM stock_ledger WHERE product_id=? AND warehouse_id=1",[cproduct])))
    _,trace,_=warehouse.request('/lots/show?id='+str(lot_a));check('Lot trace shows the count variance','Stock count #'+str(count)+' variance' in trace)
    status,ledger_csv,_=warehouse.request('/reports/stock-ledger.csv');check('Stock movements export includes the count adjustment',status==200 and 'STOCK_COUNT' in ledger_csv)
    status,report,_=warehouse.request('/reports/stock-counts');check('Count report lists the posted count','Count #'+str(count) in report and 'data-count-report' in report)
    status,report_csv,headers=admin.request('/reports/stock-counts.csv');rows=[r for r in csv.reader(io.StringIO(report_csv)) if len(r)>3 and r[3]==csku]
    check('Count report CSV has the variance lines with value',status==200 and 'text/csv' in headers.get('Content-Type','') and sorted(int(r[12]) for r in rows)==[-3,2] and '-30.00' in [r[15] for r in rows],rows)
    check('No count was approved by a counter',int(scalar('SELECT COUNT(*) FROM stock_counts c WHERE c.approved_by IS NOT NULL AND EXISTS (SELECT 1 FROM stock_count_lines l WHERE l.count_id=c.id AND (l.counted_by=c.approved_by OR l.recounted_by=c.approved_by))'))==0)
    for path in ['/stock-counts','/stock-counts/create','/stock-counts/show?id='+str(count),'/reports/stock-counts','/stock-counts?status=Posted&warehouse_id=1&mine=1']:
        code,body,_=admin.request(path);check('Count page renders '+path,code==200 and 'Warning:' not in body and 'Fatal error' not in body)
    check('Invalid count filter rejected',admin.request('/stock-counts?status=Invented')[0]==422)
    # D-19 / ADR-025: customer price lists with tiers, quotations, delivery notes (surat jalan) and invoices with PPN.
    other_sales=Client();other_sales.login('sales2@example.test')
    psku=prefix+'-PRICE';admin.post('/products',{'sku':psku,'name':prefix+' price (12 pcs)','unit':'pcs','purchase_price':'1000','selling_price':'1500','reorder_point':'1','category_id':'1'},label='Product for price lists')
    pproduct=int(scalar('SELECT id FROM products WHERE sku=?',[psku]))
    admin.post('/products/units',{'id':pproduct,'unit_code':'karton','factor_to_base':'12','purchase_price':'12000','selling_price':'17000','is_purchase_default':'1','is_sales_default':'1'},label='Karton of 12 at 17.000 (cost 12.000)')
    warehouse.post('/purchase-orders',{'supplier_id':'1','warehouse_id':'1','product_id':pproduct,'quantity':'40','unit_code':'karton'},label='PO 40 karton for the price-list product')
    ppo=int(scalar('SELECT MAX(id) FROM purchase_orders'));admin.post('/purchase-orders/order',{'id':ppo})
    warehouse.post('/purchase-orders/receive',{'id':ppo,'item_id':int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[ppo])),'quantity':'40'},label='Receive 40 karton')
    code,body,_=admin.request('/customers',{'name':prefix+' Grosir Uji','email':'','phone':'021-555','address':'Jl. Uji No. 1, Bekasi','npwp':'12.34'});check('Customer NPWP must have 15 or 16 digits',code==422 and 'NPWP must have 15 or 16 digits' in body)
    admin.post('/customers',{'name':prefix+' Grosir Uji','email':'','phone':'021-555','address':'Jl. Uji No. 1, Bekasi','npwp':'01.234.567.8-901.2345','tax_name':'PT Uji Sejahtera','tax_address':'Jl. Pajak No. 9, Jakarta'},label='Customer with NPWP (dots and dashes stripped)')
    pcustomer=int(scalar('SELECT id FROM customers WHERE name=?',[prefix+' Grosir Uji']))
    check('NPWP stored as digits',scalar('SELECT npwp FROM customers WHERE id=?',[pcustomer])=='0123456789012345')
    check('Price lists are Admin-only pages',[sales.request('/price-lists')[0],warehouse.request('/price-lists')[0],admin.request('/price-lists')[0]]==[403,403,200])
    sales.post('/price-lists',{'name':prefix+' forged','is_active':'1'},403,label='Sales cannot create a price list')
    admin.post('/price-lists',{'name':prefix+' Grosir','description':'Harga grosir uji','is_active':'1','valid_from':'','valid_to':''},label='Admin creates a price list')
    plid=int(scalar('SELECT id FROM price_lists WHERE name=?',[prefix+' Grosir']))
    code,body,_=admin.request('/price-lists/items',{'id':plid,'product_id':pproduct,'unit_code':'karton','min_quantity':'1','unit_price':'11999'});check('A tier below cost is refused when maintained',code==422 and 'below its current purchase price' in body)
    for minimum,price in [('1','16500'),('10','16000')]:admin.post('/price-lists/items',{'id':plid,'product_id':pproduct,'unit_code':'karton','min_quantity':minimum,'unit_price':price},label='Tier karton from '+minimum+' at '+price)
    admin.post('/price-lists/assign',{'id':plid,'customer_id':pcustomer},label='Customer assigned to the price list')
    check('Price list changes are audited by the service',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE entity_type='price_lists' AND entity_id=? AND status='success'",[plid]))==3 and int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='customers.price-list' AND entity_id=?",[pcustomer]))==1)
    resolve=lambda client,quantity:client.request('/api/price-resolution?'+urllib.parse.urlencode({'customer_id':pcustomer,'product_id':pproduct,'unit_code':'karton','quantity':quantity}),fetch=True)
    code,body,headers=resolve(sales,'9');nine=json.loads(body)
    check('API resolves the tier below 10 and offers the next tier',code==200 and 'application/json' in headers.get('Content-Type','') and [nine['source'],nine['price'],nine['next_tier']]==['PRICE_LIST','16500.00',{'min_quantity':10,'price':'16000.00'}],nine)
    check('Crossing the tier changes the price',json.loads(resolve(sales,'10')[1])['price']=='16000.00')
    check('Price API: Warehouse 403, anonymous 401',[resolve(warehouse,'1')[0],resolve(Client(),'1')[0]]==[403,401])
    _,soform,_=sales.request('/sales-orders/create');check('SO form resolves prices live','data-price-resolution="/api/price-resolution"' in soform and 'data-price-customer' in soform and 'data-order-price-source' in soform)
    sales.post('/sales-orders',{'customer_id':pcustomer,'warehouse_id':'1','product_id':pproduct,'quantity':'10','unit_code':'karton'},label='SO line without a price takes the tier')
    pso=int(scalar('SELECT MAX(id) FROM sales_orders'))
    check('SO line stores the resolved price and its source',db('SELECT selling_price, list_price, price_reason, price_source, price_list_id FROM sales_order_items WHERE sales_order_id=?',[pso])==[{'selling_price':'16000.00','list_price':'16000.00','price_reason':None,'price_source':'PRICE_LIST','price_list_id':plid}])
    code,body,_=sales.request('/sales-orders',{'customer_id':pcustomer,'warehouse_id':'1','product_id':pproduct,'quantity':'10','unit_code':'karton','selling_price':'17000'});check('The master price now differs from the list price and needs a reason',code==422 and 'differs from the list price' in body)
    code,body,_=sales.request('/sales-orders',{'customer_id':pcustomer,'warehouse_id':'1','product_id':pproduct,'quantity':'10','unit_code':'karton','selling_price':'14399.99','price_reason':'Diskon'});check('Sales cap is measured against the list price',code==422 and 'below the list price' in body)
    admin.post('/sales-orders/cancel',{'id':pso},label='Cancel the price-list draft')
    # Quotations: create, send, accept, convert once; reject and cancel paths; Sales see only their own.
    code,body,headers=sales.request('/quotations',{'customer_id':pcustomer,'warehouse_id':'1','product_id':pproduct,'quantity':'12','unit_code':'karton','notes':prefix+' quote'})
    qid=int(scalar('SELECT id FROM quotations WHERE notes=?',[prefix+' quote']))
    check('Quotation created with a QT number and redirected to its page',code==302 and headers.get('Location')=='/quotations/show?id='+str(qid) and re.match(r'^QT-[0-9]{4}-[0-9]{4,}$',scalar('SELECT quotation_number FROM quotations WHERE id=?',[qid])) is not None)
    check('Quotation access: other Sales 403, Warehouse 403, owner 200',[other_sales.request('/quotations/show?id='+str(qid))[0],warehouse.request('/quotations')[0],sales.request('/quotations/show?id='+str(qid))[0]]==[403,403,200])
    code,body,_=sales.request('/quotations/convert',{'id':qid});check('A draft quotation cannot be converted',code==422 and 'Only an Accepted quotation' in body)
    sales.post('/quotations/send',{'id':qid},label='Quotation sent');sales.post('/quotations/accept',{'id':qid},label='Customer accepted')
    _,qprint,_=sales.request('/quotations/print?id='+str(qid));check('Printable quotation','QUOTATION / PENAWARAN HARGA' in qprint and 'Rp 192.000,00' in qprint and 'data-print' in qprint)
    code,body,headers=sales.request('/quotations/convert',{'id':qid})
    qso=int(scalar('SELECT id FROM sales_orders WHERE quotation_id=?',[qid]))
    check('Conversion opens the new SO draft',code==302 and headers.get('Location')=='/sales-orders/show?id='+str(qso))
    check('Converted SO carries the quote reference and the quoted price',db('SELECT so.status, so.created_by, i.selling_price, i.price_source, i.quotation_item_id IS NOT NULL q FROM sales_orders so JOIN sales_order_items i ON i.sales_order_id=so.id WHERE so.id=?',[qso])==[{'status':'Draft','created_by':2,'selling_price':'16000.00','price_source':'PRICE_LIST','q':1}])
    code,body,_=admin.request('/quotations/convert',{'id':qid});check('A quotation converts to one SO only',code==422 and 'already converted' in body and int(scalar('SELECT COUNT(*) FROM sales_orders WHERE quotation_id=?',[qid]))==1)
    sales.post('/quotations',{'customer_id':pcustomer,'warehouse_id':'1','product_id':pproduct,'quantity':'1','unit_code':'karton','notes':prefix+' reject'})
    rq=int(scalar('SELECT id FROM quotations WHERE notes=?',[prefix+' reject']));sales.post('/quotations/send',{'id':rq})
    sales.post('/quotations/reject',{'id':rq,'reason':'Harga pesaing lebih murah'},label='Customer rejected the quotation')
    sales.post('/quotations',{'customer_id':pcustomer,'warehouse_id':'1','product_id':pproduct,'quantity':'1','unit_code':'karton','notes':prefix+' cancel'})
    cq=int(scalar('SELECT id FROM quotations WHERE notes=?',[prefix+' cancel']));sales.post('/quotations/cancel',{'id':cq},label='Draft quotation cancelled')
    check('Rejected and cancelled quotations keep their reason and stamps',db('SELECT status, rejection_reason FROM quotations WHERE id IN (?, ?) ORDER BY id',[rq,cq])==[{'status':'Rejected','rejection_reason':'Harga pesaing lebih murah'},{'status':'Cancelled','rejection_reason':None}])
    _,qlist,_=other_sales.request('/quotations');check('Other Sales does not list these quotations',prefix+' quote' not in qlist and str(qid) not in re.findall(r'/quotations/show\?id=(\d+)',qlist))
    # Delivery notes: two shipments, each with its own surat jalan in the same transaction as the issue.
    sales.post('/sales-orders/submit',{'id':qso});admin.post('/sales-orders/approve',{'id':qso},label='Approve the converted SO')
    qitem=int(scalar('SELECT id FROM sales_order_items WHERE sales_order_id=?',[qso]))
    code,body,_=warehouse.request('/sales-orders/issue',{'id':qso,'quantities['+str(qitem)+']':'5','vehicle':'B'*41});check('Vehicle longer than 40 characters refused before any stock moves',code==422 and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[qso]))==0)
    warehouse.post('/sales-orders/issue',{'id':qso,'quantities['+str(qitem)+']':'5','vehicle':'B 1234 UJI','driver':'Pak Budi'},label='Ship 5 karton with vehicle and driver')
    warehouse.post('/sales-orders/issue',{'id':qso,'quantities['+str(qitem)+']':'7'},label='Ship the remaining 7 karton')
    notes=db('SELECT id, delivery_number, shipment_number, vehicle, driver, origin FROM delivery_notes WHERE sales_order_id=? ORDER BY shipment_number',[qso])
    check('Each shipment has exactly one delivery note',[(n['shipment_number'],n['vehicle'],n['driver'],n['origin']) for n in notes]==[(1,'B 1234 UJI','Pak Budi','Issue'),(2,None,None,'Issue')] and all(re.match(r'^DO-[0-9]{4}-[0-9]{4,}$',n['delivery_number']) for n in notes),notes)
    check('Every Issue ledger row of the order is on one delivery note',int(scalar("SELECT COUNT(*) FROM stock_ledger l JOIN delivery_note_lots x ON x.ledger_id=l.id WHERE l.reference_type='SO' AND l.reference_id=?",[qso]))==int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[qso]))>0)
    dn1,dn2=notes[0]['id'],notes[1]['id']
    _,dnprint,_=warehouse.request('/delivery-notes/show?id='+str(dn1))
    check('Printable surat jalan with units, base, lots, vehicle and driver','DELIVERY NOTE / SURAT JALAN' in dnprint and '5 karton' in dnprint and '60 pcs' in dnprint and 'B 1234 UJI' in dnprint and 'Pak Budi' in dnprint and 'data-lot' in dnprint and 'Jl. Uji No. 1, Bekasi' in dnprint)
    check('Delivery notes: owner Sales 200, other Sales 403; list filters',[sales.request('/delivery-notes/show?id='+str(dn1))[0],other_sales.request('/delivery-notes/show?id='+str(dn1))[0],warehouse.request('/delivery-notes?invoiced=no')[0],warehouse.request('/delivery-notes?invoiced=maybe')[0]]==[200,403,200,422])
    # Invoices with PPN: Admin bills each delivery note once; payments, overpayment, void and re-invoice.
    warehouse.post('/invoices',{'delivery_note_id':dn1},403,label='Warehouse cannot invoice');sales.post('/invoices',{'delivery_note_id':dn1},403,label='Sales cannot invoice')
    code,body,headers=admin.request('/invoices',{'delivery_note_id':dn1});inv1=int(scalar('SELECT id FROM invoices WHERE delivery_note_id=? AND voided_at IS NULL',[dn1]))
    check('Invoice created from the delivery note',code==302 and headers.get('Location')=='/invoices/show?id='+str(inv1))
    code,body,_=admin.request('/invoices',{'delivery_note_id':dn1});check('A delivery note is invoiced once',code==422 and 'already invoiced' in body)
    row=db('SELECT subtotal, dpp_amount, vat_amount, total_amount, vat_rate_percent, vat_dpp_numerator, vat_dpp_denominator, customer_npwp, customer_tax_name, due_date >= invoice_date d FROM invoices WHERE id=?',[inv1])[0]
    check('5 karton x 16.000 = 80.000; DPP 73.333; PPN 8.800; total 88.800; settings and tax identity snapshotted',[row['subtotal'],row['dpp_amount'],row['vat_amount'],row['total_amount'],row['vat_rate_percent'],row['vat_dpp_numerator'],row['vat_dpp_denominator'],row['customer_npwp'],row['customer_tax_name']]==['80000.00','73333.00','8800.00','88800.00',12,11,12,'0123456789012345','PT Uji Sejahtera'],row)
    today=str(scalar('SELECT invoice_date FROM invoices WHERE id=?',[inv1]))
    code,body,_=admin.request('/invoices/payments',{'id':inv1,'paid_on':'2099-01-01','amount':'1','method':'Cash'});check('Future payment date refused',code==422 and 'future' in body)
    code,body,_=admin.request('/invoices/payments',{'id':inv1,'paid_on':today,'amount':'88800.01','method':'Transfer'});check('Overpayment refused',code==422 and 'Overpayment refused' in body)
    sales.post('/invoices/payments',{'id':inv1,'paid_on':today,'amount':'1','method':'Cash'},403,label='Sales cannot record payments')
    admin.post('/invoices/payments',{'id':inv1,'paid_on':today,'amount':'40000','method':'Giro','reference':'GR-001'},label='Partial payment')
    _,ishow,_=admin.request('/invoices/show?id='+str(inv1));check('Invoice shows PartiallyPaid and no void once paid','PartiallyPaid' in ishow and 'Void Invoice' not in ishow and 'Rp 48.800,00' in ishow)
    code,body,_=admin.request('/invoices/void',{'id':inv1,'reason':'Salah'});check('An invoice with payments cannot be voided',code==422 and 'has payments' in body)
    admin.post('/invoices/payments',{'id':inv1,'paid_on':today,'amount':'48800','method':'Transfer','reference':'TRF-002'},label='Final payment')
    check('Paid in full: Σ payments = total',db('SELECT paid_amount, (SELECT SUM(amount) FROM invoice_payments WHERE invoice_id=i.id) s FROM invoices i WHERE id=?',[inv1])==[{'paid_amount':'88800.00','s':'88800.00'}])
    admin.post('/invoices',{'delivery_note_id':dn2},label='Invoice the second delivery note')
    inv2=int(scalar('SELECT id FROM invoices WHERE delivery_note_id=? AND voided_at IS NULL',[dn2]))
    stock_before=scalar("SELECT CONCAT(quantity,'/',reserved_quantity) FROM product_stocks WHERE product_id=? AND warehouse_id=1",[pproduct])
    admin.post('/invoices/void',{'id':inv2,'reason':'Alamat pajak salah'},label='Void an unpaid invoice with a reason')
    check('Void keeps stock and frees the delivery note',scalar("SELECT CONCAT(quantity,'/',reserved_quantity) FROM product_stocks WHERE product_id=? AND warehouse_id=1",[pproduct])==stock_before and int(scalar('SELECT COUNT(*) FROM invoices WHERE active_delivery_note_id=?',[dn2]))==0)
    admin.post('/invoices',{'delivery_note_id':dn2},label='Re-invoice the delivery note after the void')
    inv3=int(scalar('SELECT id FROM invoices WHERE delivery_note_id=? AND voided_at IS NULL',[dn2]))
    check('Second invoice: 7 x 16.000 = 112.000 + PPN 12.320 = 124.320',db('SELECT subtotal, vat_amount, total_amount FROM invoices WHERE id=?',[inv3])==[{'subtotal':'112000.00','vat_amount':'12320.00','total_amount':'124320.00'}])
    _,iprint,_=admin.request('/invoices/print?id='+str(inv1));check('Printable invoice with NPWP and PPN','INVOICE / FAKTUR PENJUALAN' in iprint and 'NPWP 0123456789012345' in iprint and 'Rp 8.800,00' in iprint)
    check('Invoice access: owner Sales read-only, other Sales and Warehouse 403',[sales.request('/invoices/show?id='+str(inv1))[0],'data-payment-form' in sales.request('/invoices/show?id='+str(inv3))[1],other_sales.request('/invoices/show?id='+str(inv1))[0],warehouse.request('/invoices')[0]]==[200,False,403,403])
    code,body,headers=admin.request('/reports/receivables.csv');aging=[r for r in csv.reader(io.StringIO(body)) if r and r[0]==prefix+' Grosir Uji']
    check('AR aging CSV lists the open invoice in the 0-30 bucket',code==200 and 'text/csv' in headers.get('Content-Type','') and aging==[[prefix+' Grosir Uji','124320.00','0.00','0.00','0.00','124320.00','0.00','1']],aging)
    code,body,_=admin.request('/reports/sales-by-customer.csv?from='+today+'&to='+today);sales_rows=[r for r in csv.reader(io.StringIO(body)) if r and r[0]==prefix+' Grosir Uji']
    check('Sales by customer counts net, PPN and total of non-void invoices',code==200 and sales_rows==[[prefix+' Grosir Uji','2','192000.00','21120.00','213120.00','88800.00',today,today]],sales_rows)
    check('Reports are Admin-only',[sales.request('/reports/receivables')[0],warehouse.request('/reports/sales-by-customer')[0],admin.request('/reports/receivables')[0]]==[403,403,200])
    check('Admin dashboard shows outstanding receivables','data-receivables-card' in admin.request('/dashboard')[1] and 'data-receivables-card' not in sales.request('/dashboard')[1])
    # D-20 / ADR-026: costs on every movement, COGS per shipment = surat jalan lots x lot cost, margin per invoice, Admin only.
    rows=db("SELECT movement_type, quantity, unit_cost, cost_amount FROM stock_ledger WHERE reference_type='SO' AND reference_id=? ORDER BY id",[qso])
    check('Every Issue row of the order carries its lot cost (cost = -quantity x unit cost)',rows and all(r['unit_cost'] is not None and round(float(r['cost_amount'])*100)==-int(r['quantity'])*round(float(r['unit_cost'])*100) for r in rows),rows)
    dn_cogs=[int(scalar("SELECT COALESCE(SUM(-CAST(ROUND(s.cost_amount*100) AS SIGNED)),0) FROM delivery_note_lots x JOIN stock_ledger s ON s.id=x.ledger_id WHERE x.delivery_note_id=?",[n])) for n in (dn1,dn2)]
    dn_lots=[int(scalar('SELECT COALESCE(SUM(CAST(ROUND(x.quantity*l.unit_cost*100) AS SIGNED)),0) FROM delivery_note_lots x JOIN stock_lots l ON l.id=x.lot_id WHERE x.delivery_note_id=?',[n])) for n in (dn1,dn2)]
    check('COGS per delivery note = its lots x lot cost',dn_cogs==dn_lots and min(dn_cogs)>0,[dn_cogs,dn_lots])
    _,soshow,_=admin.request('/sales-orders/show?id='+str(qso))
    check('Admin SO page: COGS of both shipments and margin of the invoiced ones','data-costing-cogs="%d"'%sum(dn_cogs) in soshow and 'data-costing-margin="%d"'%(8000000+11200000-sum(dn_cogs)) in soshow)
    check('Owner Sales and Warehouse see no cost or margin','data-costing' not in sales.request('/sales-orders/show?id='+str(qso))[1] and 'data-costing' not in warehouse.request('/sales-orders/show?id='+str(qso))[1] and 'data-costing' not in warehouse.request('/delivery-notes/show?id='+str(dn1))[1] and 'data-costing' not in sales.request('/invoices/show?id='+str(inv1))[1])
    check('Admin delivery note and invoice pages show COGS','data-costing-cogs="%d"'%dn_cogs[0] in admin.request('/delivery-notes/show?id='+str(dn1))[1] and 'data-invoice-cogs="%d"'%dn_cogs[0] in admin.request('/invoices/show?id='+str(inv1))[1])
    code,body,_=admin.request('/reports/gross-margin.csv?'+urllib.parse.urlencode({'from':today,'to':today,'customer_id':pcustomer}))
    inv_rows=[r for r in csv.reader(io.StringIO(body)) if len(r)>2 and r[0]=='invoiced']
    check('Margin CSV: void invoice excluded, revenue = 192.000 net, COGS = both shipments',code==200 and sorted(r[2] for r in inv_rows)==sorted(scalar('SELECT invoice_number FROM invoices WHERE id=?',[i]) for i in (inv1,inv3)) and abs(sum(float(r[12]) for r in inv_rows)-192000)<0.001 and round(sum(float(r[13]) for r in inv_rows)*100)==sum(dn_cogs),inv_rows)
    check('Margin and valuation reports: Sales 403, Warehouse 403',[sales.request('/reports/gross-margin')[0],warehouse.request('/reports/gross-margin')[0],warehouse.request('/reports/gross-margin.csv')[0],sales.request('/reports/valuation')[0],warehouse.request('/reports/inventory-losses')[0],warehouse.request('/reports/valuation-reconciliation')[0]]==[403]*6)
    _,recon,_=admin.request('/reports/valuation-reconciliation?from='+today+'&to='+today);check('Reconciliation of today balances to the cent','data-reconciled="1"' in recon)
    code,body,_=admin.request('/reports/valuation.csv?by=lot');check('Valuation CSV by lot with in-transit and totals',code==200 and body.splitlines()[0].startswith('section,lot_code') and 'TOTAL inventory' in body)
    check('Every D-20 valuation invariant holds after the workflow',valuation_checks()==dict.fromkeys(valuation_checks().keys(),0) and len(valuation_checks())==10,valuation_checks())
    check('Every D-19 invariant holds after the workflow',sales_document_checks()==dict.fromkeys(['issue_rows_without_delivery_note','delivery_note_line_mismatches','delivery_note_issue_mismatches','invoice_line_mismatches','invoice_total_mismatches','invoice_payment_mismatches','quotation_conversion_mismatches','price_source_mismatches'],0),sales_document_checks())
    fixtures={'product_id':product,'warehouse_id':1,'destination_id':2,'current_quantity':quantity(),'pending_operation':self_approval,'posted_operation':adjustment,'po_id':po,'so_id':so,'receipt_ledger_id':source,'issue_ledger_id':issue}
except Exception as error:
    check('Unexpected business HTTP failure',False,type(error).__name__+': '+str(error));fixtures={}
failed=sum(not row['passed'] for row in checks)
from pathlib import Path
output=Path(args.output);output.parent.mkdir(parents=True,exist_ok=True);output.write_text(json.dumps({'project':args.project,'url':args.url,'checks':len(checks),'passed':len(checks)-failed,'failed':failed,'results':checks,'fixtures':fixtures},indent=2)+'\n')
raise SystemExit(1 if failed else 0)
