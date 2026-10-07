const test = require('node:test');
const assert = require('node:assert/strict');
const drafts = require('../../public/assets/js/form-drafts.js');

function memoryStorage(entries = {}) {
    const data = new Map(Object.entries(entries));
    return {
        get length() { return data.size; },
        key: index => [...data.keys()][index] ?? null,
        getItem: key => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => data.set(key, String(value)),
        removeItem: key => data.delete(key),
        keys: () => [...data.keys()],
    };
}

test('drafts are keyed per user, role and form', () => {
    assert.equal(drafts.storageKey('7:Sales', 'sales-order'), 'ioms-draft:v1:7:Sales:sales-order');
    assert.notEqual(drafts.storageKey('7:Sales', 'sales-order'), drafts.storageKey('7:Admin', 'sales-order'));
});

test('secrets, idempotency keys and stale count baselines are never stored', () => {
    for (const name of ['csrf_token', 'operation_key', 'password', 'password_confirmation', 'baseline', 'items[3][baseline]']) assert.equal(drafts.draftable(name, 'text'), false, name);
    assert.equal(drafts.draftable('reason', 'textarea'), true);
    assert.equal(drafts.draftable('items[2][quantity]', 'number'), true);
    assert.equal(drafts.draftable('anything', 'hidden'), false);
    assert.equal(drafts.draftable('anything', 'password'), false);
});

test('drafts expire with the session limit (default 8 hours) and reject future or malformed timestamps', () => {
    const now = Date.UTC(2026, 9, 7, 12);
    assert.equal(drafts.TTL_MS, 8 * 60 * 60 * 1000);
    assert.equal(drafts.ttlFrom('28800'), 28800000);
    assert.equal(drafts.ttlFrom('3600'), 3600000);
    for (const invalid of [undefined, '', 'abc', '10', String(30 * 24 * 3600)]) assert.equal(drafts.ttlFrom(invalid), drafts.TTL_MS, String(invalid));
    assert.equal(drafts.isExpired(now - drafts.TTL_MS + 1000, now), false);
    assert.equal(drafts.isExpired(now - drafts.TTL_MS - 1, now), true);
    assert.equal(drafts.isExpired(now - 2 * 3600000, now, 3600000), true);
    assert.equal(drafts.isExpired(now + 3600000, now), true);
    assert.equal(drafts.isExpired(undefined, now), true);
});

test('supplementary item indexes are rebuilt onto newly added rows', () => {
    const names = ['product_id', 'items[7][product_id]', 'items[7][quantity]', 'items[2][product_id]', 'reason'];
    assert.deepEqual(drafts.itemIndexes(names), [2, 7]);
    const mapping = new Map([[2, 1], [7, 2]]);
    assert.equal(drafts.remapName('items[7][quantity]', mapping), 'items[2][quantity]');
    assert.equal(drafts.remapName('items[9][quantity]', mapping), 'items[9][quantity]');
    assert.equal(drafts.remapName('reason', mapping), 'reason');
});

test('server check blocks inactive master data and warns about outbound stock shortfalls only', () => {
    const lines = [
        { productId: 1, quantity: 5, outbound: true, field: 'a', label: 'SKU-1' },
        { productId: 2, quantity: 3, outbound: true, field: 'b', label: 'SKU-2' },
        { productId: 3, quantity: 9, outbound: false, field: 'c', label: 'SKU-3' },
    ];
    const messages = drafts.checkMessages({
        warehouse: { id: 1, active: false },
        items: [{ product_id: 1, active: true, available: 2 }, { product_id: 2, active: false, available: 10 }, { product_id: 3, active: true, available: 0 }],
    }, lines, line => line.label);
    assert.deepEqual(messages.map(message => [message.blocking, message.field]), [[true, 'warehouse_id'], [false, 'a'], [true, 'b']]);
    assert.match(messages[1].text, /Only 2 unit\(s\) of SKU-1/);
    assert.deepEqual(drafts.checkMessages({ warehouse: { id: 1, active: true }, items: [{ product_id: 1, active: true, available: 5 }] }, lines, line => line.label), []);
});

test('logout clearing removes only this application\'s drafts; housekeeping drops expired ones', () => {
    const now = Date.now();
    const storage = memoryStorage({
        'ioms-draft:v1:1:Admin:purchase-order': JSON.stringify({ savedAt: now, values: [] }),
        'ioms-draft:v1:2:Sales:sales-order': JSON.stringify({ savedAt: now - drafts.TTL_MS - 5, values: [] }),
        'ioms-draft:v1:4:Admin:stock-proposal': JSON.stringify({ savedAt: now - 2 * 3600000, ttl: 3600000, values: [] }),
        'ioms-draft:v1:3:Sales:sales-order': '{broken',
        'theme': 'dark',
    });
    drafts.purgeExpired(storage, now);
    assert.deepEqual(storage.keys().sort(), ['ioms-draft:v1:1:Admin:purchase-order', 'theme']);
    drafts.clearAll(storage);
    assert.deepEqual(storage.keys(), ['theme']);
    assert.doesNotThrow(() => drafts.clearAll(null));
});
