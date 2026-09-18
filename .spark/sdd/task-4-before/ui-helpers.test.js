const test = require('node:test');
const assert = require('node:assert/strict');
const UiHelpers = require('../../public/assets/js/ui-helpers.js');

test('confirmation is required only for state-changing action paths', () => {
    assert.equal(UiHelpers.needsConfirmation('/sales-orders/12/approve'), true);
    assert.equal(UiHelpers.needsConfirmation('/products/12/edit'), false);
    assert.equal(UiHelpers.needsConfirmation('/products/12'), false);
});

test('filter matching ignores surrounding whitespace and letter case', () => {
    assert.equal(UiHelpers.matchesFilter('  Blue Widget  ', ' blue '), true);
    assert.equal(UiHelpers.matchesFilter('Blue Widget', 'red'), false);
    assert.equal(UiHelpers.matchesFilter('Blue Widget', ''), true);
});

test('table values sort numeric values numerically and text values alphabetically', () => {
    assert.equal(UiHelpers.compareTableValues('Stock 2', 'Stock 10', 'asc') < 0, true);
    assert.equal(UiHelpers.compareTableValues('Warehouse B', 'Warehouse A', 'asc') > 0, true);
    assert.equal(UiHelpers.compareTableValues('Warehouse A', 'Warehouse B', 'desc') > 0, true);
});

test('CSV cells escape quotes and normalize whitespace', () => {
    assert.equal(UiHelpers.escapeCsvCell('  A "quoted"\nvalue  '), '"A ""quoted"" value"');
});

test('debounce keeps only the latest call and can cancel pending work', () => {
    const scheduled = new Map();
    let nextId = 0;
    const calls = [];
    const debounced = UiHelpers.debounce((value) => calls.push(value), 250, {
        setTimeout(callback) {
            const id = ++nextId;
            scheduled.set(id, callback);
            return id;
        },
        clearTimeout(id) {
            scheduled.delete(id);
        },
    });

    debounced('old');
    debounced('latest');
    assert.deepEqual(calls, []);
    assert.equal(scheduled.size, 1);

    const [timerId, timer] = scheduled.entries().next().value;
    scheduled.delete(timerId);
    timer();
    assert.deepEqual(calls, ['latest']);

    debounced('cancelled');
    debounced.cancel();
    assert.equal(scheduled.size, 0);
});
