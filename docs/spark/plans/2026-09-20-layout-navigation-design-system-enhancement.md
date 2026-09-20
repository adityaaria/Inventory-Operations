# Layout & Navigation Design System Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Improve the shared application shell, navigation, page headers, toolbars, and responsive layout without changing routes, authorization, business workflows, or persistence behavior.

**Architecture:** Extend the existing `app.css` and progressive `navigation.js` shell builder. Keep server-rendered pages usable if JavaScript fails, and keep the current `InventoryNavigation.buildShell()` entry point and route list.

**Tech Stack:** PHP 8.2+, server-rendered PHP, custom CSS, Vanilla JS, Node built-in test runner, PHPUnit, PHPStan.

## Global Constraints

- No frontend framework, CSS framework, new runtime dependency, route, permission, authorization, API, repository, service, or database change.
- Existing navigation labels, URLs, route matching, auth-page exclusion, and status vocabulary remain unchanged.
- New behavior is test-first: write a failing contract test, verify RED, implement minimally, verify GREEN.
- Preserve progressive enhancement and existing desktop behavior while adding the mobile drawer.

### Task 1: Add failing shell and navigation contract tests

**Files:**
- Modify: `tests/JavaScript/design-system.test.js`
- Create: `tests/JavaScript/navigation.test.js`
- Read: `public/assets/css/app.css`, `public/assets/js/navigation.js`

**Steps:**

1. Add CSS contract assertions for `.app-shell`, `.sidebar`, `.main-content`, `.page-header`, `.toolbar`, `.skip-link`, `.sidebar-toggle`, and `.sidebar-backdrop`.
2. Add DOM-free source-contract assertions for `navigation.js`: `aria-current`, `aria-expanded`, `aria-controls`, stable main-content target, Escape handling, backdrop handling, focus restoration, and auth-body exclusion.
3. Run `node --test tests/JavaScript/design-system.test.js tests/JavaScript/navigation.test.js` and verify RED for the new contract.
4. Commit only the failing test contract as `test: define layout and navigation contracts`.

### Task 2: Implement shell, navigation accessibility, and mobile drawer

**Files:**
- Modify: `public/assets/js/navigation.js`
- Modify: `tests/JavaScript/navigation.test.js`

**Steps:**

1. Preserve the existing shell creation and navigation item list.
2. Assign a stable sidebar id and add a skip link targeting `main.page` with a stable id.
3. Add a mobile menu button and backdrop with accessible attributes.
4. Implement open/close state, `aria-expanded`, `is-open` classes, body scroll lock, backdrop click, Escape close, and focus return.
5. Add `aria-current="page"` to the active navigation link while preserving pathname matching.
6. Ensure auth pages and already-built shells remain excluded.
7. Run focused navigation tests, then all JavaScript tests; verify GREEN.
8. Commit as `feat: add accessible responsive navigation shell`.

### Task 3: Add desktop and responsive layout styling

**Files:**
- Modify: `public/assets/css/app.css`
- Modify: `tests/JavaScript/design-system.test.js`

**Steps:**

1. Add only needed shell/layout tokens and visible focus styling.
2. Refine sidebar, active nav, main-content, page-header, and toolbar spacing without changing their semantic classes.
3. Add skip-link styles and desktop-hidden/mobile-visible menu/backdrop styles.
4. Add a mobile breakpoint where the sidebar becomes an off-canvas drawer, content fills the viewport, and background scrolling is locked while open.
5. Make page headers/toolbars wrap or stack without clipping; keep wide tables horizontally scrollable.
6. Make dashboard grid collapse across desktop, tablet, and mobile while retaining existing component classes.
7. Respect the existing reduced-motion rule.
8. Run CSS contract tests and all JavaScript tests; verify GREEN.
9. Commit as `feat: refine responsive application layout`.

### Task 4: Verify page compatibility and accessibility contracts

**Files:**
- Read: `public/assets/js/app.js`
- Read: representative `views/*/index.php` pages and auth views
- Modify: `tests/JavaScript/navigation.test.js` only if a missing stable contract is exposed

**Steps:**

1. Confirm `app.js` still initializes navigation before page enhancements.
2. Confirm representative operational pages provide `main.page` and continue to receive the shell.
3. Confirm authentication pages retain `auth-body` and do not receive the shell.
4. Confirm no route, link label, or authorization boundary changed.
5. Run PHP syntax checks for all views and focused view/security tests if markup was touched.

### Task 5: Full verification and evidence update

**Files:**
- Modify: `docs/quality/design-system-fase-1-known-gaps.md`
- Read: approved spec and all changed files

**Steps:**

1. Run all JavaScript tests, Docker PHPUnit unit/integration suites, Docker PHPStan, all view syntax checks, and `git diff --check`.
2. Perform manual smoke verification for desktop navigation, active route, mobile drawer, backdrop, Escape, focus return, scroll lock, skip link, page header/toolbar wrapping, dashboard grid, and authentication-page exclusion. If browser automation remains unavailable, record that limitation rather than fabricating evidence.
3. Update the quality evidence with implemented scope, test results, and remaining manual/browser gaps.
4. Commit evidence as `docs: record layout navigation design system evidence`.

## Completion Criteria

- All new navigation/layout contract tests pass.
- Existing JavaScript, PHPUnit, integration, PHPStan, and syntax checks pass.
- Shell behavior is unchanged for desktop routes and auth pages.
- Mobile drawer behavior is keyboard accessible and progressively enhanced.
- No route, authorization, business-flow, or persistence behavior changes.
- Quality evidence and known gaps are current.
