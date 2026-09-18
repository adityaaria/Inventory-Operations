# Operational UI Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the existing native PHP app into a clearer operational dashboard UI without changing business behavior.

**Architecture:** This is a presentation-only enhancement. Keep server-rendered PHP views, custom CSS, existing controllers/services/repositories, CSRF hidden inputs, and server-side authorization. Avoid a shared template refactor unless absolutely required; use CSS and small markup changes in priority views.

**Tech Stack:** PHP 8.2+ native OOP, HTML, custom CSS, Vanilla JS only if already useful, Docker Compose, PHPUnit, PHPStan.

## Global Constraints

- Do not change business logic, authorization rules, stock transactions, ledger behavior, database schema, or repository contracts.
- Do not add Laravel, CodeIgniter, Symfony, Slim, ORM, React, Vue, Angular, jQuery, Bootstrap, Tailwind, admin templates, or frontend dependencies.
- Keep all rendered dynamic content escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` or existing safe helper.
- Keep CSRF hidden inputs in all POST forms.
- Preserve Docker routed CSV behavior for dotted routes.
- Verify with PHP lint, PHPUnit, PHPStan, Docker startup, and HTTP smoke.

---

## File Structure Map

- Modify `public/assets/css/app.css`: primary design system, layout shell, dashboard cards, badges, table polish, form/button polish, responsive behavior.
- Modify `views/auth/login.php`: login page shell and form class polish.
- Modify `views/dashboard/index.php`: metric cards and summary table classes.
- Modify `views/products/index.php`: filter toolbar, table class, stock badges, empty state.
- Modify `views/purchase-orders/index.php`: filter toolbar, table class, status badges, empty state.
- Modify `views/sales-orders/index.php`: filter toolbar, table class, status badges, empty state.
- Modify `views/reports/index.php`: report action panels.
- Modify `views/users/index.php`, `views/categories/index.php`, `views/warehouses/index.php`, `views/suppliers/index.php`, `views/customers/index.php` only if global classes need explicit list/action styling.
- Modify `docs/testing/test-scenarios.md`, `docs/testing/test-results.md`, and `ai-usage-log.md` after verification.

---

### Task 1: CSS Design System and App Shell

**Files:**
- Modify: `public/assets/css/app.css`

**Interfaces:**
- Consumes: existing page markup with `.page`, `.toolbar`, `.filters`, `.form`, `.alert`, table elements.
- Produces: CSS classes `.app-title`, `.page-header`, `.page-subtitle`, `.metric-grid`, `.metric-card`, `.data-table`, `.status-badge`, `.status-draft`, `.status-ordered`, `.status-received`, `.status-partial`, `.status-pending`, `.status-approved`, `.status-fulfilled`, `.status-cancelled`, `.stock-low`, `.action-link`, `.button-primary`, `.report-grid`, `.report-panel`.

- [x] **Step 1: Inspect current CSS and priority views**

Run:

```bash
sed -n '1,260p' public/assets/css/app.css
sed -n '1,220p' views/dashboard/index.php
sed -n '1,220p' views/products/index.php
```

Expected: current CSS uses `.page`, `.toolbar`, `.filters`, `.form`, `.alert`, and plain tables.

- [x] **Step 2: Implement CSS system**

Update `public/assets/css/app.css` with:

```css
:root {
    --bg: #f3f5f8;
    --surface: #ffffff;
    --surface-muted: #f8fafc;
    --text: #17202a;
    --muted: #637083;
    --line: #d8dee8;
    --line-strong: #b8c2d1;
    --primary: #1d4ed8;
    --primary-dark: #1e40af;
    --success: #166534;
    --success-bg: #dcfce7;
    --warning: #92400e;
    --warning-bg: #fef3c7;
    --danger: #991b1b;
    --danger-bg: #fee2e2;
    --info: #075985;
    --info-bg: #e0f2fe;
}

/* Keep existing reset, then style body/page as operational app shell. */
```

Include complete rules for body, `.page`, `.page-header`, `.app-title`, `.toolbar`, links, buttons, forms, filters, metric cards, status badges, tables, report panels, alert/empty states, and `@media (max-width: 520px)`.

- [x] **Step 3: Lint CSS-adjacent PHP unchanged**

Run:

```bash
for f in views/**/*.php public/*.php; do php -l "$f" >/dev/null || exit 1; done
```

Expected: exit code 0.

### Task 2: Dashboard Operational Metrics

**Files:**
- Modify: `views/dashboard/index.php`

**Interfaces:**
- Consumes: `$dashboard` array keys from `DashboardService`.
- Produces: dashboard cards using `.metric-grid` and `.metric-card`; summary tables use `.data-table`; no data values hardcoded.

- [x] **Step 1: Update dashboard markup**

Replace plain metric paragraphs with:

```php
<header class="page-header">
    <div>
        <p class="app-title">Inventory Operations</p>
        <h1>Dashboard</h1>
        <p class="page-subtitle">Role: <?= htmlspecialchars((string) $dashboard['role'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <nav class="toolbar"><a href="/">Home</a><a href="/reports">Reports</a></nav>
</header>
```

Render metric cards only when keys exist. Keep numeric values from `$dashboard`.

- [x] **Step 2: Add empty class for no-data status groups**

Use:

```php
<?php if ($dashboard[$key] === []): ?><p class="empty">No data available.</p><?php endif; ?>
```

- [x] **Step 3: Verify dashboard rendering**

Run:

```bash
php -l views/dashboard/index.php
composer test -- --filter DashboardServiceTest
```

Expected: syntax OK and dashboard tests pass.

### Task 3: Product and Order List Polish

**Files:**
- Modify: `views/products/index.php`
- Modify: `views/purchase-orders/index.php`
- Modify: `views/sales-orders/index.php`

**Interfaces:**
- Consumes: existing `$criteria`, `$result`, `$stocksByProduct`, `$suppliers`, `$customers`, `$warehouses`, `$canWrite`.
- Produces: styled filter toolbar, `.data-table`, `.status-badge`, `.stock-low`, `.empty`, and `.action-link`.

- [x] **Step 1: Update list headers**

Each page should start with:

```php
<header class="page-header">
    <div>
        <p class="app-title">Inventory Operations</p>
        <h1>Products</h1>
        <p class="page-subtitle">Search, filter, and review operational records.</p>
    </div>
    <nav class="toolbar">...</nav>
</header>
```

Use page-specific titles/subtitles for products, purchase orders, and sales orders.

- [x] **Step 2: Add table classes and action link classes**

Use:

```php
<table class="data-table">
<a class="action-link" href="...">Open</a>
```

- [x] **Step 3: Add status/stock badge markup**

For order status cells:

```php
<?php $statusClass = strtolower(str_replace([' ', '_'], '-', $order->status())); ?>
<span class="status-badge status-<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($order->status(), ENT_QUOTES, 'UTF-8') ?>
</span>
```

For low stock rows:

```php
<span class="<?= $stock->isLowStock() ? 'status-badge stock-low' : 'status-badge status-normal' ?>">
    <?= $stock->quantity() ?><?= $stock->isLowStock() ? ' Low' : ' In stock' ?>
</span>
```

- [x] **Step 4: Add empty states**

After each `<tbody>` start, add an `if ($result->items() === [])` row with `class="empty"` and a correct `colspan`.

- [x] **Step 5: Verify list tests and syntax**

Run:

```bash
php -l views/products/index.php
php -l views/purchase-orders/index.php
php -l views/sales-orders/index.php
composer test -- --filter 'ProductControllerTest|PurchaseOrderControllerTest|SalesOrderControllerTest|ProductSearch'
```

Expected: syntax OK and focused tests pass.

### Task 4: Login, Reports, and Shared CRUD Polish

**Files:**
- Modify: `views/auth/login.php`
- Modify: `views/reports/index.php`
- Modify: `views/users/index.php`
- Modify: `views/categories/index.php`
- Modify: `views/warehouses/index.php`
- Modify: `views/suppliers/index.php`
- Modify: `views/customers/index.php`

**Interfaces:**
- Consumes: existing server-rendered forms and list data.
- Produces: consistent page headers, `.button-primary`, `.data-table`, `.action-link`, `.report-grid`, `.report-panel`.

- [x] **Step 1: Polish login**

Add `.login-page` on `<main>`, add an app title and subtitle, and make the login button `.button-primary`.

- [x] **Step 2: Polish reports**

Wrap the two report forms in:

```php
<section class="report-grid">
    <article class="report-panel">...</article>
    <article class="report-panel">...</article>
</section>
```

Keep actions `/reports/orders.csv` and `/reports/stock-ledger.csv`.

- [x] **Step 3: Polish shared list pages**

Add `.data-table` to list tables and `.action-link` to edit links where present. Add page headers with toolbar where this does not change data flow.

- [x] **Step 4: Verify syntax**

Run:

```bash
for f in views/auth/login.php views/reports/index.php views/users/index.php views/categories/index.php views/warehouses/index.php views/suppliers/index.php views/customers/index.php; do php -l "$f" >/dev/null || exit 1; done
```

Expected: exit code 0.

### Task 5: Runtime Verification and Evidence

**Files:**
- Modify: `docs/testing/test-scenarios.md`
- Modify: `docs/testing/test-results.md`
- Modify: `ai-usage-log.md`

**Interfaces:**
- Consumes: completed UI markup/CSS and running Docker app.
- Produces: honest verification evidence.

- [x] **Step 1: Run full verification**

Run:

```bash
for f in app/**/*.php public/*.php views/**/*.php tests/**/*.php; do php -l "$f" >/dev/null || exit 1; done
composer test
composer analyse
APP_PORT=8082 docker compose up -d --build app
```

Expected: lint, PHPUnit, PHPStan, and Docker startup pass.

- [x] **Step 2: Run HTTP smoke**

Run a cookie/CSRF curl smoke for:

```text
/login
/dashboard
/products?q=Demo&page=1
/purchase-orders
/sales-orders
/reports
/reports/orders.csv
/api/products/SKU-DEMO-001/availability
```

Expected: login POST returns 302; page/API/report routes return 200.

- [x] **Step 3: Update evidence docs**

Record:

- Date: 2026-09-01.
- UI enhancement scope.
- Commands and pass/fail results.
- Manual visual review notes for desktop and 360px.
- Known limitation: no automated screenshot tool used unless separately added.

- [x] **Step 4: Commit checkpoint**

If Git exists:

```bash
git add public/assets/css/app.css views docs ai-usage-log.md
git commit -m "style: enhance operational ui"
```

If Git does not exist, record inability to commit in final report.

## Self-Review

- Spec coverage: all approved UI shell, visual system, dashboard, list, form, report, and responsive requirements are covered.
- Placeholder scan: no `TBD`, `TODO`, or incomplete implementation steps remain.
- Scope check: presentation-only; no business logic, database schema, authorization, or stock transaction changes.
- Constraint check: no forbidden dependency or framework is introduced.
- Verification check: plan includes syntax lint, focused tests, full tests, PHPStan, Docker startup, and HTTP smoke.
- Modern refinement: 2026-09-01 SaaS Clean refinement updated the global CSS and home navigation, then re-ran PHP lint, Docker rebuild, HTTP smoke, full PHPUnit, and PHPStan.

## Execution Handoff

Plan execution is complete. Follow-up UI changes should continue from the same visual system in `public/assets/css/app.css` unless a new approved design direction replaces it.
