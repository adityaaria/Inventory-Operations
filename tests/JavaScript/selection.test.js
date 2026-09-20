const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/tables.js'), 'utf8');
const productsView = fs.readFileSync(path.join(__dirname, '../../views/products/index.php'), 'utf8');

test('table selection exposes accessible presentation-only contracts', () => {
    assert.match(source, /table-row-select/);
    assert.match(source, /table-select-all/);
    assert.match(source, /indeterminate/);
    assert.match(source, /selected count/i);
    assert.match(source, /bulk-action-bar/);
    assert.match(source, /role.*region|region.*role/);
    assert.doesNotMatch(source, /fetch\s*\(/);
});

test('products table opts into selection without changing its business form contract', () => {
    assert.match(productsView, /data-selectable="products"/);
    assert.match(productsView, /table-select-all/);
    assert.match(productsView, /table-row-select/);
    assert.match(productsView, /aria-label="Select product/);
    assert.doesNotMatch(productsView, /bulk-action.*action=|bulk-action.*fetch/i);
});
