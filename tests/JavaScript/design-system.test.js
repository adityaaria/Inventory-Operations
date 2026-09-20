const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const css = fs.readFileSync(path.join(__dirname, '../../public/assets/css/app.css'), 'utf8');

test('defines semantic core interaction tokens', () => {
    for (const token of [
        '--primary', '--primary-hover', '--accent', '--success', '--warning',
        '--danger', '--info', '--focus-ring', '--motion-fast',
    ]) {
        assert.match(css, new RegExp(`${token}\\s*:`), `missing ${token}`);
    }
});

test('defines canonical component modifiers', () => {
    for (const selector of [
        '.button-danger', '.button-quiet', '.field', '.field-label',
        '.field-hint', '.alert-info', '.alert-success', '.alert-warning',
        '.alert-danger', '.modal-footer', '.status-low', '.status-normal',
    ]) {
        assert.ok(css.includes(selector), `missing ${selector}`);
    }
});

test('keeps reduced-motion and visible keyboard focus contracts', () => {
    assert.match(css, /prefers-reduced-motion/);
    assert.match(css, /:focus-visible/);
});

test('defines canonical button, field, and alert components', () => {
    for (const selector of [
        '.button-danger', '.button-quiet', '.field', '.field-label',
        '.field-hint', '.alert-info', '.alert-success', '.alert-warning',
        '.alert-danger',
    ]) {
        assert.ok(css.includes(selector), `missing ${selector}`);
    }
    assert.match(css, /\.button-danger[\s\S]*var\(--danger\)/);
});

test('defines canonical status modifiers and keeps legacy aliases', () => {
    for (const selector of [
        '.status-low', '.status-normal', '.status-active', '.status-pending',
        '.status-approved', '.status-success', '.status-warning', '.status-danger',
        '.status-pendingapproval', '.status-partiallyreceived', '.status-cancelled',
        '.stock-low',
    ]) {
        assert.ok(css.includes(selector), `missing ${selector}`);
    }
});

test('defines modal footer and accessible dialog states', () => {
    assert.ok(css.includes('.modal-footer'), 'missing .modal-footer');
    assert.match(css, /\.modal-backdrop\[aria-busy="true"\]/);
    assert.match(css, /\.modal-close:focus-visible/);
    assert.match(css, /transition:[^;]*var\(--motion-normal\)/);
});

test('defines data-heavy component states', () => {
    for (const selector of [
        '.table-toolbar', '.table-search', '.table-loading', '.filtered-empty',
        '.data-error', '.metric-card', '.dashboard-panel', '.chart', '.quick-panel',
    ]) {
        assert.ok(css.includes(selector), `missing ${selector}`);
    }
});

test('defines dashboard chart state styling', () => {
    for (const selector of ['.chart-row', '.chart-track', '.quick-list']) {
        assert.ok(css.includes(selector), `missing ${selector}`);
    }
});
