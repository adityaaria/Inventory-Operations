const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/tables.js'), 'utf8');

test('table sorting exposes ascending and none aria-sort states', () => {
    assert.match(source, /setAttribute\(['"]aria-sort['"], ['"]ascending['"]\)/);
    assert.match(source, /setAttribute\(['"]aria-sort['"], ['"]none['"]\)/);
});

test('table search exposes an accessible label and filtered-empty row', () => {
    assert.match(source, /aria-label=['"]Search table rows['"]/);
    assert.match(source, /filtered-empty/);
});
