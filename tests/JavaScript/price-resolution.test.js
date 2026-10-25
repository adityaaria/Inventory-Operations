const test = require('node:test');
const assert = require('node:assert/strict');
const resolution = require('../../public/assets/js/price-resolution.js');

// D-19 / ADR-025: live price resolution on the SO and quotation forms (customer price list tier, else master).

/** Minimal order line: product, unit select with options, quantity, price, reason, source and next-tier spans. */
function fakeRow({ unit = 'karton', listPrice = '17000.00', price = '17000.00', reason = '' } = {}) {
    const option = { value: unit, dataset: { price: listPrice } };
    const nodes = {
        '[data-order-unit]': { value: unit, selectedOptions: [option] },
        '[data-order-price]': { value: price },
        '[data-order-reason]': { value: reason },
        '[data-order-price-source]': { textContent: '' },
        '[data-order-next-tier]': { textContent: '' },
        '[data-order-input="product_id"]': { value: '10' },
        '[data-order-input="quantity"]': { value: '12' },
    };
    return { option, nodes, dataset: {}, querySelector: selector => nodes[selector] ?? null };
}

const answer = (overrides = {}) => ({
    product_id: 10, unit_code: 'karton', quantity: 12, source: 'PRICE_LIST', price: '16000.00', master_price: '17000.00', price_list_id: 1,
    price_list_name: 'Grosir', min_quantity: 10, next_tier: { min_quantity: 50, price: '15500.00' }, label: 'Price list Grosir, from 10 karton', ...overrides,
});
const rupiah = value => 'Rp ' + value;

test('the request URL carries customer, product, unit and only a positive whole quantity', () => {
    assert.equal(resolution.resolutionUrl('/api/price-resolution', { customerId: 6, productId: 10, unitCode: 'karton', quantity: '12' }),
        '/api/price-resolution?customer_id=6&product_id=10&unit_code=karton&quantity=12');
    assert.equal(resolution.resolutionUrl('/api/price-resolution', { customerId: 6, productId: 10, unitCode: '', quantity: '' }),
        '/api/price-resolution?customer_id=6&product_id=10');
    assert.equal(resolution.resolutionUrl('/api/price-resolution?x=1', { customerId: 6, productId: 10, quantity: '-2' }),
        '/api/price-resolution?x=1&customer_id=6&product_id=10');
    assert.equal(resolution.resolutionUrl('/api', { customerId: 1, productId: 2, quantity: '1.5' }), '/api?customer_id=1&product_id=2');
});

test('a price follows the list price unless the user overrode it', () => {
    assert.equal(resolution.shouldFollow('', '17000.00'), true, 'empty price');
    assert.equal(resolution.shouldFollow('17000', '17000.00'), true, 'equal in cents');
    assert.equal(resolution.shouldFollow('16500', '17000.00'), false, 'overridden');
    assert.equal(resolution.shouldFollow('abc', '17000.00'), true, 'unparseable input is replaced');
});

test('the next tier is offered with its quantity and price', () => {
    assert.equal(resolution.nextTierText(answer(), rupiah), ' · from 50 karton: Rp 15500.00');
    assert.equal(resolution.nextTierText(answer({ next_tier: null }), rupiah), '');
    assert.equal(resolution.nextTierText(null, rupiah), '');
});

test('crossing a tier updates the list price, a following price, the source and clears the reason', () => {
    const row = fakeRow({ reason: 'old' });
    assert.equal(resolution.applyResolution(row, answer(), rupiah), true);
    assert.equal(row.option.dataset.price, '16000.00', 'order-items.js reads the list price from the unit option');
    assert.equal(row.nodes['[data-order-price]'].value, '16000.00');
    assert.equal(row.nodes['[data-order-reason]'].value, '');
    assert.equal(row.nodes['[data-order-price-source]'].textContent, 'Price list Grosir, from 10 karton');
    assert.equal(row.nodes['[data-order-next-tier]'].textContent, ' · from 50 karton: Rp 15500.00');

    // Quantity rises to the next tier: the price still follows (it equals the previous list price).
    assert.equal(resolution.applyResolution(row, answer({ price: '15500.00', min_quantity: 50, next_tier: null, label: 'Price list Grosir, from 50 karton' }), rupiah), true);
    assert.equal(row.nodes['[data-order-price]'].value, '15500.00');
    assert.equal(row.nodes['[data-order-next-tier]'].textContent, '');
});

test('an overridden price and its reason are kept while the list price changes', () => {
    const row = fakeRow({ price: '15000', reason: 'Volume order' });
    assert.equal(resolution.applyResolution(row, answer(), rupiah), false);
    assert.equal(row.nodes['[data-order-price]'].value, '15000');
    assert.equal(row.nodes['[data-order-reason]'].value, 'Volume order');
    assert.equal(row.option.dataset.price, '16000.00', 'the difference is now measured against the resolved price');
});

test('an answer for another product is never applied', () => {
    const row = fakeRow();
    assert.equal(resolution.applyResolution(row, answer({ product_id: 99 }), rupiah), false);
    assert.equal(row.nodes['[data-order-price]'].value, '17000.00');
});

test('master answers and stale answers for another unit', () => {
    const row = fakeRow();
    resolution.applyResolution(row, answer({ source: 'MASTER', price: '17000.00', label: 'Master price', next_tier: { min_quantity: 1, price: '16500.00' } }), rupiah);
    assert.equal(row.nodes['[data-order-price-source]'].textContent, 'Master price');
    assert.equal(row.nodes['[data-order-next-tier]'].textContent, ' · from 1 karton: Rp 16500.00');
    const stale = fakeRow({ unit: 'pcs', listPrice: '1500.00', price: '1500.00' });
    assert.equal(resolution.applyResolution(stale, answer(), rupiah), false, 'an answer for karton never touches a pcs line');
    assert.equal(stale.nodes['[data-order-price]'].value, '1500.00');
    assert.equal(stale.option.dataset.price, '1500.00');
    assert.equal(resolution.applyResolution(fakeRow(), { error: 'x' }, rupiah), false, 'error bodies are ignored');
});

test('the form requests resolution per line and applies the answer (fetch stubbed)', async () => {
    const row = fakeRow();
    row.closest = () => row;
    const requested = [];
    const listeners = {};
    const form = {
        dataset: { priceResolution: '/api/price-resolution' },
        querySelector: selector => (selector === '[data-price-customer]' ? { value: '6' } : null),
        querySelectorAll: () => [row],
        addEventListener: (type, handler) => { listeners[type] = handler; },
    };
    const fetchStub = async url => { requested.push(url); return { ok: true, status: 200, json: async () => answer() }; };
    globalThis.Event = class { constructor(type) { this.type = type; } };
    row.nodes['[data-order-price]'].dispatchEvent = () => true;
    resolution.enhanceForm(form, fetchStub);
    assert.equal(row.nodes['[data-order-price-source]'].textContent, 'Checking list price…', 'a pending line never shows a stale source');
    await new Promise(resolve => setTimeout(resolve, resolution.DEBOUNCE_MS + 50));
    assert.deepEqual(requested, ['/api/price-resolution?customer_id=6&product_id=10&unit_code=karton&quantity=12']);
    assert.equal(row.nodes['[data-order-price]'].value, '16000.00');
    assert.ok(listeners.change && listeners.input, 'listens for customer, product, unit and quantity changes');

    // A 422 (for example a tier below cost) is shown next to the price instead of changing it.
    const failing = async () => ({ ok: false, status: 422, json: async () => ({ error: 'Price list below cost' }) });
    const second = fakeRow();
    const form2 = { ...form, dataset: { priceResolution: '/api/price-resolution' }, querySelectorAll: () => [second] };
    resolution.enhanceForm(form2, failing);
    await new Promise(resolve => setTimeout(resolve, resolution.DEBOUNCE_MS + 50));
    assert.equal(second.nodes['[data-order-price-source]'].textContent, 'List price unavailable: Price list below cost');
    assert.equal(second.nodes['[data-order-price]'].value, '17000.00');
});
