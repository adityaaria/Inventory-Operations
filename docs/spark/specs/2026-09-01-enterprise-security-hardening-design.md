# Enterprise Security Hardening Design

Date: 2026-09-01
Source: Enterprise-readiness review after mandatory Rudis/SPARK phases and UI enhancement.

## Requirement IDs

- OPT-SEC-001: Audit trail baseline for authentication and mutating browser actions.
- OPT-SEC-002: Login rate limiting for repeated credential failures.
- OPT-SEC-003: Structured application error logging.

## Scope

Add a pragmatic enterprise baseline without changing mandatory stock invariants or introducing forbidden frameworks.

In scope:

- `audit_logs` persistence with actor, action, entity, status, request metadata, timestamp, and JSON metadata.
- Authentication audit events for login success, login failure, login blocked, and logout.
- Central POST action audit recorder after routing for create/update/activate/deactivate/order/receive/submit/approve/cancel/issue flows.
- `login_attempts` persistence and rate limiter keyed by normalized email and IP address.
- JSON-lines file logger for global exception handling.
- Unit and MySQL integration coverage.

Out of scope:

- Immutable append-only audit storage.
- External observability platform.
- Full before/after data diff audit.
- Production reverse proxy or WAF.

## Behavior

- Failed login attempts are recorded by email and IP.
- After 5 failures within 15 minutes, further login attempts for that email/IP are blocked before password verification.
- Successful login resets the failure window for the email/IP pair.
- Audit write failures are swallowed in the baseline implementation so the assessment app does not mask the original business outcome.
- POST action audit records are based on the routed path and response status after the controller finishes.

## Constraints

- Native PHP only.
- PDO prepared statements only.
- Controller remains HTTP-only.
- Service owns auth/rate-limit orchestration.
- Repository owns SQL.
- Stock receipt/issue transaction and ledger rules remain unchanged.
