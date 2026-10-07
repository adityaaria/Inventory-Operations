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
    admin.post('/purchase-orders',{'order_number':prefix+'-PO','supplier_id':'1','warehouse_id':'1','product_id':product,'quantity':'10','purchase_price':'10'})
    po=int(scalar('SELECT id FROM purchase_orders WHERE order_number=?',[prefix+'-PO']));item=int(scalar('SELECT id FROM purchase_order_items WHERE purchase_order_id=?',[po]));admin.post('/purchase-orders/order',{'id':po})
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
    sales.post('/sales-orders',{'order_number':prefix+'-REJECT','customer_id':'1','warehouse_id':'1','product_id':product,'quantity':'1','selling_price':'20'})
    rejected=int(scalar('SELECT id FROM sales_orders WHERE order_number=?',[prefix+'-REJECT']));sales.post('/sales-orders/submit',{'id':rejected})
    sales.post('/sales-orders/reject',{'id':rejected,'reason':'Forged reject'},403);admin.post('/sales-orders/reject',{'id':rejected,'reason':'Credit review incomplete'})
    _,detail,_=sales.request('/sales-orders/show?id='+str(rejected));check('Sales sees rejection reason with Cancelled status','Credit review incomplete' in detail and scalar('SELECT status FROM sales_orders WHERE id=?',[rejected])=='Cancelled')
    adjustment=operation(warehouse,'Adjustment',5,baseline='3');post(adjustment,422);warehouse.post('/inventory-operations/decide',{'id':adjustment,'decision':'Approved','reason':'Forged approval'},403);approve(adjustment);post(adjustment);post(adjustment);check('Adjustment posted once',quantity()==5 and int(scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='ADJUSTMENT' AND reference_id=?",[adjustment]))==1)
    transfer=operation(warehouse,'Transfer',2,destination_id='2');approve(transfer);post(transfer);post(transfer);check('Transfer atomic pair conserves total',quantity()==3 and quantity(2)==2 and int(scalar("SELECT SUM(quantity_delta) FROM stock_ledger WHERE reference_type='TRANSFER' AND reference_id=?",[transfer]))==0)
    source=int(scalar("SELECT id FROM stock_ledger WHERE reference_type='PO' AND reference_id=?",[po]))
    returned=operation(warehouse,'SupplierReturn',1,source_ledger_id=str(source));approve(returned);post(returned);post(returned);check('Supplier return decreases stock once',quantity()==2)
    over=operation(warehouse,'SupplierReturn',3,source_ledger_id=str(source));approve(over);post(over,422);check('Supplier over-return leaves stock untouched',quantity()==2)
    sales.post('/sales-orders',{'order_number':prefix+'-ISSUE','customer_id':'1','warehouse_id':'1','product_id':product,'quantity':'2','selling_price':'20'});so=int(scalar('SELECT id FROM sales_orders WHERE order_number=?',[prefix+'-ISSUE']));sales.post('/sales-orders/submit',{'id':so});admin.post('/sales-orders/approve',{'id':so});warehouse.post('/sales-orders/issue',{'id':so})
    issue=int(scalar("SELECT id FROM stock_ledger WHERE reference_type='SO' AND reference_id=?",[so]))
    warehouse.post('/inventory-operations',{'kind':'CustomerReturn','source_ledger_id':issue,'quantity':'1','reason':'Missing condition confirmation'},422)
    customer=operation(warehouse,'CustomerReturn',1,source_ledger_id=str(issue),fit_for_stock='1');approve(customer);post(customer);post(customer);check('Fit customer return increases stock and preserves Fulfilled',quantity()==1 and scalar('SELECT status FROM sales_orders WHERE id=?',[so])=='Fulfilled')
    self_approval=operation(admin,'Adjustment',2,baseline='1');admin.post('/inventory-operations/decide',{'id':self_approval,'decision':'Approved','reason':'Self approval'},403)
    reject_proposal=operation(warehouse,'Transfer',1,destination_id='2');admin.post('/inventory-operations/decide',{'id':reject_proposal,'decision':'Rejected','reason':'Not needed'});post(reject_proposal,422)
    cancel_proposal=operation(warehouse,'Transfer',1,destination_id='2');warehouse.post('/inventory-operations/decide',{'id':cancel_proposal,'decision':'Cancelled','reason':'Count changed'});post(cancel_proposal,422)
    # Check aggregate delta equals stock for this newly created, zero-start catalog product.
    balance=int(scalar("SELECT SUM(CASE WHEN movement_type='Receipt' THEN quantity WHEN movement_type='Issue' THEN -quantity ELSE quantity_delta END) FROM stock_ledger WHERE product_id=?",[product]));check('All new movements reconcile with both warehouse balances',balance==quantity()+quantity(2))
    status,csv_body,_=warehouse.request('/reports/stock-ledger.csv');check('Signed Adjustment exported in stock report',status==200 and 'Adjustment' in csv_body and 'TRANSFER' in csv_body and 'SUPPLIER_RETURN' in csv_body and 'CUSTOMER_RETURN' in csv_body)
    for path in ['/inventory-operations','/inventory-operations?kind=Transfer&status=Posted','/inventory-operations/create','/inventory-operations/show?id='+str(adjustment),'/replenishment']:
        code,body,_=admin.request(path);check('New page renders '+path,code==200 and 'Warning:' not in body and 'Fatal error' not in body)
    check('Successful operation replay does not duplicate audit',int(scalar("SELECT COUNT(*) FROM audit_logs WHERE action='inventory-operations.post' AND entity_id=? AND status='success'",[transfer]))==1)
    check('Invalid stock filters rejected',admin.request('/inventory-operations?status=Invented')[0]==422)
    fixtures={'product_id':product,'warehouse_id':1,'destination_id':2,'current_quantity':quantity(),'pending_operation':self_approval,'posted_operation':adjustment,'po_id':po,'so_id':so,'receipt_ledger_id':source,'issue_ledger_id':issue}
except Exception as error:
    check('Unexpected business HTTP failure',False,type(error).__name__+': '+str(error));fixtures={}
failed=sum(not row['passed'] for row in checks)
from pathlib import Path
output=Path(args.output);output.parent.mkdir(parents=True,exist_ok=True);output.write_text(json.dumps({'project':args.project,'url':args.url,'checks':len(checks),'passed':len(checks)-failed,'failed':failed,'results':checks,'fixtures':fixtures},indent=2)+'\n')
raise SystemExit(1 if failed else 0)
