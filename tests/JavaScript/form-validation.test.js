const test = require('node:test');
const assert = require('node:assert/strict');
const Validation = require('../../public/assets/js/form-validation.js');

test('required fields report an error when empty and clear it when filled', () => {
    const field = {name: 'name', required: true, value: ''};
    assert.equal(Validation.validateField(field), 'Name is required.');

    field.value = 'Widget';
    assert.equal(Validation.validateField(field), null);
});

test('number fields enforce valid values and min/max constraints', () => {
    assert.equal(Validation.validateField({name: 'quantity', type: 'number', value: 'abc'}), 'Quantity must be a valid number.');
    assert.equal(Validation.validateField({name: 'quantity', type: 'number', min: '0', value: '-1'}), 'Quantity must be at least 0.');
    assert.equal(Validation.validateField({name: 'quantity', type: 'number', max: '10', value: '11'}), 'Quantity must be at most 10.');
});

test('optional empty fields are accepted', () => {
    assert.equal(Validation.validateField({name: 'notes', value: ''}), null);
});

test('validateFields returns field errors keyed by field name', () => {
    const errors = Validation.validateFields([
        {name: 'name', required: true, value: ''},
        {name: 'quantity', type: 'number', min: '0', value: '-2'},
    ]);

    assert.deepEqual(errors, {
        name: 'Name is required.',
        quantity: 'Quantity must be at least 0.',
    });
});

 test('required checkbox needs explicit confirmation', () => {
    assert.ok(Validation.validateField({name: 'fit_for_stock', type: 'checkbox', value: '1', required: true, checked: false}));
    assert.equal(Validation.validateField({name: 'fit_for_stock', type: 'checkbox', value: '1', required: true, checked: true}), null);
 });
