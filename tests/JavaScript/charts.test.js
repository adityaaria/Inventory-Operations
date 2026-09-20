const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/charts.js'), 'utf8');

test('chart renderer exposes accessible roles for populated and empty states', () => {
    assert.match(source, /setAttribute\('role', 'status'\)/);
    assert.match(source, /setAttribute\('role', 'img'\)/);
    assert.match(source, /aria-label/);
});
