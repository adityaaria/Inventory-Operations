const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/tables.js'), 'utf8');

test('table sorting exposes ascending and none aria-sort states', () => {
    assert.match(source, /setAttribute\(['"]aria-sort['"], ['"]ascending['"]\)/);
    assert.match(source, /setAttribute\(['"]aria-sort['"], ['"]none['"]\)/);
});

test('export joins the page header toolbar without duplicate search or filter submission', () => {
    for (const hasToolbar of [true, false]) {
        const created = [];
        const makeElement = (tag) => ({
            tag, dataset: {}, children: [], attributes: {}, listeners: {},
            append(child) { this.children.push(child); },
            setAttribute(name, value) { this.attributes[name] = value; },
            addEventListener(name, callback) { this.listeners[name] = callback; },
        });
        let toolbar = hasToolbar ? makeElement('nav') : null;
        const header = {
            querySelector() { return toolbar; },
            append(element) { toolbar = element; },
        };
        const page = {querySelector(selector) { return selector === '.page-header' ? header : null; }};
        const table = {id: 'products', dataset: {}, closest() { return page; }, querySelectorAll() { return []; }};
        const context = {
            document: {
                querySelectorAll() { return [table]; },
                createElement(tag) { const element = makeElement(tag); created.push(element); return element; },
            },
        };
        vm.runInNewContext(source, context);
        const tables = context.InventoryTables.create();
        tables.enhance();
        tables.enhance();
        assert.equal(toolbar.children.length, 1);
        const button = toolbar.children[0];
        assert.equal(button.tag, 'button');
        assert.equal(button.type, 'button');
        assert.equal(button.textContent, 'Export CSV');
        assert.equal(button.dataset.exportTable, table.id);
        assert.equal(typeof button.listeners.click, 'function');
        assert.ok(created.every(element => element.tag !== 'input'));
        if (!hasToolbar) assert.equal(toolbar.attributes['aria-label'], 'Table actions');
    }
});

test('server reports keep the full-result export instead of generating a page-only export', () => {
    const table = {id:'report-records',dataset:{export:'server'},querySelectorAll:()=>[]};
    const context = {document:{querySelectorAll:()=>[table],createElement(){throw new Error('Report export belongs to the server');}}};
    vm.runInNewContext(source,context);
    context.InventoryTables.create().enhance();
    assert.equal(table.dataset.enhanced,'true');
});

test('client sorting skips action, blank and opted-out headers so no meaningless arrows appear', () => {
    assert.match(source, /'noSort' in th\.dataset \|\| \/\^\(actions\?\|\)\$\/i\.test\(th\.textContent\.trim\(\)\)/);
    const skip = text => /^(actions?|)$/i.test(text.trim());
    assert.deepEqual(['Action', 'Actions', ' ', 'Status', 'Action Date'].map(skip), [true, true, true, false, false]);
});

test('table regions get a keyboard tab stop only while they actually scroll', () => {
    const context = {document: {querySelectorAll() { return []; }}};
    vm.runInNewContext(source, context);
    const region = {
        scrollWidth: 900, clientWidth: 400, scrollHeight: 300, clientHeight: 300, attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
    let observed = null;
    const view = {ResizeObserver: class { constructor(callback) { observed = callback; } observe(target) { assert.equal(target, region); } }};

    context.InventoryTables.watchScrollRegions({querySelectorAll: (selector) => (selector === '.table-scroll[aria-label]' ? [region] : [])}, view);
    assert.equal(region.attributes.tabindex, '0');

    region.clientWidth = 900; // widened viewport: the table now fits
    observed();
    assert.equal('tabindex' in region.attributes, false);

    const listeners = {};
    const fallback = {...region, clientWidth: 100, attributes: {}};
    context.InventoryTables.watchScrollRegions({querySelectorAll: () => [fallback]}, {addEventListener: (name, callback) => { listeners[name] = callback; }});
    assert.equal(fallback.attributes.tabindex, '0');
    fallback.clientWidth = 900;
    listeners.resize();
    assert.equal('tabindex' in fallback.attributes, false);
});
