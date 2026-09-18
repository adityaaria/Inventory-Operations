# Enterprise Security Hardening Plan

Date: 2026-09-01

## Checklist

- [x] Add failing unit tests for audit logging and login rate limiting.
- [x] Add in-memory repository implementations for unit tests.
- [x] Add MySQL repository contracts and integration tests.
- [x] Add `audit_logs` and `login_attempts` schema.
- [x] Wire audit logger and rate limiter into authentication flow.
- [x] Add central request audit recorder for non-auth POST actions.
- [x] Add structured JSON file logger for global exceptions.
- [x] Add unit coverage for JSON log format.
- [x] Apply safe DDL-only update to the existing Docker database.
- [x] Run Docker smoke test for failed-login rate limit and audit rows.
- [x] Run syntax, unit/integration, full PHPUnit, and static analysis verification.
- [x] Update quality, test, progress, and AI usage evidence.

## Verification Targets

- `composer test -- --filter 'AuditLoggerTest|LoginRateLimiterTest|JsonFileLoggerTest|RequestAuditRecorderTest|AuthServiceTest|SecurityAuditRepositoryIntegrationTest'`
- `composer test`
- `composer analyse`
- `find app public tests -name '*.php' -print0 | xargs -0 -n1 php -l`
- `APP_PORT=8082 docker compose up -d --build app`
- Runtime smoke against `localhost:8082/login` with 6 failed attempts for a unique email.
