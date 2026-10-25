'use strict';
// D-19 / ADR-025: live price resolution on the SO form and the printable surat jalan in a real browser (Chrome DevTools
// Protocol). Usage (disposable seeded stack only):
//   node tests/Browser/price-resolution.cjs appUrl devtoolsUrl customerId productId unitCode firstPrice tierMin tierPrice deliveryNoteId
// customerId has a price list whose tiers for productId/unitCode are firstPrice from 1 and tierPrice from tierMin.
const assert = require('node:assert/strict');
const [appUrl, devtoolsUrl, customerId, productId, unitCode, firstPrice, tierMin, tierPrice, deliveryNoteId] = process.argv.slice(2);
const url = (appUrl || '').replace(/\/$/, '');
const devtools = (devtoolsUrl || 'http://127.0.0.1:9226').replace(/\/$/, '');
if (!/^http:\/\/localhost:180\d\d$/.test(url)) throw Error('Refusing to run outside a disposable localhost:180xx stack');

(async () => {
    const tabs = await (await fetch(devtools + '/json')).json();
    const ws = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
    await new Promise(resolve => { ws.onopen = resolve; });
    let id = 0;
    const pending = new Map();
    const exceptions = new Map();
    ws.onmessage = event => {
        const message = JSON.parse(event.data);
        if (message.id) {
            const request = pending.get(message.id);
            pending.delete(message.id);
            message.error ? request.reject(Error(message.error.message)) : request.resolve(message.result);
        } else if (message.method === 'Runtime.exceptionThrown') {
            exceptions.set(message.params.exceptionDetails.exceptionId, message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
        } else if (message.method === 'Runtime.exceptionRevoked') {
            exceptions.delete(message.params.exceptionId);
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
        await sleep(300);
    }
    async function login(email) {
        await navigate('/login');
        await evaluate(`fetch('/logout', {method: 'POST', body: new URLSearchParams({csrf_token: document.querySelector('[name=csrf_token]').value})}).catch(() => null)`);
        await navigate('/login');
        const status = await evaluate(`fetch('/login', {method: 'POST', redirect: 'manual', body: new URLSearchParams({email: ${JSON.stringify(email)}, password: 'password', csrf_token: document.querySelector('[name=csrf_token]').value})}).then(r => r.type === 'opaqueredirect' ? 302 : r.status)`);
        assert.equal(status, 302, 'login ' + email);
    }
    const results = [];
    const pass = name => { results.push(name); console.log('ok - ' + name); };
    await call('Page.enable');
    await call('Runtime.enable');
    await call('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    await login('sales1@example.test');

    // SO form: the customer's price list tier replaces the list price as the quantity crosses a tier.
    await navigate('/sales-orders/create');
    await wait(`document.querySelector('form[data-price-resolution]')?.dataset.priceResolutionEnhanced === 'true'`, 'price resolution enhanced');
    const set = (selector, value) => evaluate(`(() => { const field = document.querySelector(${JSON.stringify(selector)}); field.value = ${JSON.stringify(value)}; field.dispatchEvent(new Event('change', {bubbles: true})); field.dispatchEvent(new Event('input', {bubbles: true})); return field.value; })()`);
    await set('[data-price-customer]', customerId);
    await set('[data-order-items] [data-order-input="product_id"]', productId);
    await set('[data-order-items] [data-order-unit]', unitCode);
    const line = `[data-order-items] [data-order-item]`;
    const state = () => evaluate(`({price: document.querySelector('${line} [data-order-price]').value, source: document.querySelector('${line} [data-order-price-source]').textContent, next: document.querySelector('${line} [data-order-next-tier]').textContent, list: document.querySelector('${line} [data-order-master]').textContent, reasonRequired: document.querySelector('${line} [data-order-reason]').required})`);
    await set('[data-order-items] [data-order-input="quantity"]', String(Number(tierMin) - 1));
    await wait(`document.querySelector('${line} [data-order-price]').value === ${JSON.stringify(firstPrice)} && document.querySelector('${line} [data-order-price-source]').textContent.includes('from 1 ')`, 'first tier');
    let now = await state();
    assert.equal(now.price, firstPrice, 'below the second tier: the first tier price');
    assert.match(now.next, new RegExp('from ' + tierMin + ' '));
    pass('Below the tier the first price-list tier applies and the next tier is offered');
    await set('[data-order-items] [data-order-input="quantity"]', tierMin);
    await wait(`document.querySelector('${line} [data-order-price]').value === ${JSON.stringify(tierPrice)} && document.querySelector('${line} [data-order-price-source]').textContent.includes('from ' + ${JSON.stringify(tierMin)} + ' ')`, 'tier price after crossing');
    now = await state();
    assert.match(now.source, new RegExp('from ' + tierMin + ' '));
    assert.equal(now.reasonRequired, false, 'the resolved price needs no reason');
    pass('Crossing the tier updates the price and its source live');
    await evaluate(`(() => { const price = document.querySelector('${line} [data-order-price]'); price.value = '1'; price.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    await set('[data-order-items] [data-order-input="quantity"]', String(Number(tierMin) - 1));
    await wait(`!document.querySelector('${line} [data-order-price-source]').textContent.startsWith('Checking')`, 'answer after the override');
    now = await state();
    assert.equal(now.price, '1', 'an overridden price is never replaced');
    assert.equal(now.reasonRequired, true, 'an override needs a reason');
    pass('An overridden price is kept and requires a reason');

    // Printable pages: no navigation chrome, a Print button and print CSS.
    await login('warehouse1@example.test');
    await navigate('/delivery-notes/show?id=' + deliveryNoteId);
    assert.equal(await evaluate(`Boolean(document.querySelector('[data-print]')) && !document.querySelector('.sidebar') && [...document.styleSheets].some(sheet => (sheet.href || '').endsWith('/assets/css/print.css'))`), true);
    assert.match(await evaluate(`document.querySelector('h1').textContent`), /SURAT JALAN/);
    for (const width of [360, 768]) {
        await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 600 });
        await navigate('/delivery-notes/show?id=' + deliveryNoteId);
        assert.equal(await evaluate(`document.documentElement.scrollWidth <= window.innerWidth + 1`), true, 'no page overflow at ' + width);
    }
    pass('Printable surat jalan has a Print button, print CSS and no overflow at 360/768 px');
    assert.deepEqual([...exceptions.values()], [], 'no page exceptions');
    pass('No page exceptions');
    console.log(JSON.stringify({ passed: results.length, failed: 0 }));
    ws.close();
})().catch(error => { console.error('not ok - ' + error.message); process.exit(1); });
