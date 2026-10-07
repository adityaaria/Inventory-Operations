const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup() {
    const classes = new Set();
    const attributes = {};
    const timers = new Map();
    const events = {};
    let id = 0;
    const context = {
        InventoryHttp: {createRequestCoordinator: () => ({})},
        document: {
            body: {classList: {add: value => classes.add(value), remove: value => classes.delete(value)}},
            querySelector: () => ({setAttribute: (key, value) => { attributes[key] = value; }}),
        },
        addEventListener: (name, callback) => { events[name] = callback; },
        setTimeout: (callback, delay) => { timers.set(++id, {callback, delay}); return id; },
        clearTimeout: key => timers.delete(key),
    };
    vm.runInNewContext(fs.readFileSync('public/assets/js/modal.js', 'utf8'), context);
    return {modal: context.InventoryModal.create({state: {}}), classes, attributes, timers, events};
}

test('fast navigation and cancelled loading never flash an indicator', () => {
    const {modal, classes, timers, attributes} = setup();
    modal.setPageLoading(true);
    assert.equal(classes.size, 0);
    assert.equal([...timers.values()][0].delay, 180);
    modal.setPageLoading(false);
    assert.equal(timers.size, 0);
    assert.equal(classes.size, 0);
    assert.equal(attributes['aria-hidden'], 'true');
});

test('slow navigation shows feedback once and Back restores an idle page', () => {
    const {modal, classes, timers, attributes, events} = setup();
    modal.setPageLoading(true);
    modal.setPageLoading(true);
    assert.equal(timers.size, 1);
    [...timers.values()][0].callback();
    assert.ok(classes.has('is-page-loading'));
    assert.equal(attributes['aria-hidden'], 'false');
    events.pageshow();
    assert.equal(classes.size, 0);
    assert.equal(attributes['aria-hidden'], 'true');
    modal.setPageLoading(true);
    assert.ok(timers.size >= 1);
});
