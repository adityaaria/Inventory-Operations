# Release Readiness

Date: 2026-08-31  
Status: Historical assessment verification; superseded by the current status below

## Current status — 6 October 2026

Git history is available; the previous no-Git limitation is historical. Docker runtime/test targets and an isolated disposable `test-db` now provide a repeatable quality gate. Current source-order locking, authorization revocation, strict input/import/CSV handling, product detail and reporting evidence is in `../testing/audit-remediation-2026-10-06.md`. Preserve the dated counts below as historical results rather than current claims. Physical-device checks and trainer ambiguities still need external validation.

## Clean Docker and Empty DB Verification

The destructive `docker compose down -v` command was not run against the existing project because it can delete local MySQL volume data. Instead, release verification used an isolated Compose project:

```bash
COMPOSE_PROJECT_NAME=rudis_phase8 APP_PORT=8083 DB_HOST_PORT=3307 docker compose up -d --build app
```

Result: app image built, clean `rudis_phase8_mysql-data` volume was created, MySQL became healthy, and the app started on `localhost:8083`.

Seed verification query results from the clean DB:

| Metric | Result |
|---|---:|
| Admin users | 1 |
| Sales users | 2 |
| Warehouse Staff users | 2 |
| Warehouses | 2 |
| Products | 30 |
| Purchase Orders | 13 |
| Sales Orders | 12 |
| PendingApproval Sales Orders | 3 |
| Cancelled Sales Orders | 2 |
| Low-stock rows | 7 |

## Runtime Smoke

HTTP smoke against the isolated clean app:

| Route / Flow | Result |
|---|---|
| `GET /login` | CSRF token present. |
| `POST /login` with Admin credentials and CSRF token | `302`. |
| `GET /` | `200`. |
| `GET /products?q=Demo&page=1` | `200`, includes `SKU-DEMO-001`. |
| `GET /purchase-orders` | `200`. |
| `GET /sales-orders` | `200`. |
| `GET /dashboard` | `200`. |
| `GET /reports/orders.csv` | `200`, CSV header `Type,OrderNumber,Party,Status,Date`. |
| `GET /api/products/SKU-DEMO-001/availability` | `200`. |

## Final Test and Analysis Gate

| Command | Result |
|---|---|
| `DB_PORT=3307 composer test` | Pass: 87 tests, 336 assertions. |
| `DB_PORT=3307 composer test:unit` | Pass: 75 tests, 285 assertions. |
| `DB_PORT=3307 composer test:integration` | Pass: 12 tests, 51 assertions. |
| `composer analyse` | Pass: PHPStan level 5, 96 files, no errors. |

## Security and Forbidden Technology Audit

Findings:

- `.env` is absent from the workspace.
- `.gitignore` includes `/.env`.
- `.env.example` contains placeholder-only local values.
- Secret grep matches are expected references to placeholders, demo password hashing, CSRF variable names, docs, and tests; no production key material was found.
- Forbidden technology grep matches are only documentation statements and bootstrap naming; `composer.json` contains no forbidden framework, ORM, frontend framework, jQuery, or CSS framework dependency.

## Invariant Audit

Stock:

- `StockService::receive()` and `StockService::issue()` are the transaction boundary for stock mutations.
- `MySqlStockRepository::lockByProductWarehouse()` uses `SELECT ... FOR UPDATE`.
- Receipt and issue paths append ledger rows through `StockLedgerRepositoryInterface`.
- Rollback and oversell behavior are covered by integration tests.

Authorization:

- Protected controllers use `AuthGuard`.
- User management requires Admin through `AuthGuard::requireUserManagement()`.
- PO create/receive and SO create/approve/issue enforce workflow-specific roles server-side.
- Sales own-order behavior and approval blocking are covered by tests.

Dashboard/report:

- Dashboard and report values come from `OperationalQueryRepositoryInterface` and MySQL aggregate queries.
- No dashboard totals are hardcoded.

## Requirement Traceability

| ID | Implementation / Evidence |
|---|---|
| AUTH-01 | `AuthController`, `AuthService`, `NativeSessionManager`, auth tests, HTTP login smoke. |
| AUTH-02 | Logout route/session behavior, `SessionManagerTest`, `AuthServiceTest`. |
| USR-01 | `UserController`, `UserService`, user repositories, user tests. |
| PRD-01 | Product module, SKU constraint, product tests; upload out of mandatory implemented scope. |
| WH-01 | Warehouses and `product_stocks`, master data integration tests. |
| PO-01 | `PurchaseOrderService`, `StockService::receive()`, PO integration tests. |
| SO-01 | `SalesOrderService`, `StockService::issue()`, SO oversell integration test. |
| VIEW-01 | Server-rendered views under `views/`, HTTP smoke. |
| FIND-01 | Search criteria and repository search tests; Phase 8 product search bugfix integration test. |
| DASH-01 | `DashboardService`, operational query repository, dashboard tests and smoke. |
| REPORT-01 | `ReportService`, `CsvResponse`, CSV smoke. |
| API-01 | Product availability API tests and smoke. |
| VAL-01 | `InputValidator`, service validations, validation tests. |
| ERR-01 | `ErrorResponder`, error tests, CSRF 403 smoke. |
| UI-01 | `public/assets/css/app.css`, responsive evidence in Phase 6/7 docs. |
| DB-01 | `database/schema-and-seed.sql`, MySQL integration tests, clean seed query. |
| JOB-01 | `scripts/check-low-stock.php`, Docker CLI smoke. |
| ARCH-01 | Repository interfaces, in-memory repositories, unit tests. |
| ARCH-02 | ADR-002, ADR-003, stock integration tests. |
| DESIGN-01 | Initial and as-built diagrams. |
| DESIGN-02 | ADR-001, ADR-002, ADR-003. |
| DESIGN-03 | Refactor log, SRP audit, tech debt; Git evidence blocked by no Git repo. |
| DESIGN-04 | Critique document. |
| TEST-01 | Unit test result evidence. |
| TEST-02 | Integration test result evidence against MySQL. |
| TEST-03 | PHPStan report. |

## Defense Notes

- Architecture: explain manual composition root, controllers as HTTP layer, services as business/state layer, repositories as persistence boundary.
- Stock safety: explain row locks, deterministic movement ordering, post-lock validation, ledger append, source state callback, and rollback.
- Security: explain server-side role checks, CSRF on POST, session regeneration, safe errors, escaping, and placeholder-only env.
- Testing: explain why in-memory repositories support unit tests and MySQL integration tests prove real persistence/transactions.
- Tradeoffs: manual wiring is verbose but satisfies no-container rule; validation is partly centralized and partly service-local; Git evidence requires repository initialization.

## Safe Refactor Rehearsal

Example safe refactor to explain during defense:

1. Identify repeated primitive validation in a service.
2. Add or extend a focused unit test for the intended validation behavior.
3. Replace the repeated guard with `InputValidator`.
4. Run the targeted test, full `composer test`, and `composer analyse`.
5. Keep stock transaction behavior untouched unless the refactor explicitly targets `StockService`.

## Release Gate

Passed:

- Clean isolated Docker startup.
- Empty DB seed verification.
- Full/unit/integration tests.
- PHPStan level 5.
- Runtime smoke for auth, product list, PO/SO list, dashboard, report CSV, API.
- Low-stock command.
- Secret and forbidden technology audit.

Blocked:

- Git status, `refactor:` commit evidence, and final tag are unavailable because this workspace is not a Git repository.

## Current verification — 7 October 2026

The workspace is a Git repository; the older statement above about unavailable Git status is historical and no longer applies. Release commit/tag and human review are not claimed. Functional verification passes: 329 PHP tests, 50 JS tests, 199 HTTP checks, 303 browser page checks and 110 dialog checks. A fresh dependency build failed on DNS; matching-lock offline verification passed. Production operations/trainer decisions remain separate gates. See [current evidence](../testing/end-to-end-verification-2026-10-07.md).
