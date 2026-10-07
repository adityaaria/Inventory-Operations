const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('enhancement preserves server-rendered layout and initializes the mobile drawer once', () => {
    function element() {
        const classes = new Set();
        return {events: {}, attributes: {}, classList: {
            add: value => classes.add(value), remove: value => classes.delete(value), contains: value => classes.has(value),
        }, addEventListener(name, fn) { assert.equal(this.events[name], undefined); this.events[name] = fn; },
        setAttribute(name, value) { this.attributes[name] = value; }, focus() { this.focused = true; }};
    }
    const sidebar = element(), toggle = element(), backdrop = element(), body = element(), close = element();
    const media = {matches: true, addEventListener(name, callback) { this.change = callback; }};
    const shell = {dataset: {}, querySelector: selector => ({'.sidebar':sidebar,'.sidebar-toggle':toggle,'.sidebar-backdrop':backdrop,'.sidebar-close':close})[selector]};
    const document = {body, events: {}, querySelector: () => shell, addEventListener(name, fn) { this.events[name] = fn; }};
    const context = {document, matchMedia: () => media};
    vm.runInNewContext(fs.readFileSync('public/assets/js/navigation.js', 'utf8'), context);
    context.InventoryNavigation.buildShell();
    context.InventoryNavigation.buildShell();
    assert.equal(sidebar.inert, true);
    assert.equal(sidebar.attributes['aria-hidden'], 'true');
    toggle.events.click();
    assert.equal(toggle.attributes['aria-expanded'], 'true');
    assert.ok(sidebar.classList.contains('is-open'));
    assert.equal(sidebar.inert, false);
    assert.equal(close.focused, true);
    const first = element(), last = element();
    first.getClientRects = last.getClientRects = () => [{}];
    sidebar.querySelectorAll = () => [first, last];
    document.activeElement = last;
    let trapped = false;
    document.events.keydown({key:'Tab', preventDefault() { trapped = true; }});
    assert.equal(trapped, true);
    assert.equal(first.focused, true);
    document.activeElement = first;
    document.events.keydown({key:'Tab', shiftKey:true, preventDefault() {}});
    assert.equal(last.focused, true);
    document.events.keydown({key:'Escape'});
    assert.equal(toggle.attributes['aria-expanded'], 'false');
    assert.equal(sidebar.classList.contains('is-open'), false);
    assert.equal(toggle.focused, true);
    assert.equal(sidebar.inert, true);
    toggle.events.click();
    close.events.click();
    assert.equal(sidebar.inert, true);
    media.matches = false;
    media.change();
    assert.equal(sidebar.inert, false);
    assert.equal(sidebar.attributes['aria-hidden'], 'false');
});
