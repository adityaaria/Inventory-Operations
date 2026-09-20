# Typography & Palette Design System Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Centralize typography and active palette values while reducing identified legacy blue/teal mismatches without changing markup or application behavior.

**Architecture:** Keep the enhancement in `public/assets/css/app.css`, extend the existing root token layer, and migrate only high-value selectors and explicitly identified palette literals.

**Tech Stack:** Custom CSS, server-rendered PHP, Vanilla JS, Node built-in test runner, PHPUnit, PHPStan, Docker Compose.

## Global Constraints

- No markup, JavaScript behavior, route, permission, authorization, API, repository, service, query, or database change.
- Preserve status color meaning and invalid/error feedback.
- Keep font family and component geometry unchanged unless token substitution naturally preserves the current value.
- New behavior is test-first: write a failing contract test, verify RED, implement minimally, verify GREEN.

### Task 1: Add failing typography and palette contract tests

**Files:**
- Modify: `tests/JavaScript/design-system.test.js`
- Read: `public/assets/css/app.css`

**Steps:**

1. Add assertions for `--fs-xs`, `--fs-sm`, `--fs-md`, `--fs-lg`, `--fs-xl`, `--fs-2xl`, and all four `--fw-*` tokens.
2. Add assertions that primary selectors reference typography tokens: `body`, `h1`, `.page-subtitle`, `.app-title`, `.side-nav a`, `.data-table`, and `.metric-card`.
3. Add assertions for active palette aliases and migrated legacy areas: page background, `.app-title`, and relevant hover surfaces.
4. Add a source assertion that semantic status tokens remain present.
5. Run the focused tests and verify RED.
6. Commit only the failing tests as `test: define typography palette contracts`.

### Task 2: Add typography and palette tokens

**Files:**
- Modify: `public/assets/css/app.css`
- Test: `tests/JavaScript/design-system.test.js`

**Steps:**

1. Add the six font-size tokens with values close to the current scale.
2. Add regular, medium, semibold, and bold weight tokens matching current declarations.
3. Add any missing semantic palette aliases needed for page tint, active surface, accent soft, and legacy shadow/background migration.
4. Keep existing tokens as compatibility aliases where they are already consumed.
5. Run focused contract tests and verify the token portion is GREEN.
6. Commit as `feat: add typography and palette tokens`.

### Task 3: Migrate primary typography selectors

**Files:**
- Modify: `public/assets/css/app.css`
- Test: `tests/JavaScript/design-system.test.js`

**Steps:**

1. Replace scattered font-size declarations on `body`, headings, `.app-title`, `.page-subtitle`, buttons, navigation, metadata, data tables, and metric values with the approved tokens.
2. Replace matching weight declarations with `--fw-*` tokens.
3. Preserve responsive heading behavior by keeping the existing `clamp()` relationship or mapping it to the page-heading token without changing its intended range.
4. Keep compact table and badge text readable at existing breakpoints.
5. Run all JavaScript tests and inspect the diff for selector/geometry changes.
6. Commit as `feat: standardize design system typography`.

### Task 4: Migrate identified legacy palette literals

**Files:**
- Modify: `public/assets/css/app.css`
- Test: `tests/JavaScript/design-system.test.js`

**Steps:**

1. Replace the page background blue/teal radial gradients with active palette tint tokens.
2. Replace the login/auth background legacy gradient with active palette aliases while preserving the existing auth surface contrast.
3. Replace `.app-title` teal border and mint surface with accent-compatible tokens.
4. Replace identified blue hover backgrounds/shadows and pale legacy spinner track only where the semantic role is already clear.
5. Do not replace success, warning, danger, info, draft, invalid, or status-specific colors without a separate decision.
6. Run all JavaScript tests and `git diff --check`.
7. Commit as `feat: align legacy palette surfaces`.

### Task 5: Full verification and evidence update

**Files:**
- Modify: `docs/quality/design-system-fase-1-known-gaps.md`
- Read: approved spec and all changed CSS/tests

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

2. Perform manual smoke review for headings, navigation, buttons, tables, metric cards, status/error surfaces, focus rings, dashboard background, and login background. If browser tooling remains unavailable, record the limitation.
3. Update evidence with migrated tokens, remaining legacy literals, test results, and manual review status.
4. Commit evidence as `docs: record typography palette design system evidence`.

## Completion Criteria

- All typography and palette contract tests pass.
- Existing JavaScript, PHPUnit, integration, PHPStan, and syntax checks pass.
- Status and error semantics remain intact.
- No markup, route, authorization, business-flow, or persistence changes occur.
- Remaining legacy palette literals are explicitly documented.
