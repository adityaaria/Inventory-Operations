# Testing Strategy

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Test Tooling

PHPUnit 10.5 is configured with separate Unit and Integration suites. Composer scripts run all tests, unit tests, integration tests, and PHPStan analysis.

Evidence:
- `composer.json`
- `phpunit.xml`

## Unit Test Coverage Shape

Unit tests cover:
- domain entities and value/status behavior
- service authorization and transition rules
- in-memory repositories and duplicate-line checks
- stock receipt/issue deterministic locks and rollback behavior
- auth, session, login rate limit, audit logging
- controllers and HTTP responses
- search criteria and pagination defaults
- CSV import/export safety
- view escaping and CSRF form presence
- router and API error behavior
- dashboard/report services

Evidence:
- `tests/Unit/`

## Integration Test Coverage Shape

Integration tests use real MySQL/PDO configuration and cover:
- schema and constraint presence
- seeded demo data volume and statuses
- user repository persistence
- master-data repository and stock queries
- purchase order receipt stock/ledger/status behavior and rollback on ledger failure
- sales order issue oversell prevention and clean ledger on failure
- audit log and login-attempt repositories

Evidence:
- `tests/Integration/`
- `tests/Integration/PurchaseOrderReceiptIntegrationTest.php`
- `tests/Integration/SalesOrderIssueIntegrationTest.php`
- `tests/Integration/SecurityAuditRepositoryIntegrationTest.php`

## Static Analysis

PHPStan level 5 analyzes `app`, `config`, and `public`, with cache under `var/phpstan`.

Evidence:
- `phpstan.neon`
- `composer.json`

## JavaScript Behavior Tests

The browser Fetch and UI behavior boundaries have focused Node test coverage for
successful responses, unexpected HTTP responses, allowed validation responses,
abort-signal forwarding, stale-request cancellation, confirmation matching,
debounced table filtering, table sorting, and CSV escaping. Run it with
`composer test:javascript`. The tests remain DOM-free so they
can validate behavior without introducing a frontend framework or browser test runner.

Evidence:
- `public/assets/js/http.js`
- `public/assets/js/ui-helpers.js`
- `tests/JavaScript/http.test.js`
- `tests/JavaScript/ui-helpers.test.js`
- `composer.json`

The frontend interaction layer is split into navigation, modal, chart, browser-neutral
HTTP, UI-helper, and form-validation modules loaded before `app.js`. Modal accessibility behavior includes
focus trapping, focus restoration, `aria-busy`, and live error content; these behaviors
still require browser-level verification for full DOM evidence.

Evidence:
- `public/assets/js/app.js`
- `public/assets/js/navigation.js`
- `public/assets/js/modal.js`
- `public/assets/js/charts.js`
- `public/assets/js/forms.js`
- `public/assets/js/tables.js`
- `public/assets/js/http.js`
- `public/assets/js/ui-helpers.js`
- `public/assets/js/form-validation.js`
- `docs/quality/javascript-runtime-evidence.md`

## Integration Prerequisites

Integration tests expect a reachable MySQL database using environment variables or defaults:
- `DB_HOST` default `127.0.0.1`
- `DB_PORT` default `3306`
- `DB_DATABASE` default `inventory_order_management`
- `DB_USERNAME` default `inventory_app`
- `DB_PASSWORD` default `change_me_for_local_only`

Evidence:
- `tests/Integration/PurchaseOrderReceiptIntegrationTest.php`
- `tests/Integration/SalesOrderIssueIntegrationTest.php`
- `app/Support/DatabaseFactory.php`
- `compose.yaml`

## Reusable Test Patterns

- Use in-memory repositories for service and controller unit tests.
- Use MySQL repository implementations for integration tests that validate schema, SQL, locks, and transaction behavior.
- Stock safety tests assert both stock quantity and ledger cleanliness after failures.
- Security tests inspect source/view strings for repository superglobal isolation and CSRF fields in POST forms.

Evidence:
- `app/Repository/InMemory/`
- `tests/Unit/SecurityAuditTest.php`
- `tests/Unit/StockServiceReceiptTest.php`
- `tests/Unit/StockServiceIssueTest.php`
- `tests/Integration/PurchaseOrderReceiptIntegrationTest.php`
- `tests/Integration/SalesOrderIssueIntegrationTest.php`

## Gaps / Unknowns

- Browser-level end-to-end tests were not found.
- No coverage report configuration was found in `phpunit.xml`.
