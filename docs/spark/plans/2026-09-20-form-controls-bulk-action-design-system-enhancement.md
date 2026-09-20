# Form Controls & Bulk Action Design System Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Standardize native select/checkbox controls and add an accessible presentation-only bulk-action bar without introducing business mutations.

**Architecture:** Keep form controls native, add shared CSS hooks in `app.css`, and add a small progressive helper in `tables.js` only for a representative table whose markup can support selection without changing server contracts.

**Tech Stack:** Server-rendered PHP, custom CSS, Vanilla JS, Node built-in test runner, PHPUnit, PHPStan, Docker Compose.

## Global Constraints

- No custom dropdown, frontend dependency, route, endpoint, authorization, service, repository, query, database, or stock mutation change.
- No existing form `name`, `value`, action, method, filter, pagination, or status behavior changes.
- The bulk-action bar has no business mutation handler in this batch.
- Preserve server-rendered table usability if JavaScript fails.
- New behavior is test-first: write a failing contract test, verify RED, implement minimally, verify GREEN.

### Task 1: Select the representative table and add failing contracts

**Files:**
- Read: `views/products/index.php`, `views/purchase-orders/index.php`, `views/sales-orders/index.php`, `views/users/index.php`
- Modify: `tests/JavaScript/design-system.test.js`
- Create: `tests/JavaScript/selection.test.js`
- Read: `public/assets/css/app.css`, `public/assets/js/tables.js`

**Steps:**

1. Compare existing table markup and choose the least risky representative target. Prefer a read-only master-data table with stable row identity; do not choose a stock mutation workflow.
2. Add CSS contract assertions for native select/checkbox and `.bulk-action-bar` states.
3. Add DOM-free source-contract tests for row checkbox hooks, select-all, `indeterminate`, selected count, labelled region, and absence of mutation endpoint/action code.
4. Run focused tests and verify RED.
5. Commit only the failing contracts as `test: define form control selection contracts`.

### Task 2: Standardize native select and checkbox styling

**Files:**
- Modify: `public/assets/css/app.css`
- Test: `tests/JavaScript/design-system.test.js`

**Steps:**

1. Ensure `.field select`, `.filters select`, and `.form select` share existing surface, border, typography, disabled, invalid, and focus-visible treatment.
2. Add a shared checkbox presentation that preserves native checkbox semantics and visible focus.
3. Add `.bulk-action-bar` base, hidden, visible, loading, disabled, and error styles using existing tokens.
4. Keep responsive layout usable on narrow screens.
5. Run focused CSS tests and verify GREEN.
6. Commit as `feat: standardize selection control styling`.

### Task 3: Implement presentation-only selection helper

**Files:**
- Modify: `public/assets/js/tables.js`
- Modify: `tests/JavaScript/selection.test.js`
- Modify: the one selected representative view only if markup is required

**Steps:**

1. Add stable row checkbox markup with accessible row labels and a select-all checkbox to the chosen read-only table, preserving all existing cell content and links.
2. Add a bulk-action bar with a labelled region and selected-count text; do not add action URLs or mutation handlers.
3. Implement selection state for visible rows, select-all checked/unchecked/indeterminate state, and selected count.
4. Keep JavaScript optional: without enhancement, checkboxes remain ordinary controls and the table remains usable.
5. Ensure filtered/search table updates recalculate selection and do not retain hidden rows as an incorrect visible count.
6. Run focused selection tests and all JavaScript tests; verify GREEN.
7. Commit as `feat: add accessible presentation-only table selection`.

### Task 4: Verify compatibility and security boundaries

**Files:**
- Read: selected view and all modified JS/CSS
- Modify: tests only if a missing stable contract is found

**Steps:**

1. Confirm existing form names, values, query parameters, pagination, links, and row actions are unchanged.
2. Confirm no mutation endpoint, fetch call, form action, or domain action was added for bulk selection.
3. Run relevant PHP view/security tests and syntax checks.
4. Inspect the diff for accidental changes to stock, approval, delete, receipt, issue, or authorization paths.

### Task 5: Full verification and evidence update

**Files:**
- Modify: `docs/quality/design-system-fase-1-known-gaps.md`
- Read: approved spec, plan, and all changed files

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

2. Perform manual smoke review for select focus/disabled/invalid states, checkbox keyboard interaction, select-all mixed state, selected count, responsive bar layout, and confirmation that no business action is triggered.
3. Record browser automation limitations if applicable.
4. Update quality evidence, explicitly noting that business bulk actions remain deferred.
5. Commit evidence as `docs: record form control bulk action evidence`.

## Completion Criteria

- Native select and checkbox contracts are consistent and accessible.
- One representative read-only table supports presentation-only selection.
- Select-all and indeterminate state work correctly.
- Bulk-action bar shows selected count but performs no business mutation.
- Existing form/query/table behavior and authorization boundaries remain unchanged.
- All automated verification passes and deferred business semantics are documented.
