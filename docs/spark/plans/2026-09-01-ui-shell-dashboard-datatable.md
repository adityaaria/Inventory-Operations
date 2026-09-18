# UI Shell, Dashboard, and Datatable Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a modern operational workspace UI with sidebar navigation, dashboard visuals, datatable tools, modal forms, confirmations, loading states, and empty states.

**Architecture:** Keep server-rendered PHP as the source of truth and layer progressive enhancement through `public/assets/js/app.js` plus custom CSS. Existing routes remain valid fallbacks. Dashboard chart data is embedded from existing dashboard arrays without adding chart dependencies.

**Tech Stack:** PHP 8.2+ native OOP, HTML, custom CSS, Vanilla JS, Fetch API, Docker Compose, PHPUnit, PHPStan.

## Global Constraints

- No Laravel, CodeIgniter, Symfony, Slim, ORM, framework DI container, React, Vue, Angular, jQuery, CSS framework, or admin template.
- No stock mutation outside the existing stock service transaction and ledger.
- Never place authorization only in UI.
- Preserve existing controller, service, repository, route, CSRF, and POST behavior.
- JavaScript enhancements must degrade to normal links/forms.

---

### Task 1: Global App Shell

**Files:**
- Modify: `public/assets/css/app.css`
- Modify: `public/assets/js/app.js`
- Modify: all `views/**/*.php` and `app/Controller/HomeController.php` documents to load `app.js`

**Interfaces:**
- Produces: `.app-shell`, `.sidebar`, `.main-content`, `.page-loading`, and client-side shell injection from `main.page`.

- [x] Add global layout CSS for sidebar and content.
- [x] Add Vanilla JS that wraps `main.page` with sidebar and content shell after DOM load.
- [x] Ensure all rendered pages load `/assets/js/app.js`.
- [x] Verify PHP syntax.

### Task 2: Dashboard Visuals

**Files:**
- Modify: `views/dashboard/index.php`
- Modify: `public/assets/css/app.css`
- Modify: `public/assets/js/app.js`

**Interfaces:**
- Consumes: existing `$dashboard` array.
- Produces: `data-chart` elements rendered by Vanilla JS.

- [x] Add dashboard overview grid and status chart containers.
- [x] Embed status data as escaped JSON.
- [x] Render bar charts with Vanilla JS.
- [x] Provide empty states when dashboard widgets have no data.

### Task 3: Datatable Toolbar, Export, and Empty States

**Files:**
- Modify: master/list views under `views/users`, `views/products`, `views/categories`, `views/warehouses`, `views/suppliers`, `views/customers`, `views/purchase-orders`, and `views/sales-orders`.
- Modify: `public/assets/css/app.css`
- Modify: `public/assets/js/app.js`

**Interfaces:**
- Produces: `.table-toolbar`, `data-export-table`, sortable table headers, and consistent `.empty-state`.

- [x] Add table toolbar and export controls to list pages.
- [x] Add client-side visible table CSV export.
- [x] Add sortable column behavior.
- [x] Upgrade empty row styling.

### Task 4: Modal Forms, Confirmations, and Loading States

**Files:**
- Modify: `public/assets/css/app.css`
- Modify: `public/assets/js/app.js`
- Modify: list/show views with semantic action metadata where needed.

**Interfaces:**
- Produces: `.modal-backdrop`, `.confirm-dialog`, `.is-loading`, page loading overlay, and data-driven confirmation prompts.

- [x] Open create/edit links in modal dialogs with fetch.
- [x] Submit modal forms through fetch and show validation errors in the modal.
- [x] Add confirmation dialog for mutating action forms.
- [x] Add loading state for page navigation and submit buttons.

### Task 5: Verification and Evidence

**Files:**
- Modify: `.spark/sdd/progress.md`
- Modify: `docs/testing/test-results.md`
- Modify: `docs/testing/test-scenarios.md`
- Modify: `docs/quality/phpstan-report.txt`
- Modify: `ai-usage-log.md`

**Interfaces:**
- Produces: fresh verification evidence for the UI shell enhancement.

- [x] Run PHP syntax lint.
- [x] Run full PHPUnit regression suite.
- [x] Run PHPStan.
- [x] Rebuild Docker app and smoke test HTTP routes.
- [x] Update evidence docs.

## Self-Review

- Spec coverage: sidebar, main content, dashboard visuals, datatable export/filter, modal forms, confirmation dialogs, loading states, and empty states are covered.
- Scope check: presentation/progressive enhancement only; no business logic, authorization, transaction, stock, or schema changes.
- Constraint check: no forbidden frontend framework or chart library.
