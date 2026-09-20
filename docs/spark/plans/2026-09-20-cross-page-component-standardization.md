# Cross-page Component Standardization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Make UI component contracts consistent across every application page without changing business behavior, routes, authorization, CSRF, or persistence.

**Architecture:** Keep standalone PHP views and the existing shared CSS/Vanilla JS layer. Normalize markup in bounded page groups; do not extract a shared PHP layout in this batch.

**Tech Stack:** PHP 8.2+, server-rendered PHP, custom CSS, Vanilla JS, Node test runner, PHPUnit, PHPStan, Docker Compose.

## Global Constraints

- No route, HTTP status, form action/method/name/value, CSRF, authorization, service, repository, query, transaction, or status vocabulary change.
- Preserve existing modal, form validation, confirmation, table, navigation, and loading behavior.
- List tables remain `.data-table`; PO/SO detail tables use `.detail-table` and must not become enhanced list tables.
- New/changed view markup must remain usable if JavaScript fails.
- New behavior is test-first: write a failing contract test, verify RED, implement minimally, verify GREEN.

### Task 1: Add failing cross-page contract tests and inventory

**Files:**
- Create: `tests/JavaScript/view-components.test.js`
- Modify: `tests/JavaScript/design-system.test.js`
- Read: all files under `views/`

**Steps:**

1. Build a DOM-free source inventory for the expected page groups: error, create/edit, list, detail, dashboard, reports, and auth.
2. Add tests asserting error pages load `app.css`, use `main.page`, and expose page context.
3. Add tests asserting create/edit views use `.page-header`/`.form`/`.field`/`.field-label` and `.form-actions` for visible actions.
4. Add tests asserting PO/SO detail views use `.detail-table`, `scope="col"`, `.detail-summary`, and `.form-actions`.
5. Add tests asserting list views retain `.data-table` and auth remains `auth-body` without app shell markup.
6. Run focused tests and verify RED.
7. Commit only the failing contract as `test: define cross-page component contracts`.

### Task 2: Standardize error pages

**Files:**
- Modify: `views/errors/404.php`
- Modify: `views/errors/500.php`
- Review: `views/errors/403.php`
- Modify: `tests/JavaScript/view-components.test.js` only if contract clarification is needed

**Steps:**

1. Add the same minimum stylesheet/head contract as the styled error page.
2. Use `main.page`, `.page-header`, `.app-title`, `h1`, descriptive subtitle/message, semantic alert, and a safe back/home link.
3. Keep HTTP status selection in the responder untouched; only view markup changes.
4. Ensure error pages do not receive authenticated navigation shell behavior.
5. Run focused view contract tests and PHP syntax checks; verify GREEN.
6. Commit as `feat: standardize error page components`.

### Task 3: Standardize create/edit forms and page headers

**Files:**
- Modify all master-data create/edit views under `views/categories`, `views/customers`, `views/products`, `views/suppliers`, `views/users`, `views/warehouses`.
- Modify: `views/purchase-orders/create.php`
- Modify: `views/sales-orders/create.php`
- Modify: `public/assets/css/app.css`

**Steps:**

1. Add consistent page header/context and back toolbar without changing destination URLs.
2. Wrap all visible controls with `.field` and `.field-label`; preserve specialized textarea/select/file/number controls.
3. Group submit and cancel controls under `.form-actions`; preserve button text and existing data attributes.
4. Normalize page/request errors to `.alert-danger` where they represent errors.
5. Add `.form-actions` and any needed page/detail surface CSS using existing tokens and responsive rules.
6. Verify every original field `name`, method, action, CSRF field, and conditional authorization remains unchanged through source checks.
7. Run focused tests and syntax checks; verify GREEN.
8. Commit as `feat: standardize form page components`.

### Task 4: Standardize PO/SO detail pages

**Files:**
- Modify: `views/purchase-orders/show.php`
- Modify: `views/sales-orders/show.php`
- Modify: `public/assets/css/app.css`

**Steps:**

1. Add page header and contextual back toolbar while preserving links.
2. Wrap order metadata in `.detail-summary` label/value pairs.
3. Add semantic status badge classes while keeping existing status text and vocabulary.
4. Add `.detail-table` and `scope="col"` to detail table headers; preserve all item values and receive inputs.
5. Group state-changing forms in `.form-actions`; preserve CSRF, IDs, action URLs, authorization conditions, and confirmation behavior.
6. Ensure `.detail-table` is not selected by `tables.js` list enhancement.
7. Run focused source tests, view/security tests, and syntax checks; verify GREEN.
8. Commit as `feat: standardize order detail components`.

### Task 5: Normalize cross-page alerts and compatibility contracts

**Files:**
- Modify remaining affected list/dashboard/report views only where audit confirms a component mismatch.
- Modify: `tests/JavaScript/view-components.test.js`
- Modify: `public/assets/css/app.css` only for shared modifiers.

**Steps:**

1. Normalize generic request-error alerts to semantic alert modifiers without changing copy.
2. Confirm lists retain `.data-table`, filters retain query fields, dashboard panels retain chart/data contracts, and reports retain export forms.
3. Confirm login remains excluded from the app shell and error pages remain standalone.
4. Add an explicit exception list for any plain label or specialized markup that cannot safely migrate in this batch.
5. Run all JavaScript tests and relevant PHP view/security tests.

### Task 6: Full verification and evidence update

**Files:**
- Modify: `docs/quality/design-system-fase-1-known-gaps.md`
- Read: approved spec, plan, all changed views, CSS, and tests

**Steps:**

1. Run:

```text
node --test tests/JavaScript/*.test.js
docker compose run --rm app composer test:unit
docker compose run --rm app composer test:integration
docker compose run --rm app composer analyse
docker compose run --rm app sh -lc 'find views -type f -name "*.php" -print0 | xargs -0 -n1 php -l'
git diff --check
```

2. Perform manual smoke scope: error pages, representative create/edit forms, PO/SO detail actions, all list table/filter contracts, dashboard/reports, and login shell exclusion. Record browser automation limitations if unavailable.
3. Update quality evidence with page coverage, tests, exceptions, and remaining manual visual gaps.
4. Commit as `docs: record cross-page component standardization evidence`.

## Completion Criteria

- All page groups use the approved component contracts or have a documented exception.
- Error pages are visually and semantically consistent.
- Create/edit forms use consistent field and action grouping.
- PO/SO detail pages use detail-specific table and summary contracts.
- Existing routes, form contracts, authorization, CSRF, business status, and workflows are unchanged.
- Full automated verification passes and evidence is current.
