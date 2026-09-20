# Data-heavy Design System Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Standardize tables, toolbars, filters, pagination, shared states, and dashboard data components without changing query or business behavior.

**Architecture:** Extend the existing `app.css`, `tables.js`, and `charts.js` progressive-enhancement layer. Keep server-rendered HTML as the source of usable content, add ARIA/state metadata at the view and JS boundaries, and preserve existing route parameters, repository queries, and role scoping.

**Tech Stack:** PHP 8.2+, server-rendered PHP, custom CSS, Vanilla JS, Node built-in test runner, PHPUnit, PHPStan.

## Global Constraints

- No frontend framework, CSS framework, chart library, ORM, or new runtime dependency.
- No query, repository, service, route, authorization, CSRF, pagination-semantic, or business-flow changes.
- `tables.js` and `charts.js` remain progressive enhancements.
- Existing filters, page links, CSV export, role scope, and status vocabulary remain unchanged.
- New behavior is test-first: write a failing test, verify RED, implement minimally, verify GREEN.

---

### Task 1: Add failing data-heavy contract tests

**Files:**
- Modify: `tests/JavaScript/design-system.test.js`
- Modify: `tests/JavaScript/ui-helpers.test.js` only if shared helpers need coverage
- Create: `tests/JavaScript/tables.test.js`
- Read: `public/assets/css/app.css`, `public/assets/js/tables.js`

**Interfaces:**
- Produces: tests for CSS selectors, table sorting ARIA state, and filtered-empty behavior.
- Consumes: Node `node:test`, `node:assert/strict`, and the existing DOM-free helper style.

- [ ] **Step 1: Add CSS contract assertions**

Append tests to `design-system.test.js` for `.table-toolbar`, `.table-search`, `.table-loading`, `.filtered-empty`, `.data-error`, `.metric-card`, `.dashboard-panel`, `.chart`, and `.quick-panel`.

- [ ] **Step 2: Add a DOM-free table source-contract test**

Create `tables.test.js` that reads `public/assets/js/tables.js` and asserts the implementation contains the stable behavior contract: `aria-sort` values, an accessible search label, and the `filtered-empty` generated-row class. This repository intentionally keeps JavaScript tests DOM-free and has no jsdom dependency.

- [ ] **Step 3: Run focused tests and verify RED**

Run: `node --test tests/JavaScript/design-system.test.js tests/JavaScript/tables.test.js`

Expected: FAIL because the new selectors, ARIA sort behavior, and filtered-empty contract do not yet exist.

- [ ] **Step 4: Commit the failing test contract**

```bash
git add tests/JavaScript/design-system.test.js tests/JavaScript/tables.test.js
git commit -m "test: define data-heavy component contracts"
```

### Task 2: Standardize data-heavy CSS tokens and states

**Files:**
- Modify: `public/assets/css/app.css`
- Test: `tests/JavaScript/design-system.test.js`

**Interfaces:**
- Consumes: CSS contract tests from Task 1.
- Produces: semantic data-display tokens and styles for toolbar, loading, filtered-empty, error, metric, chart, panel, and quick-action states.

- [ ] **Step 1: Add semantic data-display tokens**

Add only missing tokens next to existing tokens:

```css
    --table-header: #f1f5f9;
    --table-row-hover: #fff7ed;
    --state-muted: #98a2b3;
    --state-error: var(--danger);
```

- [ ] **Step 2: Normalize toolbar/filter/table/pagination focus and spacing**

Use shared tokens in `.table-toolbar`, `.table-search`, `.filters`, `.data-table`, and `.pagination`. Preserve existing dimensions and responsive breakpoints; do not change server-side markup semantics in this step.

- [ ] **Step 3: Add shared state styles**

Add `.table-loading`, `.filtered-empty`, and `.data-error`. Reuse `.empty-state` where possible, but make filtered-empty and error copy visually distinct through border/background/token treatment.

- [ ] **Step 4: Add dashboard component state styles**

Ensure `.metric-card`, `.dashboard-panel`, `.chart`, `.quick-panel`, `.chart-row`, and `.chart-track` share the same surface, border, spacing, and empty-state rhythm. Do not add a chart library.

- [ ] **Step 5: Run CSS-focused tests and verify GREEN**

Run: `node --test --test-name-pattern="data-heavy|dashboard|state" tests/JavaScript/design-system.test.js`

Expected: all matching CSS contract tests pass.

- [ ] **Step 6: Commit the CSS slice**

```bash
git add public/assets/css/app.css tests/JavaScript/design-system.test.js
git commit -m "feat: standardize data-heavy component states"
```

### Task 3: Improve table sorting, search, and filtered-empty accessibility

**Files:**
- Modify: `public/assets/js/tables.js`
- Modify: `tests/JavaScript/tables.test.js`
- Existing tests: `tests/JavaScript/ui-helpers.test.js`

**Interfaces:**
- Consumes: existing `InventoryUi.matchesFilter`, `compareTableValues`, `debounce`, and CSV export helpers.
- Produces: sortable headers with `aria-sort`, accessible search labeling, and a `.filtered-empty` generated row while preserving existing sorting/filter/export semantics.

- [ ] **Step 1: Extend tests for the expected behavior**

Add focused source-contract tests for:

```js
assert.match(source, /setAttribute\(['"]aria-sort['"], ['"]ascending['"]\)/);
assert.match(source, /setAttribute\(['"]aria-sort['"], ['"]none['"]\)/);
assert.match(source, /filtered-empty/);
```

Also assert that the toolbar search input has an accessible label association and that existing `.empty` server rows are not replaced.

- [ ] **Step 2: Run focused table tests and verify RED**

Run: `node --test tests/JavaScript/tables.test.js`

Expected: FAIL because current `tables.js` uses only `data-sort-direction`, has no `aria-sort`, and creates `.generated-empty` instead of `.filtered-empty`.

- [ ] **Step 3: Implement minimal table behavior changes**

In `addSorting`, initialize each header with `aria-sort="none"`; in `sort`, reset all headers to `none` and set the active header to `ascending` or `descending`. Keep `data-sort-direction` for compatibility.

Give the generated search input a stable label via `aria-label="Search table rows"` while keeping the visible `.table-search` label. Change only the generated row class to include `filtered-empty` and retain `empty` for existing selectors: `className = 'empty filtered-empty'`.

- [ ] **Step 4: Run table and JavaScript tests**

Run: `node --test tests/JavaScript/tables.test.js tests/JavaScript/*.test.js`

Expected: all tests pass.

- [ ] **Step 5: Commit the JS slice**

```bash
git add public/assets/js/tables.js tests/JavaScript/tables.test.js
git commit -m "feat: improve accessible table states"
```

### Task 4: Standardize filter and pagination markup

**Files:**
- Modify: `views/products/index.php`
- Modify: `views/purchase-orders/index.php`
- Modify: `views/sales-orders/index.php`
- Modify: `views/reports/index.php`
- Modify: `public/assets/css/app.css` only if markup needs a shared modifier
- Existing tests: `tests/Unit/ViewEscapingTest.php`, `tests/Unit/SecurityAuditTest.php`

**Interfaces:**
- Consumes: `.filters`, `.pagination`, `.table-search`, and ARIA behavior from Tasks 2–3.
- Produces: accessible labels, `aria-current="page"`, `aria-disabled="true"`, and stable filter grouping without changing query strings.

- [ ] **Step 1: Add accessible filter labels without changing field names**

Use visually consistent `.field` wrappers or explicit labels for query/status/direction/date controls. Preserve every existing `name`, `value`, action, method, and `http_build_query` parameter.

- [ ] **Step 2: Add pagination ARIA state**

Set `aria-current="page"` on the current-page element. Keep disabled previous/next spans as non-links with `aria-disabled="true"`; keep enabled URLs unchanged.

- [ ] **Step 3: Run PHP view/security tests**

Run: `vendor/bin/phpunit tests/Unit/ViewEscapingTest.php tests/Unit/SecurityAuditTest.php`

Expected: selected tests pass.

- [ ] **Step 4: Syntax-check modified views**

Run: `php -l views/products/index.php`, `php -l views/purchase-orders/index.php`, `php -l views/sales-orders/index.php`, `php -l views/reports/index.php`.

Expected: no syntax errors.

- [ ] **Step 5: Commit the markup slice**

```bash
git add views/products/index.php views/purchase-orders/index.php views/sales-orders/index.php views/reports/index.php public/assets/css/app.css
git commit -m "feat: standardize filter and pagination semantics"
```

### Task 5: Standardize dashboard metrics, charts, panels, and states

**Files:**
- Modify: `views/dashboard/index.php`
- Modify: `public/assets/js/charts.js` only for explicit accessible state metadata
- Modify: `public/assets/css/app.css`
- Modify: `tests/JavaScript/design-system.test.js` if chart contract assertions are needed

**Interfaces:**
- Consumes: shared state styles and data-display tokens from Tasks 2–4.
- Produces: consistent metric/panel/chart/quick-action hierarchy and text fallback without changing dashboard data keys.

- [ ] **Step 1: Add dashboard state assertions**

Assert that dashboard chart containers expose an accessible label/heading relationship and that empty chart output uses `.empty-state`.

- [ ] **Step 2: Run dashboard-focused tests and verify RED**

Run: `node --test --test-name-pattern="dashboard|chart" tests/JavaScript/design-system.test.js`

Expected: FAIL only where the new dashboard contract is absent.

- [ ] **Step 3: Migrate dashboard markup**

Give each chart container a stable `aria-labelledby` reference to its section heading, keep the existing `data-chart` JSON payload, add consistent panel modifiers where needed, and preserve the existing fallback status table.

- [ ] **Step 4: Improve chart state metadata without changing rendering**

In `charts.js`, set `role="img"` only when chart entries exist and use `aria-label` derived from the existing panel heading when available. For empty data, keep `.empty-state` and set `role="status"`.

- [ ] **Step 5: Run JavaScript tests**

Run: `node --test tests/JavaScript/*.test.js`

Expected: all tests pass.

- [ ] **Step 6: Commit the dashboard slice**

```bash
git add public/assets/css/app.css public/assets/js/charts.js views/dashboard/index.php tests/JavaScript/design-system.test.js
git commit -m "feat: standardize dashboard data components"
```

### Task 6: Full verification and evidence update

**Files:**
- Modify: `docs/quality/design-system-fase-1-known-gaps.md`
- Modify: `docs/testing/test-results.md` only if the repository evidence format requires a current result entry
- Read: all changed files and approved spec

**Interfaces:**
- Consumes: completed Data-heavy component contract from Tasks 1–5.
- Produces: evidence of test results, remaining visual gaps, and no business-flow changes.

- [ ] **Step 1: Run all automated verification**

Run:

```bash
node --test tests/JavaScript/*.test.js
docker compose run --rm app composer test:unit
docker compose run --rm app composer test:integration
docker compose run --rm app composer analyse
docker compose run --rm app sh -lc 'find views -type f -name "*.php" -print0 | xargs -0 -n1 php -l'
git diff --check
```

Expected: all commands exit 0.

- [ ] **Step 2: Perform manual smoke verification**

Inspect Products, Purchase Orders, Sales Orders, Reports, and Dashboard at desktop and narrow viewport widths. Verify search, sort keyboard interaction, filters, pagination, empty/filtered-empty, metric cards, chart empty state, and quick actions.

- [ ] **Step 3: Update evidence**

Record the Data-heavy contract, automated results, and remaining browser-level gaps in `docs/quality/design-system-fase-1-known-gaps.md`. Keep temporary recommendations out of `.docs/` stable memory.

- [ ] **Step 4: Commit evidence**

```bash
git add docs/quality/design-system-fase-1-known-gaps.md docs/testing/test-results.md
git commit -m "docs: record data-heavy design system verification"
```

## Plan Self-Review

- Spec coverage: tables, toolbar/search, filters, pagination, loading/empty/error, dashboard, accessibility, responsive behavior, testing, and non-goals are covered by Tasks 1–6.
- Scope: no persistence, query, service, route, authorization, or business workflow changes are planned.
- Placeholder scan: no TBD/TODO or implementation-later steps are present.
- Compatibility: existing query parameters, CSS classes, `data-sort-direction`, empty rows, and CSV export are explicitly preserved.
- Verification: Node, PHPUnit, integration, PHPStan, PHP syntax, diff, and manual smoke checks are defined.
