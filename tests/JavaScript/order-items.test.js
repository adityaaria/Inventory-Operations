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
