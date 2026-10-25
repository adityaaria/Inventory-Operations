const test = require('node:test');
const assert = require('node:assert/strict');
const orderItems = require('../../public/assets/js/order-items.js');

test('duplicate product lines are flagged after their first occurrence only', () => {
    assert.deepEqual(orderItems.duplicateIndexes(['4', '7', '4', '9', '7']), [2, 4]);
    assert.deepEqual(orderItems.duplicateIndexes(['4', '', '']), []);
});

test('supplementary item names match the server items[n][field] contract and 100-item limit', () => {
    assert.equal(orderItems.itemName(3, 'purchase_price'), 'items[3][purchase_price]');
    assert.equal(orderItems.MAX_ITEMS, 100);
});

test('cross-page replenishment picks add only products not shown on the current page', () => {
    const stored = { 4: '4:10', 7: '7:3', 9: '9:1' };
    assert.deepEqual(orderItems.offPagePicks(stored, ['7', '8']), ['4:10', '9:1']);
    assert.deepEqual(orderItems.offPagePicks({}, ['7']), []);
});

test('line price shows the selected product master price and is empty without a selection', () => {
    const select = price => ({selectedOptions: price === null ? [] : [{dataset: {price}}]});
    assert.equal(orderItems.masterPrice(select('250.00')), '250.00');
    assert.equal(orderItems.masterPrice(select(null)), '');
});

test('line prices are shown in Rupiah exactly like Money::rupiah() on the server', () => {
    assert.equal(orderItems.formatRupiah('20000.00'), 'Rp 20.000,00');
    assert.equal(orderItems.formatRupiah('1234567.89'), 'Rp 1.234.567,89');
    assert.equal(orderItems.formatRupiah('250.5'), 'Rp 250,50');
    assert.equal(orderItems.formatRupiah('0'), 'Rp 0,00');
    assert.equal(orderItems.formatRupiah(''), '');
    assert.equal(orderItems.formatRupiah('not a price'), '');
});

test('negotiated price: empty or equal to master needs no reason, a different price does (D-13, ADR-019)', () => {
    assert.equal(orderItems.cents('20000'), 2000000);
    assert.equal(orderItems.cents('250.5'), 25050);
    assert.equal(orderItems.cents(''), null);
    assert.equal(orderItems.cents('-5'), null);
    assert.equal(orderItems.cents('Rp 1.000'), null);
    assert.equal(orderItems.priceDiffers('', '1000.00'), false, 'empty means master price');
    assert.equal(orderItems.priceDiffers('1000', '1000.00'), false);
    assert.equal(orderItems.priceDiffers('1000.004', '1000.00'), false, 'compared in whole cents like the server');
    assert.equal(orderItems.priceDiffers('950', '1000.00'), true);
    assert.equal(orderItems.priceDiffers('1100', '1000.00'), true);
    assert.equal(orderItems.priceDiffers('950', ''), false, 'no master price, nothing to compare');
});

test('price difference hint shows percent and Rupiah per unit in Indonesian notation', () => {
    assert.equal(orderItems.differenceLabel('1000', '1000.00'), '');
    assert.equal(orderItems.differenceLabel('950', '1000.00'), ' · differs by -5,00% (-Rp 50,00 per unit); reason required');
    assert.equal(orderItems.differenceLabel('21500', '20000.00'), ' · differs by +7,50% (+Rp 1.500,00 per unit); reason required');
    assert.equal(orderItems.differenceLabel('5', '0.00'), ' · differs by n/a (+Rp 5,00 per unit); reason required');
});

// D-16 / ADR-022: units of measure on order lines.
const units = [
    { code: 'karton', factor: 48, base: false, purchase_price: '95000.00', selling_price: '120000.00' },
    { code: 'box', factor: 12, base: false, purchase_price: '24000.00', selling_price: '31200.00' },
    { code: 'pcs', factor: 1, base: true, purchase_price: '2000.00', selling_price: '2600.00' },
];

test('quantity breakdown matches App\\Support\\QuantityFormatter', () => {
    assert.equal(orderItems.formatQuantity(101, units, 'pcs'), '2 karton + 5 pcs (101 pcs)');
    assert.equal(orderItems.formatQuantity(96, units, 'pcs'), '2 karton (96 pcs)');
    assert.equal(orderItems.formatQuantity(125, units, 'pcs'), '2 karton + 2 box + 5 pcs (125 pcs)');
    assert.equal(orderItems.formatQuantity(5, units, 'pcs'), '5 pcs');
    assert.equal(orderItems.formatQuantity(0, units, 'pcs'), '0 pcs');
    assert.equal(orderItems.formatQuantity(-48, units, 'pcs'), '-1 karton (-48 pcs)');
    assert.equal(orderItems.formatQuantity(1200, [{ code: 'botol', factor: 1, base: true }], 'botol'), '1.200 botol');
});

test('unit data is parsed defensively and quantities convert to base units', () => {
    assert.deepEqual(orderItems.parseUnits(JSON.stringify(units)).map(unit => unit.code), ['karton', 'box', 'pcs']);
    assert.deepEqual(orderItems.parseUnits('not json'), []);
    assert.deepEqual(orderItems.parseUnits('[{"code":"x","factor":0},{"code":"y","factor":2}]').map(unit => unit.code), ['y'], 'factor must be a whole number ≥ 1');
    assert.equal(orderItems.baseQuantity('3', 48), 144);
    assert.equal(orderItems.baseQuantity('7', undefined), 7);
    assert.equal(orderItems.baseQuantity('1.5', 48), null);
    assert.equal(orderItems.baseQuantity('', 48), null);
    assert.equal(orderItems.baseQuantity('0', 48), null);
});

test('a product change rebuilds the unit list with per-unit prices and the form default unit', () => {
    const document = { createElement: () => ({ dataset: {} }) };
    const select = { ownerDocument: document, options: [], replaceChildren(...options) { this.options = options; } };
    const product = { dataset: { units: JSON.stringify(units), baseUnit: 'pcs', defaultUnit: 'karton' } };
    orderItems.fillUnits(select, product, true, null);
    assert.deepEqual(select.options.map(option => [option.value, option.textContent, option.dataset.factor, option.dataset.price, option.selected]), [
        ['karton', 'karton (48 pcs)', '48', '120000.00', true],
        ['box', 'box (12 pcs)', '12', '31200.00', false],
        ['pcs', 'pcs', '1', '2600.00', false],
    ]);
    orderItems.fillUnits(select, product, false, 'box');
    assert.deepEqual(select.options.map(option => [option.value, option.dataset.price, option.selected]), [['karton', '95000.00', false], ['box', '24000.00', true], ['pcs', '2000.00', false]], 'a valid earlier choice is kept; purchase prices on PO forms');
    orderItems.fillUnits(select, { dataset: { units: '[]' } }, true, null);
    assert.equal(select.options.length, 3, 'a product without unit data leaves the list unchanged');
});
