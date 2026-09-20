# Core Interaction Design System Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Improve the operational clarity, consistency, and accessibility of buttons, forms, alerts, status badges, and modal/dialog components without changing business workflows.

**Architecture:** Keep the existing shared CSS and Vanilla JS architecture. Add semantic component tokens and modifiers in `public/assets/css/app.css`, preserve legacy selectors during migration, and update representative server-rendered views only where the new contract needs markup support. Existing JS behavior remains the owner of validation, loading, focus, retry, and dialog lifecycle.

**Tech Stack:** PHP 8.2+, server-rendered PHP views, custom CSS, Vanilla JavaScript, Node built-in test runner, PHPUnit, PHPStan.

## Global Constraints

- No frontend framework, CSS framework, icon dependency, ORM, or backend architecture change.
- No route, authorization, CSRF, order workflow, stock transaction, or database change.
- Business rules remain in services/controllers; CSS and JS changes remain presentation/interaction concerns.
- Preserve existing status aliases and direct-label form markup during migration.
- Use test-first cycles for new JavaScript/component contracts.
- Verify with `composer test:unit`, `composer test:javascript`, and `composer analyse`.

---

### Task 1: Add a CSS contract test for core component tokens and selectors

**Files:**
- Create: `tests/JavaScript/design-system.test.js`
- Read: `public/assets/css/app.css`

**Interfaces:**
- Produces: Node tests that define the minimum semantic CSS contract for later tasks.
- Consumes: Node built-in `node:test` and `node:assert/strict`, matching the existing JavaScript test style.

- [ ] **Step 1: Write the failing contract tests**

```js
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

```

- [ ] **Step 2: Run the new tests and verify the expected red failure**

Run: `node --test tests/JavaScript/design-system.test.js`

Expected: FAIL because the new tokens and canonical selectors do not yet exist in `app.css`.

- [ ] **Step 3: Commit the contract test**

```bash
git add tests/JavaScript/design-system.test.js
git commit -m "test: define core design system css contract"
```

### Task 2: Normalize semantic tokens and base interaction states

**Files:**
- Modify: `public/assets/css/app.css` (`:root`, focus rules, transition rules, reduced-motion block)
- Test: `tests/JavaScript/design-system.test.js`

**Interfaces:**
- Consumes: The failing CSS contract from Task 1.
- Produces: `--primary-hover`, `--focus-ring`, `--motion-fast`, `--motion-normal`, and consistent focus/motion behavior for all core controls.

- [ ] **Step 1: Add the minimum semantic tokens**

Add these tokens next to the existing interaction tokens, using values that preserve the approved black/orange operational palette and existing semantic colors:

```css
    --primary-hover: #2f3238;
    --focus-ring: rgba(255, 90, 31, 0.28);
    --motion-fast: 150ms;
    --motion-normal: 220ms;
```

- [ ] **Step 2: Replace duplicated interaction timing and focus-ring literals**

Use `var(--focus-ring)` for the shared `:focus-visible` outline and use `var(--motion-fast)`/`var(--motion-normal)` for button, backdrop, and panel transitions where the current values express the same behavior. Do not change layout or component dimensions in this step.

- [ ] **Step 3: Add reduced-motion behavior**

Append:

```css
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
    }
}
```

- [ ] **Step 4: Run the token-focused contract tests and verify green**

Run: `node --test tests/JavaScript/design-system.test.js --test-name-pattern="semantic core interaction tokens|reduced-motion"`

Expected: PASS for the token and reduced-motion checks. The complete contract suite remains red until Tasks 3–5 add the canonical component modifiers.

- [ ] **Step 5: Commit the token layer**

```bash
git add public/assets/css/app.css tests/JavaScript/design-system.test.js
git commit -m "feat: normalize core interaction tokens"
```

### Task 3: Standardize button, field, validation, and alert components

**Files:**
- Modify: `public/assets/css/app.css` (button/form/alert blocks)
- Modify: `public/assets/js/forms.js` only if needed to preserve class/state contracts
- Modify: `views/auth/login.php`
- Modify: `views/products/create.php`
- Modify: `views/products/edit.php`
- Test: `tests/JavaScript/design-system.test.js`
- Existing tests: `tests/Unit/ViewEscapingTest.php`, `tests/Unit/SecurityAuditTest.php`

**Interfaces:**
- Consumes: semantic tokens from Task 2 and existing `InventoryForms` validation/loading behavior.
- Produces: `.button-danger`, `.button-quiet`, `.field`, `.field-label`, `.field-hint`, `.alert-info`, `.alert-success`, `.alert-warning`, `.alert-danger`.

- [ ] **Step 1: Extend the CSS contract with button/form/alert behavior tests**

Add tests that assert `.button-danger`, `.button-quiet`, `.field`, `.field-label`, `.field-hint`, and all four alert modifiers occur in the stylesheet and that `.button-danger` uses the danger token.

- [ ] **Step 2: Run the focused test and verify red**

Run: `node --test tests/JavaScript/design-system.test.js`

Expected: FAIL on missing canonical selectors.

- [ ] **Step 3: Add canonical component CSS**

Add semantic modifiers while retaining `.button`, `.button-primary`, `button[type="submit"]`, `.form label`, `.field-error`, and `.alert` compatibility. The canonical rules must include visible focus, disabled, and loading states and must use semantic tokens rather than feature colors.

- [ ] **Step 4: Migrate representative form markup**

For the three representative views, wrap each user-facing field label with `.field`, add `.field-label` spans where markup is edited, and preserve all existing `name`, `required`, `min`, `max`, CSRF, action, and method attributes. Add `.button-quiet` to cancel/low-emphasis actions and keep submit actions primary.

- [ ] **Step 5: Run focused JavaScript and PHP tests**

Run: `node --test tests/JavaScript/design-system.test.js tests/JavaScript/form-validation.test.js tests/JavaScript/http.test.js` and `vendor/bin/phpunit tests/Unit/ViewEscapingTest.php tests/Unit/SecurityAuditTest.php`

Expected: all selected tests pass.

- [ ] **Step 6: Commit the form and button slice**

```bash
git add public/assets/css/app.css public/assets/js/forms.js views/auth/login.php views/products/create.php views/products/edit.php tests/JavaScript/design-system.test.js
git commit -m "feat: standardize core form and button components"
```

### Task 4: Normalize alerts, status badges, and stock/order state aliases

**Files:**
- Modify: `public/assets/css/app.css` (alert and status blocks)
- Modify: `views/products/index.php`
- Modify: `views/purchase-orders/index.php`
- Modify: `views/sales-orders/index.php`
- Modify: `views/users/index.php`
- Test: `tests/JavaScript/design-system.test.js`

**Interfaces:**
- Consumes: semantic tokens and canonical modifiers from Tasks 2–3.
- Produces: canonical status modifiers while retaining existing aliases such as `status-pendingapproval`, `status-partiallyreceived`, `status-cancelled`, and `stock-low`.

- [ ] **Step 1: Add status contract assertions**

Assert that canonical `.status-low`, `.status-normal`, `.status-active`, `.status-pending`, `.status-approved`, `.status-success`, `.status-warning`, and `.status-danger` selectors exist and that legacy aliases remain present.

- [ ] **Step 2: Run focused contract tests and verify red**

Run: `node --test tests/JavaScript/design-system.test.js`

Expected: FAIL for the canonical status selectors not yet implemented.

- [ ] **Step 3: Implement canonical status rules and aliases**

Group selectors by meaning, use semantic foreground/background tokens, and keep aliases in the same group. Do not change the status strings supplied by PHP views.

- [ ] **Step 4: Migrate representative status markup**

Add canonical classes alongside existing generated classes in products, purchase-order, sales-order, and user views. Preserve escaped dynamic class values and existing status text.

- [ ] **Step 5: Run focused tests**

Run: `node --test tests/JavaScript/design-system.test.js` and `vendor/bin/phpunit tests/Unit/ViewEscapingTest.php tests/Unit/SalesOrderControllerTest.php tests/Unit/PurchaseOrderControllerTest.php`

Expected: all selected tests pass.

- [ ] **Step 6: Commit the status slice**

```bash
git add public/assets/css/app.css views/products/index.php views/purchase-orders/index.php views/sales-orders/index.php views/users/index.php tests/JavaScript/design-system.test.js
git commit -m "feat: standardize operational status badges"
```

### Task 5: Improve modal, import-dialog, and confirmation-dialog component states

**Files:**
- Modify: `public/assets/css/app.css` (modal/dialog blocks)
- Modify: `public/assets/js/modal.js` only if a class or ARIA state needs a minimal contract-preserving change
- Modify: `public/assets/js/dialog.js` only if a class or ARIA state needs a minimal contract-preserving change
- Modify: `views/products/index.php`
- Modify: `views/users/index.php`
- Test: `tests/JavaScript/design-system.test.js`
- Existing tests: `tests/JavaScript/ui-helpers.test.js`, `tests/Unit/SecurityAuditTest.php`

**Interfaces:**
- Consumes: existing modal/dialog focus, loading, retry, and confirmation lifecycle.
- Produces: `.modal-footer` support, consistent panel/header/body spacing, clear loading/error/close states, and reduced-motion-safe transitions.

- [ ] **Step 1: Add modal contract assertions**

Assert that `.modal-footer` exists, dialog transitions use the semantic motion tokens, and the stylesheet contains visible styles for `[aria-busy="true"]` and `.modal-close:focus-visible`.

- [ ] **Step 2: Run focused tests and verify red**

Run: `node --test tests/JavaScript/design-system.test.js`

Expected: FAIL on the missing modal footer/contract selectors.

- [ ] **Step 3: Add modal/dialog CSS states**

Add `.modal-footer` as a flex action group, improve close-button focus/hover treatment, keep loading/error content readable, and ensure backdrop/panel transitions consume semantic motion tokens.

- [ ] **Step 4: Migrate import dialogs**

Add a footer/action-group wrapper to the products and users import dialogs only where it improves the component contract. Do not alter form action, CSRF token, upload fields, or submit behavior.

- [ ] **Step 5: Run focused tests and existing JavaScript tests**

Run: `node --test tests/JavaScript/*.test.js` and `vendor/bin/phpunit tests/Unit/SecurityAuditTest.php`

Expected: all selected tests pass.

- [ ] **Step 6: Commit the modal slice**

```bash
git add public/assets/css/app.css public/assets/js/modal.js public/assets/js/dialog.js views/products/index.php views/users/index.php tests/JavaScript/design-system.test.js
git commit -m "feat: refine modal and dialog states"
```

### Task 6: Migrate remaining representative views and run full verification

**Files:**
- Modify: remaining affected files under `views/` identified by selector audit
- Modify: `public/assets/css/app.css` only for cleanup of confirmed duplicate/legacy rules
- Modify: `docs/quality/design-system-fase-1-known-gaps.md` with evidence from this batch
- Test: existing Unit and JavaScript suites

**Interfaces:**
- Consumes: completed canonical component contract from Tasks 2–5.
- Produces: consistent core interaction usage across login, master-data forms, order pages, user/import flows, and representative error states.

- [ ] **Step 1: Audit remaining component usage**

Run:

```bash
rg -n 'class="(button|button-primary|form|alert|status-badge)|modal-|confirm-' views public/assets/css/app.css
```

Classify each result as canonical, compatibility alias, or business-specific markup. Do not remove a selector if a view or JS module still depends on it.

- [ ] **Step 2: Migrate only confirmed compatibility cases**

Update remaining master-data create/edit forms and order detail action groups to use canonical classes where the markup is already being touched. Preserve all hidden CSRF fields, method/action values, and permission-controlled conditionals.

- [ ] **Step 3: Update design-system evidence**

Record the implemented component contract, test commands, and any remaining literal/alias gaps in `docs/quality/design-system-fase-1-known-gaps.md`. Do not add temporary task recommendations to `.docs/` stable memory.

- [ ] **Step 4: Run full automated verification**

Run:

```bash
composer test:unit
composer test:javascript
composer analyse
git diff --check
```

Expected: all commands exit 0. If integration tests are available with a reachable MySQL service, also run `composer test:integration`; otherwise report the environment limitation explicitly.

- [ ] **Step 5: Perform manual smoke verification**

Inspect `/login`, `/dashboard`, `/products`, `/purchase-orders`, `/sales-orders`, and `/users` at desktop and narrow viewport widths. Verify keyboard focus, invalid field, loading submit, alert, status badge, import modal, and confirmation dialog states.

- [ ] **Step 6: Commit the completed enhancement**

```bash
git add public/assets/css/app.css public/assets/js views tests/JavaScript docs/quality/design-system-fase-1-known-gaps.md
git commit -m "feat: enhance core interaction design system"
```

## Plan Self-Review

- Spec coverage: tokens, buttons, forms, alerts, badges, modals, accessibility, migration, tests, and non-goals are covered by Tasks 1–6.
- Scope: no backend, database, route, authorization, or stock/order behavior changes are planned.
- Placeholder scan: no TBD/TODO/“implement later” steps are present.
- Type/selector consistency: all canonical selectors introduced in Tasks 2–5 are referenced by the contract tests and later migration tasks.
- Verification: full unit, JavaScript, static-analysis, diff, and manual smoke checks are defined before completion claims.
