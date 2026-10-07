'use strict';
// UI convention sweep over the main pages through Chrome DevTools Protocol (disposable seeded stack only).
// Usage: node tests/Browser/ui-sweep.cjs [appUrl] [devtoolsUrl]
const url = (process.argv[2] || 'http://localhost:18091').replace(/\/$/, '');
const devtools = (process.argv[3] || 'http://127.0.0.1:9226').replace(/\/$/, '');
if (!/^http:\/\/localhost:180\d\d$/.test(url)) throw Error('Refusing to run outside a disposable localhost:180xx stack');

(async () => {
    const tabs = await (await fetch(devtools + '/json')).json();
    const ws = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
    await new Promise(resolve => { ws.onopen = resolve; });
    let id = 0;
    const pending = new Map();
    ws.onmessage = event => { const m = JSON.parse(event.data); if (m.id) { pending.get(m.id)(m.result || m.error); pending.delete(m.id); } };
    const call = (method, params = {}) => new Promise(resolve => { const n = ++id; pending.set(n, resolve); ws.send(JSON.stringify({ id: n, method, params })); });
    const evaluate = async expression => (await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.value;
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const go = async path => { await call('Page.navigate', { url: url + path }); for (let i = 0; i < 80; i++) { if (await evaluate(`document.readyState === 'complete'`)) break; await sleep(100); } await sleep(600); };

    await call('Page.enable');
    await call('Emulation.setDeviceMetricsOverride', { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });
    await go('/login');
    await evaluate(`fetch('/login', {method: 'POST', body: new URLSearchParams({email: 'admin@example.test', password: 'password', csrf_token: document.querySelector('[name=csrf_token]').value})})`);
    await go('/sales-orders');
    const soDetail = await evaluate(`document.querySelector('a[href^="/sales-orders/show"]').getAttribute('href')`);
    const pages = ['/dashboard', '/work-queue', '/products', '/purchase-orders', '/sales-orders', '/reports', '/reports?type=outstanding', '/inventory-operations', '/replenishment', '/users', '/audit-trail', '/categories', '/warehouses', '/suppliers', '/customers', soDetail];
    const fails = [];
    for (const path of pages) {
        await go(path);
        const issues = await evaluate(`(() => {
            const out = [];
            const underlined = [...document.querySelectorAll('a, button')].filter(el => getComputedStyle(el).textDecorationLine.includes('underline')).map(el => el.textContent.trim().slice(0, 20));
            if (underlined.length) out.push('underlined: ' + underlined.join(', '));
            const meaningless = [...document.querySelectorAll('th.sortable')].filter(th => /^(actions?|select|)$/i.test(th.textContent.trim())).length;
            if (meaningless) out.push('sortable action/select/blank headers: ' + meaningless);
            const filters = document.querySelector('main .filters');
            if (filters) {
                const next = filters.nextElementSibling;
                if (next && next.classList.contains('table-scroll')) {
                    const radius = getComputedStyle(next.querySelector('.data-table')).borderTopLeftRadius;
                    if (radius !== '0px') out.push('table not attached to filters, radius ' + radius);
                } else if (document.querySelector('main .table-scroll .data-table')) {
                    out.push('filters not adjacent to table: next=' + (next ? next.className || next.tagName : 'none'));
                }
            }
            if (document.querySelector('select[name=direction]')) out.push('separate Order select present');
            const timeline = [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'Transaction Timeline');
            if (timeline && !timeline.closest('.page-header')) out.push('timeline outside page header');
            const text = getComputedStyle(document.querySelector('main')).color;
            const plain = [...document.querySelectorAll('main a')].filter(a => !a.classList.contains('sort-link') && a.offsetParent !== null).filter(a => {
                const c = getComputedStyle(a);
                return c.color === text && parseFloat(c.borderTopWidth) === 0 && c.backgroundColor === 'rgba(0, 0, 0, 0)' && Number(c.fontWeight) < 600;
            }).map(a => a.textContent.trim().slice(0, 18));
            if (plain.length) out.push('links look like body text: ' + [...new Set(plain)].slice(0, 6).join(', '));
            if (document.documentElement.scrollWidth > innerWidth) out.push('horizontal page overflow');
            return out;
        })()`);
        if (issues.length) fails.push({ page: path, issues });
    }
    console.log(JSON.stringify({ passed: fails.length === 0, pages: pages.length, fails }, null, 1));
    ws.close();
    if (fails.length) process.exit(1);
})().catch(error => { console.error(error); process.exit(1); });
