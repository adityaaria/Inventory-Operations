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

test('resolveCancelTarget closes the modal when the cancel button is inside one', () => {
    assert.deepEqual(UiHelpers.resolveCancelTarget('/products', true), {action: 'close-modal'});
});

test('resolveCancelTarget navigates to the cancel href when not inside a modal', () => {
    assert.deepEqual(UiHelpers.resolveCancelTarget('/products', false), {action: 'navigate', href: '/products'});
});

test('HTML escaping neutralizes every markup-significant character', () => {
    assert.equal(
        UiHelpers.escapeHtml(`<a href="x" title='y'>Tom & Jerry</a>`),
        '&lt;a href=&quot;x&quot; title=&#039;y&#039;&gt;Tom &amp; Jerry&lt;/a&gt;',
    );
    assert.equal(UiHelpers.escapeHtml('&lt;'), '&amp;lt;');
    assert.equal(UiHelpers.escapeHtml(42), '42');
});

test('debounce uses the real timers when no timer API is injected', async () => {
    const calls = [];
    const debounced = UiHelpers.debounce((value) => calls.push(value), 5);

    debounced('first');
    debounced('second');
    await new Promise((resolve) => setTimeout(resolve, 30));
    assert.deepEqual(calls, ['second']);

    debounced('cancelled');
    debounced.cancel();
    await new Promise((resolve) => setTimeout(resolve, 30));
    assert.deepEqual(calls, ['second']);
});

test('table sorting reads Rupiah amounts with dot thousands and comma decimals', () => {
    assert.equal(UiHelpers.compareTableValues('Rp 9.100,00', 'Rp 20.000,00') < 0, true);
    assert.equal(UiHelpers.compareTableValues('Rp 250,50', 'Rp 250,05') > 0, true);
    assert.equal(UiHelpers.compareTableValues('Rp 1.000.000,00', 'Rp 999.999,99', 'desc') < 0, true);
});
