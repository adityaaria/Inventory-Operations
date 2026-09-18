# Phase 6 UI Validation Security Errors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden responsive UI, backend validation, safe errors, output escaping, prepared statements, session security, upload safety, and DB constraints.

**Architecture:** Validators and services remain authoritative; controllers map exceptions to safe responses; views escape output; CSS remains custom and framework-free.

**Tech Stack:** PHP 8.2+, MySQL 8, PDO prepared statements, PHPUnit 10, PHPStan level 5, Docker Compose, HTML, custom CSS, Vanilla JS.

## Global Constraints

- PHP 8.2+ Native OOP.
- MySQL 8 + PDO prepared statements.
- Controller -> Service -> Repository separation.
- Repository interface boundary with manual constructor injection.
- HTML + custom CSS + Vanilla JS + Fetch API.
- Docker Compose.
- PHPUnit unit + integration tests.
- PHPStan level 5+ preferred.
- No Laravel/CodeIgniter/Symfony/Slim/ORM/framework DI container.
- No React/Vue/Angular/jQuery/CSS framework/admin template.
- Backend validation is source of truth.
- Phase 6 must not add new business workflows or optional infrastructure.

---

## File Structure Map

- Create/modify validators in `app/Validation`.
- Create/modify exception types in `app/Exception`: `ValidationException`, `ForbiddenException`, `UnauthenticatedException`.
- Modify controllers to map validation/auth/state errors.
- Modify views to escape all user-controlled output.
- Modify `public/assets/css/app.css` for 360px/desktop usability.
- Modify `config/bootstrap.php` for safe production error handling.
- Update tests and evidence docs.

---

### Task 1: Exception and Error Mapping

**Files:**
- Create: `app/Exception/ValidationException.php`, `ForbiddenException.php`, `UnauthenticatedException.php`
- Modify: `public/index.php`, `views/errors/*`
- Test: `tests/Unit/ErrorResponseTest.php`

- [x] Add tests for 401, 403, 404, 422, 409-style safe responses and API JSON behavior.
- [x] Implement explicit exception classes with HTTP status accessors.
- [x] Map browser errors to safe HTML and API errors to JSON.
- [x] Run `composer test -- --filter ErrorResponseTest`.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 2: Shared Validators

**Files:**
- Create: `app/Validation/InputValidator.php`, `app/Validation/ValidationResult.php`
- Modify: services to use validator helpers
- Test: `tests/Unit/ValidationTest.php`

- [x] Add tests for required strings, emails, allowed enums, positive integers, non-negative money, and dates.
- [x] Implement validator methods returning normalized values or throwing `ValidationException`.
- [x] Introduce centralized validation helpers without changing existing business workflows.
- [x] Run `composer test -- --filter Validation`.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 3: Escaping and View Audit

**Files:**
- Modify: all `views/**/*.php`
- Test: `tests/Unit/ViewEscapingTest.php`

- [x] Add test that `<script>` content is escaped by the shared HTML helper.
- [x] Add a small helper `e(string $value): string` and ensure it uses `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- [x] Verify existing rendered user fields use `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- [x] Run `composer test -- --filter ViewEscaping`.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 4: SQL and Session Security Audit

**Files:**
- Modify: repositories, `config/bootstrap.php`, session classes
- Test: `tests/Unit/SecurityAuditTest.php`

- [x] Add filesystem-backed tests checking repositories do not read raw `$_GET`, `$_POST`, or `$_REQUEST`.
- [x] Verify user-input SQL paths remain parameterized through repositories and allow-listed sort values.
- [x] Configure `HttpOnly`, `SameSite`, and environment-aware `Secure` session cookie settings before `session_start()`.
- [x] Run `composer test -- --filter SecurityAudit`.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 5: Responsive CSS and UX Failure States

**Files:**
- Modify: `public/assets/css/app.css`, key views
- Evidence: `docs/testing/test-scenarios.md`

- [x] Review login, user, product, PO, SO, dashboard, and report page structure for desktop and narrow layouts.
- [x] Review the same page classes for 360px constraints through CSS rules.
- [x] Add CSS for readable forms, horizontally safe tables, navigation wrapping, empty states, and error messages.
- [x] Do not introduce CSS framework or admin template.
- [x] Record manual HTTP/runtime evidence dated 2026-08-31.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 6: Constraint and Upload Review

**Files:**
- Modify: `database/schema-and-seed.sql`, upload validation service if product images exist
- Test: `tests/Unit/UploadValidationTest.php`, DB integration tests as needed

- [x] Verify DB constraints remain covered by schema and existing integration tests: unique email/SKU/order numbers, non-negative stock/prices/reorder point, positive item quantity, FK relations.
- [x] Confirm product upload does not exist in current mandatory scope, so upload MIME/size/random filename tests are not applicable.
- [x] Document coverage through Phase 6 verification evidence.
- [x] Run relevant DB tests through full `composer test`.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 7: Verification and Evidence

**Files:**
- Modify: `docs/testing/test-scenarios.md`, `docs/quality/phpstan-report.txt`, `docs/quality/tech-debt.md`, `ai-usage-log.md`

- [x] Run `composer test -- --filter Validation`.
- [x] Run targeted Phase 6 security/error/escaping tests.
- [x] Run `composer test`.
- [x] Run `composer analyse`.
- [x] Run `APP_PORT=8082 docker compose up -d --build app`.
- [x] Record responsive/security/manual evidence honestly.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

## Self-Review

This plan covers validation, errors, escaping, prepared statements, session security, responsive UI, constraints, upload safety, tests, and evidence. It excludes new business features and optional infrastructure.

## Execution Handoff

Plan complete and saved to `docs/spark/plans/2026-08-31-phase-6-ui-validation-security-errors.md`. Use Subagent-Driven or Inline Execution before implementation.
