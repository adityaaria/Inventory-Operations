'use strict';
// Draft recovery, multi-item PO and responsive acceptance through Chrome DevTools Protocol.
// Usage: node tests/Browser/form-drafts.cjs [appUrl] [devtoolsUrl]  (disposable seeded stack only).
const assert = require('node:assert/strict');
const url = (process.argv[2] || 'http://localhost:18091').replace(/\/$/, '');
const devtools = (process.argv[3] || 'http://127.0.0.1:9226').replace(/\/$/, '');
if (!/^http:\/\/localhost:180\d\d$/.test(url)) throw Error('Refusing to write outside a disposable localhost:180xx stack');

(async () => {
    const tabs = await (await fetch(devtools + '/json')).json();
    const ws = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
    await new Promise(resolve => { ws.onopen = resolve; });
    let id = 0;
    const pending = new Map();
    // Keyed by id: a rejection handled a moment later (e.g. an aborted view transition) is revoked, as in the business harness.
    const exceptions = new Map();
    let revoked = 0;
    ws.onmessage = event => {
        const message = JSON.parse(event.data);
        if (message.id) {
            const request = pending.get(message.id);
            pending.delete(message.id);
            message.error ? request.reject(Error(message.error.message)) : request.resolve(message.result);
        } else if (message.method === 'Runtime.exceptionThrown') {
            exceptions.set(message.params.exceptionDetails.exceptionId, (message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text) + ' @ ' + (message.params.exceptionDetails.url || '?') + ' after: ' + (results[results.length - 1] || 'start'));
        } else if (message.method === 'Runtime.exceptionRevoked') {
            if (exceptions.delete(message.params.exceptionId)) revoked++;
        }
    };
    const call = (method, params = {}) => new Promise((resolve, reject) => { const n = ++id; pending.set(n, { resolve, reject }); ws.send(JSON.stringify({ id: n, method, params })); });
    const evaluate = async expression => {
        const result = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    async function wait(expression, label = expression) {
        const until = Date.now() + 12000;
        while (Date.now() < until) { try { if (await evaluate(expression)) return; } catch { /* retry */ } await sleep(100); }
        throw Error('Timed out: ' + label);
    }
    async function navigate(path) {
        await call('Page.navigate', { url: url + path });
        await wait(`document.readyState === 'complete' && location.pathname === ${JSON.stringify(path.split('?')[0])}`, 'navigate ' + path);
        await sleep(250);
    }
    async function login(email) {
        await navigate('/login');
        await evaluate(`fetch('/logout', {method: 'POST', body: new URLSearchParams({csrf_token: document.querySelector('[name=csrf_token]').value})}).catch(() => null)`);
        await navigate('/login');
        const status = await evaluate(`fetch('/login', {method: 'POST', redirect: 'manual', body: new URLSearchParams({email: ${JSON.stringify(email)}, password: 'password', csrf_token: document.querySelector('[name=csrf_token]').value})}).then(r => r.type === 'opaqueredirect' ? 302 : r.status)`);
        assert.equal(status, 302, 'login ' + email);
    }
    const draftKeys = () => evaluate(`Object.keys(localStorage).filter(k => k.startsWith('ioms-draft:')).sort()`);
    const results = [];
    const poNumber = 'PO-DRAFT-' + Date.now(); // unique per run so reruns on the same stack stay valid
    const pass = name => { results.push(name); console.log('ok - ' + name); };

    await call('Page.enable');
    await call('Runtime.enable');
    await call('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });

    // Admin: multi-item PO draft survives a page close and restores every line.
    await login('admin@example.test');
    await navigate('/purchase-orders/create');
    await evaluate(`localStorage.clear()`);
    const products = await evaluate(`[...document.querySelector('[name=product_id]').options].map(o => o.value).slice(0, 3)`);
    assert.equal(products.length, 3);
    await evaluate(`(() => {
        const form = document.querySelector('form[data-draft]');
        const set = (el, v) => { el.value = v; el.dispatchEvent(new Event('input', {bubbles: true})); };
        set(form.elements.order_number, ${JSON.stringify(poNumber)});
        set(form.elements.product_id, ${JSON.stringify(products[0])}); set(form.elements.quantity, '4'); set(form.elements.purchase_price, '1200');
        form.querySelector('[data-order-add]').click(); form.querySelector('[data-order-add]').click();
        const rows = form.querySelectorAll('[data-order-item]');
        const line = (row, f) => row.querySelector('[data-order-input="' + f + '"]');
        set(line(rows[1], 'product_id'), ${JSON.stringify(products[1])}); set(line(rows[1], 'quantity'), '7'); set(line(rows[1], 'purchase_price'), '300');
        set(line(rows[2], 'product_id'), ${JSON.stringify(products[2])}); set(line(rows[2], 'quantity'), '2'); set(line(rows[2], 'purchase_price'), '50');
    })()`);
    await wait(`!!localStorage.getItem('ioms-draft:v1:1:Admin:purchase-order')`, 'draft saved');
    const stored = JSON.parse(await evaluate(`localStorage.getItem('ioms-draft:v1:1:Admin:purchase-order')`));
    assert.ok(!stored.values.some(([name]) => name === 'csrf_token'), 'CSRF token never stored');
    assert.equal(stored.ttl, 8 * 60 * 60 * 1000, 'draft lifetime follows SESSION_ABSOLUTE_SECONDS (D-08)');
    assert.equal(stored.values.filter(([name]) => /^items\[\d+\]\[product_id\]$/.test(name)).length, 2);
    pass('PO draft saved per user/role without CSRF token, with 8-hour session-bound lifetime');

    await navigate('/purchase-orders/create');
    await wait(`!!document.querySelector('[data-draft-banner]')`, 'restore banner');
    assert.equal(await evaluate(`document.querySelectorAll('[data-order-item]').length`), 1, 'nothing restored without consent');
    await evaluate(`[...document.querySelectorAll('[data-draft-banner] button')].find(b => b.textContent === 'Restore draft').click()`);
    await wait(`/checked|Review the items/.test(document.querySelector('[data-draft-banner] p').textContent)`, 'server revalidation');
    const restored = await evaluate(`(() => { const f = document.querySelector('form[data-draft]'); return {
        number: f.elements.order_number.value,
        lines: [...f.querySelectorAll('[data-order-item]')].map(r => ['product_id', 'quantity', 'purchase_price'].map(n => r.querySelector('[data-order-input="' + n + '"]').value)),
        names: [...f.querySelectorAll('[data-order-item] [data-order-input="quantity"]')].map(i => i.name),
        banner: document.querySelector('[data-draft-banner]').textContent }; })()`);
    assert.equal(restored.number, poNumber);
    assert.deepEqual(restored.lines, [[products[0], '4', '1200'], [products[1], '7', '300'], [products[2], '2', '50']]);
    assert.deepEqual(restored.names, ['quantity', 'items[1][quantity]', 'items[2][quantity]']);
    assert.match(restored.banner, /permissions, status and current stock are fine/);
    pass('restore rebuilds three PO lines and server check passes');

    // Cancelling the confirmation must keep the draft; confirming sends it and clears it.
    await evaluate(`document.querySelector('form[data-draft] [type=submit]').click()`);
    await wait(`!document.querySelector('.confirm-backdrop').hidden`, 'confirm dialog');
    await evaluate(`document.querySelector('.confirm-cancel').click()`);
    await sleep(300);
    assert.deepEqual(await draftKeys(), ['ioms-draft:v1:1:Admin:purchase-order']);
    pass('cancelled confirmation keeps the draft');
    await evaluate(`document.querySelector('form[data-draft] [type=submit]').click()`);
    await wait(`!document.querySelector('.confirm-backdrop').hidden`, 'confirm dialog again');
    await evaluate(`document.querySelector('.confirm-submit').click()`);
    await wait(`location.pathname === '/purchase-orders' && document.readyState === 'complete'`, 'redirect after create');
    assert.equal(await evaluate(`!!document.querySelector('.alert-danger')`), false, 'create succeeded without a validation error');
    assert.deepEqual(await draftKeys(), []);
    const created = await evaluate(`fetch('/purchase-orders?q=' + ${JSON.stringify(poNumber)}).then(r => r.text()).then(t => (t.match(/\\/purchase-orders\\/show\\?id=(\\d+)/) || [])[1])`);
    assert.ok(created, 'restored draft was created');
    pass('confirmed submit sends the restored draft once and clears it');

    // Modal create from the list: multi-item controls and draft saving work inside the dialog.
    await navigate('/purchase-orders');
    await evaluate(`document.querySelector('a[href="/purchase-orders/create"]').click()`);
    await wait(`!!document.querySelector('.modal-body form[data-draft][data-order-enhanced][data-draft-enhanced]')`, 'modal form enhanced');
    await evaluate(`(() => { const f = document.querySelector('.modal-body form[data-draft]'); f.querySelector('[data-order-add]').click();
        const q = f.querySelector('[name="items[1][quantity]"]'); q.value = '9'; q.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    await wait(`(JSON.parse(localStorage.getItem('ioms-draft:v1:1:Admin:purchase-order') || '{"values":[]}').values).some(([n, v]) => n === 'items[1][quantity]' && v === '9')`, 'modal draft saved');
    pass('modal create form gets multi-item controls and draft saving');

    // Discard removes the draft.
    await navigate('/purchase-orders/create');
    await wait(`!!document.querySelector('[data-draft-banner]')`, 'banner for discard');
    await evaluate(`[...document.querySelectorAll('[data-draft-banner] button')].find(b => b.textContent === 'Discard draft').click()`);
    assert.deepEqual(await draftKeys(), []);
    pass('discard removes the draft');

    // Stock proposal: kind and supplementary transfer items are restored.
    await navigate('/inventory-operations/create');
    await evaluate(`(() => { const f = document.querySelector('form[data-draft]');
        f.elements.kind.value = 'Transfer'; f.elements.kind.dispatchEvent(new Event('change', {bubbles: true}));
        f.querySelector('[data-stock-add]').click();
        const reason = f.elements.reason; reason.value = 'Rebalance before promotion'; reason.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    await wait(`!!localStorage.getItem('ioms-draft:v1:1:Admin:stock-proposal')`, 'stock draft saved');
    await navigate('/inventory-operations/create');
    await wait(`!!document.querySelector('[data-draft-banner]')`, 'stock banner');
    await evaluate(`[...document.querySelectorAll('[data-draft-banner] button')].find(b => b.textContent === 'Restore draft').click()`);
    await wait(`/checked|Review the items|could not be checked/.test(document.querySelector('[data-draft-banner] p').textContent)`, 'stock revalidation');
    const proposal = await evaluate(`(() => { const f = document.querySelector('form[data-draft]'); return { kind: f.elements.kind.value, reason: f.elements.reason.value, rows: f.querySelectorAll('[data-stock-items] [data-stock-item]').length }; })()`);
    assert.deepEqual(proposal, { kind: 'Transfer', reason: 'Rebalance before promotion', rows: 2 });
    pass('stock proposal kind, reason and items restored');

    // Logout clears every draft on this device.
    await navigate('/dashboard');
    await evaluate(`document.querySelector('form[action="/logout"]').requestSubmit()`);
    await wait(`location.pathname === '/login'`, 'logout');
    assert.deepEqual(await draftKeys(), []);
    pass('logout clears drafts');

    // Sales: draft is not visible to another user, and restore warns when stock is now short.
    await login('sales1@example.test');
    await navigate('/sales-orders/create');
    await evaluate(`localStorage.setItem('ioms-draft:v1:1:Admin:sales-order', JSON.stringify({savedAt: Date.now(), values: [['order_number', 'SO-OTHER-USER']]}))`);
    await navigate('/sales-orders/create');
    assert.equal(await evaluate(`!!document.querySelector('[data-draft-banner]')`), false);
    pass("another user's draft is never offered");
    await evaluate(`(() => { const f = document.querySelector('form[data-draft]');
        const q = f.elements.quantity; q.value = '999999'; q.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    await wait(`(() => { const o = document.querySelector('form[data-draft]').dataset.draftOwner; return !!localStorage.getItem('ioms-draft:v1:' + o + ':sales-order'); })()`, 'sales draft saved');
    await navigate('/sales-orders/create');
    await wait(`!!document.querySelector('[data-draft-banner]')`, 'sales banner');
    await evaluate(`[...document.querySelectorAll('[data-draft-banner] button')].find(b => b.textContent === 'Restore draft').click()`);
    await wait(`/Only \\d+ unit\\(s\\)/.test(document.querySelector('[data-draft-banner]').textContent)`, 'stock warning');
    pass('restored SO warns that current stock is below the requested quantity');

    // Sales multi-item SO: every line restored and warned individually against current stock.
    await navigate('/sales-orders/create');
    await evaluate(`localStorage.clear()`);
    const soProducts = await evaluate(`[...document.querySelector('[name=product_id]').options].map(o => o.value).slice(0, 2)`);
    await evaluate(`(() => { const f = document.querySelector('form[data-draft]');
        const set = (el, v) => { el.value = v; el.dispatchEvent(new Event('input', {bubbles: true})); };
        set(f.elements.product_id, ${JSON.stringify(soProducts[0])}); set(f.elements.quantity, '999998'); set(f.elements.selling_price, '10');
        f.querySelector('[data-order-add]').click();
        set(f.elements['items[1][product_id]'], ${JSON.stringify(soProducts[1])}); set(f.elements['items[1][quantity]'], '999997'); set(f.elements['items[1][selling_price]'], '20'); })()`);
    await wait(`Object.keys(localStorage).some(k => k.endsWith(':sales-order') && JSON.parse(localStorage.getItem(k)).values.some(([n, v]) => n === 'items[1][quantity]' && v === '999997'))`, 'SO multi-item draft saved');
    await navigate('/sales-orders/create');
    await wait(`!!document.querySelector('[data-draft-banner]')`, 'SO banner');
    await evaluate(`[...document.querySelectorAll('[data-draft-banner] button')].find(b => b.textContent === 'Restore draft').click()`);
    await wait(`document.querySelectorAll('[data-draft-banner] li').length >= 2`, 'two stock warnings');
    const so = await evaluate(`(() => { const f = document.querySelector('form[data-draft]'); return { rows: f.querySelectorAll('[data-order-item]').length, second: f.elements['items[1][product_id]'].value, qty: f.elements['items[1][quantity]'].value,
        warnings: [...document.querySelectorAll('[data-draft-banner] li')].filter(li => /Only \\d+ unit/.test(li.textContent)).length }; })()`);
    assert.deepEqual(so, { rows: 2, second: soProducts[1], qty: '999997', warnings: 2 });
    pass('multi-item SO restored with a stock warning per line');

    // Replenishment: selections from other pages are carried into one prefilled PO; empty submit is blocked cleanly.
    await login('admin@example.test');
    await navigate('/replenishment?warehouse_id=2');
    const onPage = await evaluate(`[...document.querySelectorAll('input[name="pick[]"]')].map(b => b.value.split(':')[0])`);
    assert.ok(onPage.length >= 1, 'warehouse 2 has recommendations');
    await navigate('/purchase-orders/create');
    const offPage = await evaluate(`[...document.querySelector('[name=product_id]').options].map(o => o.value).find(v => !${JSON.stringify(onPage)}.includes(v))`);
    await navigate('/replenishment?warehouse_id=2');
    await evaluate(`sessionStorage.clear()`);
    await evaluate(`document.querySelector('[data-replenishment-selection] button[type=submit]').click()`);
    await sleep(400);
    const blocked = await evaluate(`({ path: location.pathname, loading: document.body.classList.contains('is-page-loading') })`);
    assert.deepEqual(blocked, { path: '/replenishment', loading: false });
    pass('empty replenishment selection is blocked without a stuck loader');
    await evaluate(`sessionStorage.setItem('ioms-replenishment:2', JSON.stringify({ ${JSON.stringify(offPage)}: ${JSON.stringify(offPage + ':5')} }))`);
    await navigate('/replenishment?warehouse_id=2');
    assert.match(await evaluate(`document.querySelector('[data-selection-summary]').textContent`), /1 recommendation\(s\) selected across pages/);
    await evaluate(`(() => { const box = document.querySelector('input[name="pick[]"]'); box.click(); })()`);
    assert.match(await evaluate(`document.querySelector('[data-selection-summary]').textContent`), /2 recommendation\(s\) selected/);
    await evaluate(`document.querySelector('[data-replenishment-selection] button[type=submit]').click()`);
    await wait(`location.pathname === '/purchase-orders/create' && document.readyState === 'complete'`, 'prefilled PO');
    const prefill = await evaluate(`(() => { const f = document.querySelector('form[data-order-form]'); return { products: [...f.querySelectorAll('[data-order-items] [data-order-input="product_id"]')].map(s => s.value).sort(), stored: sessionStorage.getItem('ioms-replenishment:2') }; })()`);
    assert.deepEqual(prefill, { products: [onPage[0], offPage].sort(), stored: null });
    pass('selection across pages prefills one PO and is then cleared');

    // Responsive check of the pages touched by these enhancements.
    await login('admin@example.test');
    for (const width of [360, 390, 768, 1440]) {
        await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
        for (const path of ['/reports?type=outstanding', '/replenishment?warehouse_id=2', '/purchase-orders/create', '/sales-orders/create', '/inventory-operations/create']) {
            await navigate(path);
            const overflow = await evaluate(`document.documentElement.scrollWidth > innerWidth`);
            assert.equal(overflow, false, `${path} overflows at ${width}px`);
        }
        pass(`no page-level horizontal overflow at ${width}px`);
    }

    await sleep(500);
    assert.deepEqual([...exceptions.values()], [], 'no uncaught page exceptions');
    pass('no uncaught page exceptions');
    console.log(JSON.stringify({ passed: true, checks: results.length, revokedExceptions: revoked, results }));
    ws.close();
})().catch(error => { console.error(error); process.exit(1); });
