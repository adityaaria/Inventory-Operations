'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {escapeCsvCell} = require('../../public/assets/js/ui-helpers.js');

test('CSV export neutralizes formula-leading values after whitespace normalization', () => {
    for (const value of ['=1+1', '+SUM(1,2)', '-1+2', '@SUM(1,2)', ' \t=1+1', '\r\n@SUM(1,2)', '\u0000=1+1']) {
        assert.ok(escapeCsvCell(value).startsWith('"\''), value);
    }
    assert.equal(escapeCsvCell('Widget "A"'), '"Widget ""A"""');
});
