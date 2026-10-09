'use strict';
// Backup screenshots of the presentation demo flows, taken from the real application on a disposable seeded stack.
// Usage: node tests/Browser/demo-screenshots.cjs <outputDir> [appUrl] [devtoolsUrl]
const fs = require('node:fs');
const out = process.argv[2] || 'docs/presentation/demo-cadangan';
const url = (process.argv[3] || 'http://localhost:18091').replace(/\/$/, '');
const devtools = (process.argv[4] || 'http://127.0.0.1:9226').replace(/\/$/, '');
if (!/^http:\/\/localhost:180\d\d$/.test(url)) throw Error('Refusing to write outside a disposable localhost:180xx stack');

(async () => {
    const tabs = await (await fetch(devtools + '/json')).json();
    const ws = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
    await new Promise(resolve => { ws.onopen = resolve; });
    let id = 0;
    const pending = new Map();
    ws.onmessage = event => { const m = JSON.parse(event.data); if (m.id) { pending.get(m.id)(m.result || m.error); pending.delete(m.id); } };
    const call = (method, params = {}) => new Promise(resolve => { const n = ++id; pending.set(n, resolve); ws.send(JSON.stringify({ id: n, method, params })); });
    const evaluate = async expression => {
        const result = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (result.exceptionDetails) throw Error(expression.slice(0, 80) + ' :: ' + (result.exceptionDetails.exception?.description || result.exceptionDetails.text));
        return result.result?.value;
    };
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const settle = async () => { for (let i = 0; i < 100; i++) { if (await evaluate(`document.readyState === 'complete'`).catch(() => false)) break; await sleep(100); } await sleep(600); };
    const go = async path => { await call('Page.navigate', { url: url + path }); await settle(); };
    const shot = async name => { const s = await call('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(`${out}/${name}.png`, Buffer.from(s.data, 'base64')); console.log('saved', name); };
    const login = async email => {
        await go('/login');
        await evaluate(`fetch('/logout', {method: 'POST', body: new URLSearchParams({csrf_token: document.querySelector('[name=csrf_token]').value})}).catch(() => 0)`);
        await go('/login');
        await evaluate(`fetch('/login', {method: 'POST', body: new URLSearchParams({email: ${JSON.stringify(email)}, password: 'password', csrf_token: document.querySelector('[name=csrf_token]').value})})`);
    };
    // form.submit() skips the confirmation dialog, which is not part of what the screenshots show.
    const submit = async action => { await evaluate(`(() => { const f = document.querySelector('form[action="${action}"]'); if (!f) throw Error('missing form ${action}'); f.submit(); })()`); await sleep(300); await settle(); };
    const salesOrder = async number => { await go('/sales-orders?q=' + number); return evaluate(`document.querySelector('a[href^="/sales-orders/show"]').getAttribute('href')`); };
    const createSalesOrder = async (number, quantity) => {
        await go('/sales-orders/create');
        await evaluate(`(() => { const f = document.querySelector('form[action="/sales-orders"]');
            f.elements.order_number.value = ${JSON.stringify(number)};
            f.elements.warehouse_id.value = [...f.elements.warehouse_id.options].find(o => /Surabaya/.test(o.textContent)).value;
            f.elements.product_id.value = [...f.elements.product_id.options].find(o => /BIS-0001/.test(o.textContent)).value;
            f.elements.product_id.dispatchEvent(new Event('change', {bubbles: true}));
            f.elements.quantity.value = '${quantity}'; })()`);
    };

    fs.mkdirSync(out, { recursive: true });
    await call('Page.enable');
    await call('Runtime.enable');
    await call('Emulation.setDeviceMetricsOverride', { width: 1280, height: 860, deviceScaleFactor: 1, mobile: false });
    const stamp = Date.now().toString().slice(-6);
    const oversell = 'SO-DEMO-OVERSELL-' + stamp;
    const fulfil = 'SO-DEMO-OK-' + stamp;

    await login('sales1@example.test');
    await go('/products?q=BIS-0001');
    const product = await evaluate(`document.querySelector('a[href^="/products/show"]').getAttribute('href')`);
    await go(product); await shot('01-stok-per-gudang-BIS-0001');
    await createSalesOrder(oversell, 6); await shot('02-sales-membuat-SO-qty-6'); await submit('/sales-orders');
    const overHref = await salesOrder(oversell); await go(overHref); await submit('/sales-orders/submit');
    await createSalesOrder(fulfil, 2); await submit('/sales-orders');
    const okHref = await salesOrder(fulfil); await go(okHref); await submit('/sales-orders/submit');
    await go('/purchase-orders'); await shot('03-sales-ditolak-akses-PO-403');

    await login('admin@example.test');
    await go(overHref); await shot('04-admin-melihat-SO-pending-approval'); await submit('/sales-orders/approve');
    await go(okHref); await submit('/sales-orders/approve');

    await login('warehouse1@example.test');
    await go(overHref); await shot('05-warehouse-SO-approved-siap-issue');
    await submit('/sales-orders/issue'); await shot('06-issue-ditolak-insufficient-stock');
    await go(okHref); await submit('/sales-orders/issue'); await go(okHref); await shot('07-issue-berhasil-SO-fulfilled');
    await go(product); await shot('08-stok-berkurang-setelah-issue');
    await go('/reports?type=stock-ledger'); await shot('09-ledger-mencatat-issue');
    await go('/purchase-orders?status=Ordered');
    const po = await evaluate(`document.querySelector('a[href^="/purchase-orders/show"]').getAttribute('href')`);
    await go(po); await shot('10-PO-ordered-siap-diterima');
    await evaluate(`(() => { const f = document.querySelector('form[action="/purchase-orders/receive"]'); f.elements.quantity.value = '1'; f.submit(); })()`);
    await sleep(300); await settle(); await go(po); await shot('11-PO-partially-received');
    await go('/api/products/BIS-0001/availability'); await shot('12-json-api-availability');
    ws.close();
})().catch(error => { console.error(error); process.exit(1); });
