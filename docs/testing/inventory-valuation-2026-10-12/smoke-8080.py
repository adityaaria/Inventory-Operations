#!/usr/bin/env python3
"""D-20 / ADR-026 smoke on the user's 8080 stack. Read-only: logins and GET requests only (no document is created);
read-only SQL through the app container compares every figure with the database."""
import csv, http.cookiejar, io, json, re, subprocess, urllib.error, urllib.parse, urllib.request
URL = 'http://localhost:8080'; ROOT = '/Users/karyawan/Documents/Neuron/Tugas Akhir /Inventory-Operations'
results = []


def check(name, ok, detail=None):
    results.append((name, bool(ok))); print(('PASS ' if ok else 'FAIL ') + name + ('' if detail is None else ' :: ' + str(detail)[:300]), flush=True)


def db(sql, params=None):
    assert sql.lstrip().upper().startswith('SELECT ') and ';' not in sql
    php = '$config=require "config/bootstrap.php"; $pdo=(new App\\Support\\DatabaseFactory($config))->create(); $in=json_decode(stream_get_contents(STDIN),true); $q=$pdo->prepare($in["sql"]); $q->execute($in["params"]); echo json_encode($q->fetchAll(PDO::FETCH_ASSOC));'
    out = subprocess.run(['docker', 'compose', 'exec', '-T', 'app', 'php', '-r', php], cwd=ROOT, input=json.dumps({'sql': sql, 'params': params or []}), text=True, capture_output=True, check=True).stdout
    return json.loads(out)


def scalar(sql, params=None): return next(iter(db(sql, params)[0].values()))


def app_checks(cls):
    php = '$config=require "config/bootstrap.php"; $pdo=(new App\\Support\\DatabaseFactory($config))->create(); echo json_encode((new App\\Repository\\MySql\\' + cls + '($pdo))->report());'
    return json.loads(subprocess.run(['docker', 'compose', 'exec', '-T', 'app', 'php', '-r', php], cwd=ROOT, text=True, capture_output=True, check=True).stdout)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a): return None


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect()); self.csrf = ''

    def req(self, path, data=None):
        if data is not None: data = {'csrf_token': self.csrf, **data}
        r = urllib.request.Request(URL + path, urllib.parse.urlencode(data).encode() if data is not None else None)
        try: resp = self.opener.open(r, timeout=60)
        except urllib.error.HTTPError as e: resp = e
        body = resp.read().decode(); m = re.search(r'name="csrf_token" value="([^"]+)"', body)
        if m: self.csrf = m.group(1)
        return resp.code, body, resp.headers

    def login(self, email):
        self.req('/login'); code, _, _ = self.req('/login', {'email': email, 'password': 'password'}); check('login ' + email, code == 302, code)


admin, sales, warehouse = Client(), Client(), Client()
for c, e in [(admin, 'admin@example.test'), (sales, 'sales1@example.test'), (warehouse, 'warehouse1@example.test')]: c.login(e)

lots = int(scalar('SELECT SUM(CAST(ROUND(quantity*unit_cost*100) AS SIGNED)) FROM stock_lots'))
transit = int(scalar("SELECT COALESCE(SUM(CAST(ROUND(t.dispatched_quantity*t.unit_cost*100) AS SIGNED)),0) FROM inventory_operation_lots t JOIN inventory_operations o ON o.id=t.operation_id WHERE o.status='Dispatched'"))
opening = int(scalar('SELECT SUM(CAST(ROUND(opening_quantity*unit_cost*100) AS SIGNED)) FROM stock_lots'))
ledger = int(scalar('SELECT COALESCE(SUM(CAST(ROUND(cost_amount*100) AS SIGNED)),0) FROM stock_ledger WHERE cost_amount IS NOT NULL'))
check('Valuation = Σ lot quantity × unit cost = LEGACY opening value + later movements', lots == opening + ledger, [lots, opening, ledger])
check('Schema version inventory-valuation-1 recorded and CHECK present', int(scalar("SELECT COUNT(*) FROM schema_versions WHERE version='inventory-valuation-1'")) == 1 and int(scalar("SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND constraint_name='chk_stock_ledger_cost'")) == 1)
valuation = app_checks('ValuationChecks'); check('Every valuation invariant is 0 on 8080', sum(valuation.values()) == 0, valuation)
lot_checks = app_checks('BatchLotChecks'); check('Every lot invariant is 0 on 8080', sum(lot_checks.values()) == 0, lot_checks)
sales_checks = app_checks('SalesDocumentChecks'); check('Every sales-document invariant is 0 on 8080', sum(sales_checks.values()) == 0, sales_checks)

code, body, _ = admin.req('/dashboard')
check('Dashboard Inventory Value = lots at cost + in transit, master figure kept as reference', code == 200 and 'data-inventory-value="%d"' % (lots + transit) in body and 'data-inventory-value-master' in body, lots + transit)
code, body, _ = admin.req('/reports/valuation')
check('Valuation page renders the total and the valuation start', code == 200 and 'data-valuation-total' in body and 'Valuation starts' in body and 'Fatal error' not in body)
for by in ['category', 'product', 'lot']:
    code, body, _ = admin.req('/reports/valuation?by=' + by); check('Valuation by ' + by + ' renders', code == 200 and 'data-valuation-rows' in body, code)
code, body, h = admin.req('/reports/valuation.csv?by=warehouse')
rows = list(csv.reader(io.StringIO(body))); total = [r for r in rows if r and r[0] == 'TOTAL inventory']
check('Valuation CSV total equals the SQL value', code == 200 and 'text/csv' in h.get('Content-Type', '') and total and round(float(total[0][-2]) * 100) == lots + transit, total)
code, body, _ = admin.req('/reports/valuation?as_of=2026-10-11'); check('As-of date before the valuation start is refused with 422', code == 422 and 'no value can be computed' in body, code)
code, body, _ = admin.req('/reports/valuation?as_of=2026-10-12'); check('As-of today renders', code == 200)
code, body, _ = admin.req('/reports/valuation-reconciliation?from=2026-10-01&to=2026-10-12')
check('Reconciliation of October balances to the cent (opening taken at the valuation start)', code == 200 and 'data-reconciled="1"' in body and 'opening value is the value at the valuation start' in body, code)
code, body, _ = admin.req('/reports/valuation-reconciliation.csv?from=2026-10-01&to=2026-10-12')
diffs = [r[-3] for r in csv.reader(io.StringIO(body)) if r and r[0] != 'line']
check('Reconciliation CSV: every difference is 0.00', code == 200 and diffs and set(diffs) == {'0.00'}, diffs)
code, body, _ = admin.req('/reports/gross-margin?from=2026-10-01&to=2026-10-31&group=line'); check('Gross margin page renders', code == 200 and 'data-margin-total' in body)
code, body, _ = admin.req('/reports/gross-margin.csv?from=2026-10-01&to=2026-10-31&group=line')
inv = [r for r in csv.reader(io.StringIO(body)) if r and r[0] == 'invoiced']
sql_rev = int(scalar("SELECT COALESCE(SUM(CAST(ROUND(il.amount*100) AS SIGNED)),0) FROM invoices i JOIN invoice_lines il ON il.invoice_id=i.id WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN '2026-10-01' AND '2026-10-31'"))
sql_cogs = int(scalar("SELECT COALESCE(SUM(-CAST(ROUND(s.cost_amount*100) AS SIGNED)),0) FROM invoices i JOIN invoice_lines il ON il.invoice_id=i.id JOIN delivery_note_lots x ON x.delivery_note_line_id=il.delivery_note_line_id JOIN stock_ledger s ON s.id=x.ledger_id WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN '2026-10-01' AND '2026-10-31'"))
check('Margin CSV revenue and COGS equal SQL', round(sum(float(r[12]) for r in inv) * 100) == sql_rev and round(sum(float(r[13] or 0) for r in inv) * 100) == sql_cogs, [sql_rev, sql_cogs, len(inv)])
code, body, _ = admin.req('/reports/inventory-losses?from=2026-10-01&to=2026-10-31'); check('Losses page renders', code == 200 and 'data-losses-total' in body)
check('Valuation, margin and losses: Sales 403 and Warehouse 403', [sales.req('/reports/valuation')[0], warehouse.req('/reports/valuation')[0], sales.req('/reports/gross-margin')[0], warehouse.req('/reports/gross-margin')[0], warehouse.req('/reports/inventory-losses')[0], sales.req('/reports/valuation-reconciliation.csv')[0]] == [403] * 6)
valued_so = db("SELECT reference_id id, SUM(-CAST(ROUND(cost_amount*100) AS SIGNED)) cogs FROM stock_ledger WHERE movement_type='Issue' AND reference_type='SO' AND cost_amount IS NOT NULL GROUP BY reference_id ORDER BY reference_id LIMIT 1")
if valued_so:
    so = valued_so[0]
    code, body, _ = admin.req('/sales-orders/show?id=' + str(so['id']))
    check('Admin SO page shows the COGS of its valued shipment', code == 200 and 'data-costing-cogs="%d"' % int(so['cogs']) in body, so)
    check('Warehouse SO page shows no cost', 'data-costing' not in warehouse.req('/sales-orders/show?id=' + str(so['id']))[1])
    note = scalar('SELECT MIN(delivery_note_id) FROM delivery_note_lots x JOIN stock_ledger s ON s.id=x.ledger_id WHERE s.cost_amount IS NOT NULL')
    code, body, _ = admin.req('/delivery-notes/show?id=' + str(note)); check('Admin delivery note shows COGS (not printed)', code == 200 and 'data-costing' in body and 'class="card no-print"' in body)
    invoice = db('SELECT id FROM invoices WHERE delivery_note_id=? AND voided_at IS NULL', [note])
    if invoice:
        code, body, _ = admin.req('/invoices/show?id=' + str(invoice[0]['id'])); check('Admin invoice shows COGS and margin', code == 200 and 'data-invoice-costing' in body)
migrated = scalar("SELECT MIN(id) FROM delivery_notes WHERE origin='Migration'")
code, body, _ = admin.req('/delivery-notes/show?id=' + str(migrated)); check('A migrated (pre-valuation) shipment says it has no cost', code == 200 and 'before valuation start' in body)
print(json.dumps({'checks': len(results), 'passed': sum(ok for _, ok in results), 'failed': sum(not ok for _, ok in results)}))
raise SystemExit(0 if all(ok for _, ok in results) else 1)
