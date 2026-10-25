'use strict';
// D-18 / ADR-024: keyboard-wedge barcode scanning in a real browser (Chrome DevTools Protocol). The scanner is simulated
// with real key events: every character as keyDown/keyUp into the focused field, then Enter — what USB/Bluetooth
// scanners send. Usage (disposable seeded stack only):
//   node tests/Browser/barcode-scan.cjs appUrl devtoolsUrl poId poBarcode soId soBarcode countId countBarcode
const assert = require('node:assert/strict');
const [appUrl, devtoolsUrl, poId, poBarcode, soId, soBarcode, countId, countBarcode] = process.argv.slice(2);
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
    /** Keyboard wedge: focus the scan field, type each character as a key event, then press Enter. */
    async function scan(code) {
        await evaluate(`document.querySelector('[data-scan-input]').focus()`);
        for (const char of code) {
            await call('Input.dispatchKeyEvent', { type: 'keyDown', text: char, key: char, unmodifiedText: char });
            await call('Input.dispatchKeyEvent', { type: 'keyUp', key: char });
        }
        await call('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 });
        await call('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 });
    }
    const status = () => evaluate(`({text: document.querySelector('[data-scan-status]').textContent, tone: document.querySelector('[data-scan-status]').dataset.tone || ''})`);
    async function waitStatus(predicate, label) {
        const until = Date.now() + 8000;
        while (Date.now() < until) { const value = await status(); if (predicate(value)) return value; await sleep(100); }
        throw Error('Timed out waiting for scan status: ' + label + ' (last: ' + JSON.stringify(await status()) + ')');
    }
    const results = [];
    const pass = name => { results.push(name); console.log('ok - ' + name); };

    await call('Page.enable');
    await call('Runtime.enable');
    await call('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    await login('warehouse1@example.test');

    // PO receive: one scan of the line's unit barcode adds one line unit; unknown codes change nothing.
    await navigate('/purchase-orders/show?id=' + poId);
    assert.equal(await evaluate(`Boolean(document.querySelector('[data-scan-receive] [data-scan-input]'))`), true);
    await wait(`document.querySelector('[data-barcode-scanner]').dataset.scanReady === '1'`, 'scanner ready');
    const cameraSupported = await evaluate(`'BarcodeDetector' in window && Boolean(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)`);
    assert.equal(await evaluate(`document.querySelector('[data-scan-camera]').hidden`), !cameraSupported, 'camera button only with BarcodeDetector + getUserMedia (supported here: ' + cameraSupported + ')');
    console.log('# BarcodeDetector camera path available in this browser: ' + cameraSupported);
    pass('PO detail shows the scan panel; the camera button follows BarcodeDetector support');
    const lineSelector = `[data-scan-line] input[name="quantity"]`;
    const lineValue = () => evaluate(`[...document.querySelectorAll('[data-scan-line]')].map(form => form.querySelector('input[name="quantity"]').value).join('|')`);
    const before = await lineValue();
    await scan(poBarcode);
    await waitStatus(value => value.tone === 'success', 'receive scan');
    const after = await lineValue();
    assert.notEqual(after, before);
    assert.ok(after.split('|').includes('1'), 'the matching line now receives 1 unit: ' + after);
    assert.equal(await evaluate(`document.activeElement === document.querySelector('[data-scan-input]') && document.querySelector('[data-scan-input]').value === ''`), true, 'focus stays in the cleared scan field');
    pass('Typing a barcode + Enter increments the matching receive line by one unit');
    await scan(poBarcode);
    await waitStatus(value => value.tone === 'success' && / 2 of /.test(value.text), 'second receive scan');
    assert.ok((await lineValue()).split('|').includes('2'));
    pass('A second scan increments the line again');
    await scan('0000000000000');
    const unknown = await waitStatus(value => value.tone === 'error', 'unknown barcode');
    assert.match(unknown.text, /Unknown barcode 0000000000000/);
    assert.ok((await lineValue()).split('|').includes('2'), 'unknown barcode changes nothing');
    await scan('8991000202613');
    assert.match((await waitStatus(value => /check digit/.test(value.text), 'bad check digit')).text, /check digit/);
    pass('Unknown and misread barcodes show a visible error and change nothing');
    assert.equal(await evaluate(`document.querySelector(${JSON.stringify(lineSelector)}) !== null`), true);

    // SO pick: start scan to pick, the issue quantity follows the picked count.
    await navigate('/sales-orders/show?id=' + soId);
    await evaluate(`document.querySelector('[data-pick-toggle]').click()`);
    await wait(`document.querySelector('[data-pick-toggle]').getAttribute('aria-pressed') === 'true' && [...document.querySelectorAll('[data-pick-line]')].every(input => input.value === '0')`, 'pick mode resets issue quantities');
    await scan(soBarcode);
    await waitStatus(value => value.tone === 'success' || value.tone === 'warning', 'pick scan');
    const picked = await evaluate(`[...document.querySelectorAll('[data-pick-line]')].map(input => input.value)`);
    assert.ok(picked.some(value => Number(value) >= 1), 'one line picked: ' + picked);
    assert.match(await evaluate(`[...document.querySelectorAll('.pick-badge')].filter(badge => !badge.hidden).map(badge => badge.textContent).join(' ')`), /Picked \d+ of \d+/);
    pass('Scan to pick counts the line and prefills the issue quantity');

    // Count sheet: the seed's open count; a scan adds to the only lot line of the product.
    await navigate('/stock-counts/show?id=' + countId);
    assert.equal(await evaluate(`document.querySelector('[data-expected]')`), null, 'blind sheet');
    await scan(countBarcode);
    await waitStatus(value => value.tone === 'success' || value.tone === 'warning', 'count scan');
    assert.ok(await evaluate(`[...document.querySelectorAll('[data-count-quantity]')].some(input => input.value === '1')`), 'one counted unit');
    pass('Scanning on the blind count sheet adds to the lot line');

    for (const width of [360, 768]) {
        await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 600 });
        await navigate('/purchase-orders/show?id=' + poId);
        assert.equal(await evaluate(`document.documentElement.scrollWidth <= window.innerWidth + 1 || getComputedStyle(document.body).overflowX !== 'visible'`), true, 'no page overflow at ' + width);
    }
    pass('Scan panel fits at 360 and 768 px');
    assert.deepEqual([...exceptions.values()], [], 'no page exceptions');
    pass('No page exceptions');
    console.log(JSON.stringify({ passed: results.length, failed: 0 }));
    ws.close();
})().catch(error => { console.error('not ok - ' + error.message); process.exit(1); });
