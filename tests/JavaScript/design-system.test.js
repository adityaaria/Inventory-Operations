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

test('defines layout and navigation component hooks', () => {
    for (const selector of [
        '.app-shell', '.sidebar', '.main-content', '.page-header', '.toolbar',
        '.skip-link', '.sidebar-toggle', '.sidebar-backdrop',
    ]) {
        assert.ok(css.includes(selector), `missing ${selector}`);
    }
});

test('defines typography scale and weight tokens', () => {
    for (const token of [
        '--fs-xs', '--fs-sm', '--fs-md', '--fs-lg', '--fs-xl', '--fs-2xl',
        '--fw-regular', '--fw-medium', '--fw-semibold', '--fw-bold',
    ]) {
        assert.match(css, new RegExp(`${token}\\s*:`), `missing ${token}`);
    }
});

test('uses typography tokens in primary content selectors', () => {
    for (const selector of ['body', 'h1', '.page-subtitle', '.app-title', '.side-nav a', '.data-table', '.metric-card']) {
        assert.ok(css.includes(selector), `missing ${selector}`);
    }
    assert.match(css, /\.page-subtitle[\s\S]*var\(--fs-/);
    assert.match(css, /\.metric-card[\s\S]*var\(--fs-/);
});

test('defines active palette aliases for migrated surfaces', () => {
    for (const token of ['--page-tint', '--surface-active', '--accent-soft', '--shadow', '--shadow-soft']) {
        assert.match(css, new RegExp(`${token}\\s*:`), `missing ${token}`);
    }
    assert.match(css, /\.app-title[\s\S]*var\(--accent/);
    assert.match(css, /radial-gradient[\s\S]*var\(--page-tint/);
});

test('defines selection control and bulk action hooks', () => {
    for (const selector of [
        '.field select', '.filters select', '.form select', '.table-row-select',
        '.table-select-all', '.bulk-action-bar', '.bulk-action-bar.is-visible',
    ]) {
        assert.ok(css.includes(selector), `missing ${selector}`);
    }
});
