const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/navigation.js'), 'utf8');

test('navigation exposes accessible active route and drawer contracts', () => {
    assert.match(source, /aria-current/);
    assert.match(source, /aria-expanded/);
    assert.match(source, /aria-controls/);
    assert.match(source, /main-content/);
    assert.match(source, /Escape/);
    assert.match(source, /backdrop/);
    assert.match(source, /focus\(\)/);
    assert.match(source, /auth-body/);
});
