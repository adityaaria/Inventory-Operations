const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '../../views');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

const createEditViews = [
    'categories/create.php', 'categories/edit.php',
    'customers/create.php', 'customers/edit.php',
    'products/create.php', 'products/edit.php',
    'suppliers/create.php', 'suppliers/edit.php',
    'users/create.php', 'users/edit.php',
    'warehouses/create.php', 'warehouses/edit.php',
    'purchase-orders/create.php', 'sales-orders/create.php',
];

test('error pages share the styled error-page contract', () => {
    for (const file of ['errors/403.php', 'errors/404.php', 'errors/500.php']) {
        const source = read(file);
        assert.match(source, /assets\/css\/app\.css/, `${file} missing app.css`);
        assert.match(source, /<main class="page">/, `${file} missing page root`);
        assert.match(source, /page-header/, `${file} missing page header`);
        assert.match(source, /app-title/, `${file} missing app title`);
        assert.match(source, /alert-(danger|warning|info)/, `${file} missing semantic alert`);
    }
});

test('create and edit pages share form component contracts', () => {
    for (const file of createEditViews) {
        const source = read(file);
        assert.match(source, /page-header/, `${file} missing page header`);
        assert.match(source, /class="form"/, `${file} missing form class`);
        assert.match(source, /class="field"/, `${file} missing field wrapper`);
        assert.match(source, /field-label/, `${file} missing field label`);
        assert.match(source, /form-actions/, `${file} missing form actions`);
    }
});

test('order detail pages share detail component contracts', () => {
    for (const file of ['purchase-orders/show.php', 'sales-orders/show.php']) {
        const source = read(file);
        assert.match(source, /page-header/, `${file} missing page header`);
        assert.match(source, /detail-summary/, `${file} missing detail summary`);
        assert.match(source, /class="detail-table"/, `${file} missing detail table`);
        assert.match(source, /scope="col"/, `${file} missing column scope`);
        assert.match(source, /form-actions/, `${file} missing form actions`);
        assert.match(source, /status-badge/, `${file} missing status badge`);
    }
});

test('list, dashboard, reports, and auth contracts remain identifiable', () => {
    for (const file of [
        'categories/index.php', 'customers/index.php', 'products/index.php',
        'suppliers/index.php', 'users/index.php', 'warehouses/index.php',
        'purchase-orders/index.php', 'sales-orders/index.php',
    ]) {
        assert.match(read(file), /class="data-table"/, `${file} missing data-table`);
    }
    assert.match(read('dashboard/index.php'), /dashboard-grid/);
    assert.match(read('reports/index.php'), /report-grid/);
    const login = read('auth/login.php');
    assert.match(login, /auth-body/);
    assert.doesNotMatch(login, /side-nav/);
});
