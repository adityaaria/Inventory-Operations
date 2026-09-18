# Frontend Reliability and Accessibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Strengthen the existing Vanilla JavaScript interaction layer with accessible modal/form behavior, lightweight client validation, focused module boundaries, and reproducible evidence.

**Architecture:** Keep browser behavior in Vanilla JavaScript loaded by the existing PHP views. Keep business validation and authorization in PHP services; client validation only improves feedback. Extract reusable browser-neutral helpers and focused UI modules without introducing a bundler or frontend framework.

**Tech Stack:** PHP views, HTML, custom CSS, Vanilla JavaScript, Fetch API, Node built-in test runner, PHPUnit, PHPStan, Docker Compose.

## Global Constraints

- Preserve PHP 8.2+, MySQL 8, Controller → Service → Repository architecture.
- Do not add React, Vue, Angular, jQuery, TypeScript, CSS framework, ORM, or frontend framework.
- Backend remains the source of truth for validation and authorization.
- Stock mutations remain exclusively inside the existing transactional stock service.
- Use TDD for new JavaScript behavior and verify with fresh commands.

### Task 1: Accessible modal and form state

**Files:**
- Modify: `public/assets/js/app.js`
- Modify: `public/assets/css/app.css`
- Test: `tests/JavaScript/ui-helpers.test.js`

- [x] Add modal focus restoration, `aria-busy`, and live error status.
- [x] Keep loading and disabled state consistent for success, error, and cancellation.
- [x] Run JavaScript tests and syntax checks.

### Task 2: Lightweight client validation

**Files:**
- Create/modify: `public/assets/js/form-validation.js`
- Modify: `public/assets/js/app.js`
- Test: `tests/JavaScript/form-validation.test.js`

- [x] Validate required fields and numeric min/max constraints from HTML attributes.
- [x] Render accessible field errors without replacing server-side validation.
- [x] Test valid, invalid, and corrected form states.

### Task 3: Module boundaries

**Files:**
- Modify: `public/assets/js/app.js`
- Modify: `public/assets/js/http.js`
- Modify: `public/assets/js/ui-helpers.js`
- Modify: all existing view/controller script includes as required.

- [x] Keep HTTP, validation, modal, navigation, table, and chart responsibilities independently callable.
- [x] Avoid changing existing routes or business workflows.
- [x] Run all JavaScript tests and syntax checks.

### Task 4: Evidence and profiling notes

**Files:**
- Create: `docs/quality/javascript-runtime-evidence.md`
- Modify: `.docs/TESTING_STRATEGY.md`

- [x] Document commands, measured script size, behavior boundaries, and remaining browser-level test gap.
- [x] Do not fabricate DevTools or browser metrics; mark unavailable evidence explicitly.

### Task 5: Full verification

- [x] Run `node --test tests/JavaScript/*.test.js`.
- [x] Run `node --check` on every JavaScript asset.
- [ ] Run `composer test`, `composer analyse`, and Docker equivalents where available.
- [x] Report blockers without claiming unavailable commands passed.
