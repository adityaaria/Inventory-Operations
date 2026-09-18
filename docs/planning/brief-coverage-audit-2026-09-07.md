# Project Brief Coverage Audit

Date: 2026-09-07

Source: `Project Brief - Programmer.pdf`, `AGENTS.md`, `SDD.md`, current code, Docker runtime, PHPUnit/PHPStan, and HTTP smoke checks.

## Summary

Mandatory flows are implemented and verified: authentication, user management, master data screens, multi-warehouse stock, Purchase Orders, Sales Orders, stock ledger, search/filter/sort/pagination, role dashboards, CSV reports, JSON API, validation, safe errors, Docker, low-stock script, tests, static analysis, and architecture evidence.

The 2026-09-07 follow-up alignment slice closed the mandatory master-data gaps from section 1.3:

- `categories.description`
- product purchase price and selling price as separate product catalog fields
- `suppliers.address`
- `customers.address`

Product image upload is marked optional in the brief, so it is not counted as a mandatory gap in this audit.

## Mandatory Coverage Matrix

| Requirement | Status | Evidence / Gap |
|---|---|---|
| AUTH-01 Login and Session | Covered | `AuthController`, `AuthService`, `NativeSessionManager`; login smoke returned `302`; unauthenticated protected page redirected to `/login`. |
| AUTH-02 Logout | Covered | `/logout` route and session clear behavior covered by `SessionManagerTest`/`AuthServiceTest`. |
| USR-01 User Management | Covered | `UserController`, `UserService`, `UserRepositoryInterface`; Admin pages smoke-tested; Sales/Warehouse Staff get `403`. |
| PRD-01 Products, Categories, Reorder Point | Covered | Product/category CRUD, category description, SKU uniqueness, separate product purchase/selling prices, reorder validation, active status, stock display, search/filter are present. Optional image upload is not implemented because the brief marks it optional. |
| WH-01 Warehouse and Multi-Location Stock | Covered | `warehouses`, `product_stocks`, product stock details per warehouse; seed has 2 warehouses and low-stock examples. |
| PO-01 Purchase Order and Goods Receipt | Covered | `PurchaseOrderService`, `StockService::receive()`, `purchase_order_items.purchase_price`, receipt ledger, partial/full receipt tests. |
| SO-01 Sales Order, Approval, Goods Issue | Covered | `SalesOrderService`, server-side Sales approve rejection, Approved-only issue, insufficient-stock rejection, issue ledger, oversell integration test. |
| VIEW-01 Lists, Details, Empty States | Covered | Product, PO, SO, and CRUD views exist; Admin GET smoke and internal link crawl pass. |
| FIND-01 Search, Filter, Sort, Pagination | Covered | `ProductSearchCriteria`, `OrderSearchCriteria`, repository search tests; seed count query showed 30 products and 141 combined orders in current DB. |
| DASH-01 Role Dashboard | Covered | `DashboardService` returns Admin/Sales/Warehouse variants; role dashboard smoke passed. |
| REPORT-01 CSV Reports | Covered | `/reports/orders.csv` and `/reports/stock-ledger.csv` return CSV; `ReportServiceTest` covers CSV safety. |
| API-01 JSON Endpoint | Covered | `/api/products/{sku}/availability` returns JSON `200`, unauthenticated JSON `401`, missing SKU `404`. |
| VAL-01 Validation and Feedback | Covered | Backend validation and invalid POST smoke pass; product purchase/selling prices reject negative values and master-data fields are server-side handled. |
| ERR-01 Error Handling | Covered | unauthenticated redirect, role `403`, missing API `404`, safe error responder, CSRF `403` evidence. |
| UI-01 Responsive and Usability | Covered with evidence caveat | Custom CSS, labels, focus states, mobile notes, and page smoke exist. Screenshot artifacts are documented as not produced in the latest CLI-only audit. |
| DB-01 Relational DB and Transactions | Covered | MySQL schema, FK/check/indexes, PDO prepared statements, transactions, seed volume verified. Master data includes category description, supplier/customer address, and separate product purchase/selling prices. |
| JOB-01 Scheduled Script | Covered | `scripts/check-low-stock.php` ran in Docker and printed low-stock rows. |
| ARCH-01 Layered Architecture and Repository Interfaces | Covered | Controller -> Service -> Repository structure, contracts, MySQL and in-memory implementations, constructor injection. |
| ARCH-02 Transaction and Concurrency-Safe Stock | Covered | `StockService`, `FOR UPDATE`, transaction/rollback, receipt/issue ledger, oversell integration test. |
| DESIGN-01 Diagrams | Covered | `docs/architecture/class-diagram-initial.md`, `docs/architecture/class-diagram-as-built.md`. |
| DESIGN-02 ADRs | Covered | `docs/architecture/adr-001-layered-repository.md`, `adr-002-stock-concurrency.md`, `adr-003-stock-ledger-source-of-truth.md`. |
| DESIGN-03 Refactor/SRP/Tech Debt | Partial | Required docs exist. Git commit/tag evidence remains blocked because the workspace is not an active Git repository. |
| DESIGN-04 Critique | Covered | `docs/quality/critique.md` exists. |
| TEST-01 Unit Tests | Covered | `docker compose exec -T app composer test` passed 96 tests total; unit suite included in full run. |
| TEST-02 Integration Tests | Covered | MySQL integration tests included in full run; current DB queries verified seed volume. |
| TEST-03 Static Analysis | Covered | `docker compose exec -T app composer analyse` passed PHPStan level 5 with no errors. |

## Verification Commands

Fresh commands run during this audit:

- `docker compose ps`
- `docker compose exec -T db mysql ... SHOW COLUMNS ... seed counts`
- `docker compose exec -T app composer test`
- `docker compose exec -T app composer analyse`
- Authenticated and unauthenticated `curl` smoke checks for registered pages, report CSVs, and API responses on `localhost:8082`
- Sales and Warehouse Staff role-based `curl` smoke checks
- Forbidden technology scan across `composer.json`, `composer.lock`, `app`, `public`, `views`, `database`, and `scripts`
- Required evidence file scan
- `docker compose exec -T app php scripts/check-low-stock.php`

## Follow-Up Execution

Completed on 2026-09-07:

1. Added category description to schema, entity, repositories, services, views, seed, and tests.
2. Split product catalog price into purchase price and selling price, while keeping PO/SO item transaction prices intact.
3. Added supplier/customer address to schema, entities, repositories, services, views, seed, and tests.
4. Re-ran full PHPUnit, PHPStan, Docker HTTP smoke, and updated `docs/testing/test-results.md` plus `ai-usage-log.md`.
