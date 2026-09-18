# Phase 1 Specification: Authentication and Authorization

Status: Approved for planning  
Date: 2026-08-31  
References: `AGENTS.md`, `SDD.md`, `PLAN.md`, `docs/planning/CONSTITUTION.md`, `docs/planning/specs/phase-0-bootstrap.md`

## 1. Purpose

Phase 1 adds login, logout, authenticated session handling, role-aware authorization, and Admin-only user management. This phase establishes the security boundary used by every later workflow.

Exit gate: all three roles can login, protected routes reject anonymous users, and Sales/Warehouse Staff cannot access user administration endpoints.

## 2. Requirement Trace

- `AUTH-01`: login, credential verification, active-user check, secure session identity.
- `AUTH-02`: logout route and session clearing.
- `USR-01`: Admin-only user management.
- `VAL-01`: backend validation for auth and user inputs.
- `ERR-01`: safe 401/403/404/422 behavior.
- `ARCH-01`: service logic unit-testable using repository interfaces/fakes.
- `TEST-01`: auth/user unit tests.
- `TEST-02`: database-backed auth/user integration checks.

## 3. In Scope

- User schema with unique email, role enum, active flag, password hash, and timestamps.
- Demo accounts for one Admin, at least two Sales, and at least two Warehouse Staff.
- `UserRepositoryInterface`, MySQL implementation, and InMemory/Fake implementation for tests.
- `AuthService` for login credential validation and inactive-user rejection.
- `AuthContext`, `AuthGuard`, and `Authorization` helpers for server-side permission checks.
- `AuthController` for `GET /login`, `POST /login`, and `POST /logout`.
- `UserController` for Admin-only user list/create/update/status.
- Generic invalid credential message.
- Session ID regeneration after successful login.
- POST-only logout.
- Unit/integration tests for auth, role checks, inactive user, and Admin-only user management.

## 4. Out of Scope

- Product/master-data CRUD beyond user management.
- Purchase Order, Sales Order, stock, dashboard, reports, API, and low-stock job.
- Password reset, email verification, SSO, OAuth, MFA, Redis-backed sessions, rate limiting.
- Client-only authorization behavior as a substitute for server checks.

## 5. Architecture Contract

Controllers must only translate HTTP input/output and call services/guards. `AuthService` owns credential verification. `UserService` owns user management rules. Repositories own SQL and mapping only.

Services must depend on repository interfaces and must not read `$_POST`, `$_GET`, or `$_SESSION` directly. Session access must be isolated behind a small session/auth context abstraction so service tests can use fake dependencies.

## 6. Data Contract

Add `users` table:

- `id`
- `name`
- `email` unique
- `password_hash`
- `role` allow-list: `Admin`, `Sales`, `WarehouseStaff`
- `is_active`
- `created_at`
- `updated_at`

Passwords must be stored using `password_hash()` and verified with `password_verify()`. Demo credentials must be clearly fake and documented safely.

## 7. Authorization Rules

- Anonymous users may access login routes only.
- Authenticated users may logout and view their own basic profile/dashboard entry.
- Admin may manage users.
- Sales and Warehouse Staff must receive 403 for user administration endpoints.
- Inactive users cannot login.
- Authorization must be enforced server-side even if a route is called manually.

## 8. Validation and Errors

- Email required and valid.
- Email unique for user create/update.
- Name required.
- Role must be one of the allowed roles.
- Password required on create and must be hashed before storage.
- Login failure returns a generic invalid credential message.
- Unauthenticated browser page access redirects to login or returns 401 according to route type.
- Unauthorized access returns 403 with safe content.

## 9. Tests

Required tests:

- Admin can authenticate with valid active credentials.
- Invalid credentials are rejected with generic failure.
- Inactive user cannot authenticate.
- Successful login regenerates session ID or calls the session abstraction method for regeneration.
- Logout clears authenticated identity.
- Sales cannot access user management.
- Warehouse Staff cannot access user management.
- Admin can create/update/deactivate users.
- Duplicate email is rejected.

Unit tests must use fake repositories/session abstractions. Integration tests must verify MySQL schema, unique email, seeded demo users, and login lookup by email.

## 10. Acceptance Criteria

1. Three roles can login from seeded demo users.
2. Protected routes require authentication.
3. Admin-only user management works from server-side checks.
4. Sales and Warehouse Staff are blocked from user management with 403.
5. Passwords are hashed; no plaintext passwords are stored.
6. Session ID is regenerated after login and cleared on logout.
7. Unit and integration tests cover required auth/user behavior.
8. PHPStan remains acceptable.

## 11. Verification Commands

```bash
composer test -- --filter Auth
composer test -- --filter User
composer test
composer analyse
docker compose up --build
```

## 12. Risks and Decisions

- Session implementation must stay small and testable; avoid framework-style abstractions.
- Demo passwords must be safe, fake, and not confused with production credentials.
- Any ambiguity about role naming must preserve exact `Admin`, `Sales`, and `WarehouseStaff` values from `SDD.md`.

## 13. Handoff to Implementation Plan

Plan tasks should implement user schema/seeds first, then repository contracts, auth/session abstractions, authorization helpers, controllers/views, tests, and evidence updates.
