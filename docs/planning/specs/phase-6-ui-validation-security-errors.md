# Phase 6 Specification: UI, Validation, Security, and Error Handling

Status: Approved for planning  
Date: 2026-08-31  
References: `AGENTS.md`, `SDD.md`, `PLAN.md`, `docs/planning/CONSTITUTION.md`

## 1. Purpose

Phase 6 hardens the mandatory application surface after core workflows exist. It verifies responsive usability, backend-authoritative validation, safe errors, output escaping, prepared statements, session security, and database constraints.

Exit gate: intentional failure paths are safe.

## 2. Requirement Trace

- `VAL-01`: validation across browser and backend.
- `ERR-01`: safe 401/403/404/422/409/500 handling.
- `UI-01`: desktop and 360px responsive usability.
- `DB-01`: constraints/indexes review.
- Security design: prepared statements, escaping, session safety, upload safety.
- `TEST-01..03`: regression tests and static analysis remain acceptable.

## 3. In Scope

- Responsive review for desktop and 360px width.
- Frontend convenience validation where useful.
- Backend authoritative validation for all state-changing actions.
- Safe 403/404/500 pages and API error behavior.
- Output escaping audit for HTML.
- Prepared statement audit for SQL using user input.
- Session security audit.
- Upload validation audit.
- CSRF tokens for state-changing browser forms if mandatory scope remains stable.
- Database constraints and indexes review.
- Evidence updates with screenshots or documented manual checks.

## 4. Out of Scope

- New business workflows.
- Optional audit trail, idempotency, Redis, queueing, external monitoring, or production rate limiting.
- Full automated E2E suite unless time permits after mandatory checks.
- Replacing custom CSS with a CSS framework or admin template.

## 5. Architecture Contract

Validation logic belongs in services/validators, not only JavaScript. Views render escaped output and form feedback. Controllers translate validation exceptions to safe HTTP responses.

Security helpers should be centralized enough to avoid repeated ad hoc role/session checks.

## 6. UI Contract

- Layout must be usable at 360px width and desktop width.
- Navigation should expose only relevant role actions, while server checks remain authoritative.
- Forms must show clear validation feedback without raw exception details.
- Lists and dashboards must have loading/empty/error-safe states where applicable.
- Custom CSS only; no CSS framework or admin template.

## 7. Security and Validation Rules

- Required fields validated frontend and backend.
- Backend is source of truth.
- Enum values validated against allow-lists.
- IDs reference existing active records where required.
- Numeric values are type-checked and range-checked.
- Dates are validated.
- Failed validation must not persist partial transaction data.
- HTML output uses `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` or equivalent escaping.
- Database exceptions are logged server-side and hidden from production users.

## 8. Error Handling

- Browser unauthenticated access redirects to login or returns 401 according to route type.
- Authenticated unauthorized access returns 403.
- Missing resources return 404.
- Validation failures return form feedback or 422.
- Invalid state/conflict may return 409.
- API errors return JSON with correct status.
- Production mode must not expose SQL, stack traces, secrets, or raw exception details.

## 9. Tests

Required tests:

- Backend rejects invalid required fields.
- Backend rejects invalid enums.
- Backend rejects invalid IDs.
- Backend rejects negative quantities/prices/reorder points.
- Unauthorized access returns 403.
- API missing/unauthenticated paths return JSON.
- HTML escaping protects rendered user-provided text.
- Prepared-statement audit finds no raw user-input concatenation.
- Upload validation rejects disallowed MIME/oversized files if uploads are implemented.

Responsive checks may be documented with screenshots/manual evidence if no E2E suite exists.

## 10. Acceptance Criteria

1. Application is usable on desktop and 360px width.
2. Validation is backend-authoritative across implemented workflows.
3. Safe error behavior exists for expected failure classes.
4. Output escaping is applied to user-controlled HTML.
5. SQL user input uses prepared statements.
6. Session and upload security are reviewed.
7. DB constraints/indexes are reviewed against SDD requirements.
8. Tests, static analysis, and evidence are updated.

## 11. Verification Commands

```bash
composer test -- --filter Validation
composer test -- --filter Authorization
composer test -- --filter Error
composer test
composer analyse
docker compose up --build
```

Manual evidence should include desktop and 360px screenshots for representative pages.

## 12. Risks and Decisions

- CSRF is recommended but must not delay mandatory scope; if implemented, keep it simple and native PHP.
- Responsive evidence cannot be fabricated; failed screenshots must be recorded honestly.
- Security audit must inspect server behavior, not only UI visibility.

## 13. Handoff to Implementation Plan

Plan tasks should audit implemented workflows, add missing validation/security/error handling, update CSS, run tests/static analysis, capture evidence, and record remaining risks.
