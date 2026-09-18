# Test Results

Date: 2026-08-31  
Runtime: PHP 8.5.10 locally, PHP 8.3 CLI in Docker app image  
Database: MySQL 8 through Docker Compose

## Summary

| Evidence Area | Command | Result |
|---|---|---|
| Full PHPUnit suite | `composer test` | Pass: 86 tests, 333 assertions. |
| Phase 6 focused tests | `composer test -- --filter 'Validation|ErrorResponse|SecurityAudit|ViewEscaping'` | Pass: 9 tests, 136 assertions. |
| Static analysis | `composer analyse` | Pass: PHPStan level 5, 96 files, no errors. |
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | Pass: app image rebuilt and started with healthy DB dependency. |
| HTTP smoke | `curl` against `localhost:8082` with session cookie and CSRF token | Pass: valid login `302`, dashboard/report/API `200`, missing-CSRF login `403`. |
| Low-stock CLI | `APP_PORT=8082 docker compose exec -T app php scripts/check-low-stock.php` | Pass: printed seeded low-stock rows. |

## Unit Test Coverage Examples

| Area | Tests | Purpose |
|---|---|---|
| Auth/session/security | `AuthServiceTest`, `AuthorizationTest`, `SessionManagerTest`, `SecurityAuditTest` | Login rules, authorization boundaries, session state, CSRF and repository-superglobal audit. |
| Master data | `ProductServiceTest`, `ProductRepositoryTest`, `MasterDataAuthorizationTest`, `ProductSearchTest` | Product validation, repository behavior, role policy, stock display/search. |
| PO/SO workflows | `PurchaseOrderServiceTest`, `SalesOrderServiceTest`, `StockServiceReceiptTest`, `StockServiceIssueTest` | State transitions, server-side role rules, receipt/issue stock mutation behavior. |
| Phase 5 operations | `DashboardServiceTest`, `ReportServiceTest`, `ProductAvailabilityApiTest`, `LowStockPolicyTest`, `SearchCriteriaTest` | Dashboard scope, CSV safety, JSON API, low-stock policy, search/pagination normalization. |
| Phase 6 hardening | `ValidationTest`, `ErrorResponseTest`, `ViewEscapingTest` | Shared validators, safe HTML/API errors, escaping helper. |

## Integration Test Coverage Examples

| Test | Persistence / Transaction Evidence |
|---|---|
| `UserRepositoryIntegrationTest` | MySQL user lookup/create/update against real schema. |
| `MasterDataRepositoryIntegrationTest` | SKU/warehouse stock queries and non-negative stock DB constraint. |
| `PurchaseOrderSchemaIntegrationTest` | PO, PO item, and stock ledger tables/constraints exist. |
| `PurchaseOrderReceiptIntegrationTest` | Full/partial receipt updates stock/order state and forced ledger failure rolls back. |
| `SalesOrderIssueIntegrationTest` | Oversell attempt fails and leaves stock non-negative with no failed ledger row. |
| `DemoSeedIntegrationTest` | Seed data volume and statuses satisfy assessment demo needs. |

## Environment Notes

- The default host port `8080` is occupied by local `httpd`; runtime smoke tests use `APP_PORT=8082`.
- `composer test` and `composer analyse` require elevated local permissions in this sandbox because MySQL/PHPStan use local network sockets.
- Git history checks cannot run because the workspace is not a Git repository.

## Phase 8 Release Gate

Date: 2026-08-31

| Evidence Area | Command | Result |
|---|---|---|
| Clean isolated Docker startup | `COMPOSE_PROJECT_NAME=rudis_phase8 APP_PORT=8083 DB_HOST_PORT=3307 docker compose up -d --build app` | Pass: image rebuilt, clean project DB volume created, MySQL healthy, app started. |
| Empty DB seed counts | `docker compose exec -T db mysql ... SELECT ...` | Pass: 1 Admin, 2 Sales, 2 Warehouse Staff, 2 warehouses, 30 products, 13 POs, 12 SOs, 7 low-stock rows. |
| Clean HTTP runtime smoke | `curl` with cookie and CSRF token against `localhost:8083` | Pass: login `302`; home, products, PO, SO, dashboard, report CSV, and API returned `200`. |
| Full tests on clean DB | `DB_PORT=3307 composer test` | Pass: 87 tests, 336 assertions. |
| Unit tests | `DB_PORT=3307 composer test:unit` | Pass: 75 tests, 285 assertions. |
| Integration tests on clean DB | `DB_PORT=3307 composer test:integration` | Pass: 12 tests, 51 assertions. |
| Static analysis | `composer analyse` | Pass: PHPStan level 5, 96 files, no errors. |
| Git release checks | `git log --oneline --grep='^refactor:'` / `git rev-parse --show-toplevel` | Blocked: current workspace is not a Git repository. |

Release bugfixes found during Phase 8:

- `MySqlProductRepository::search()` reused a named placeholder twice with native MySQL prepares, causing `SQLSTATE[HY093]` for `/products?q=Demo`. Added integration coverage and split placeholders into `:term_name` and `:term_sku`.
- Docker's PHP built-in server bypassed the front controller for dotted routes such as `/reports/orders.csv`. The Docker `CMD` now passes `public/index.php` as the router script.
- `compose.yaml` now supports `DB_HOST_PORT` so clean isolated verification can run while another MySQL container owns host port `3306`.

## Operational UI Enhancement

Date: 2026-09-01

| Evidence Area | Command | Result |
|---|---|---|
| PHP version | `php -v` | PHP 8.5.10 after relinking Homebrew PHP. |
| View/front-controller syntax | `for f in views/**/*.php public/*.php; do php -l "$f" >/dev/null || exit 1; done` | Pass. |
| Focused UI-adjacent tests | `composer test -- --filter 'DashboardServiceTest|ProductControllerTest|PurchaseOrderControllerTest|SalesOrderControllerTest|ProductSearch|SecurityAuditTest'` | Pass: 17 tests, 159 assertions. |
| Full regression tests | `composer test` | Pass: 87 tests, 336 assertions. |
| Static analysis | `composer analyse` | Pass: PHPStan level 5, 96 files, no errors. |
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | Pass: app rebuilt and started on `localhost:8082`. |
| HTTP smoke | `curl` with Admin session and CSRF token | Pass: login `302`; dashboard/products/PO/SO/reports/orders CSV/API returned `200`; UI class markers appeared in HTML. |

Modern SaaS Clean refinement, 2026-09-01:

| Evidence Area | Command | Result |
|---|---|---|
| PHP syntax | `find app public views -name '*.php' -print0 \| xargs -0 -n1 php -l` | Pass: all checked PHP files reported no syntax errors. |
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | Pass: app rebuilt and started on `localhost:8082`. |
| HTTP smoke | `curl` with Admin session and CSRF token | Pass: login `302`; `/assets/css/app.css` returned `200 text/css`; `/users` and `/` returned `200` with modern UI markers. |
| Full regression tests | `composer test` | Pass: 87 tests, 336 assertions. |
| Static analysis | `composer analyse` | Pass: PHPStan level 5, 97 files, no errors. |

SaaS font-weight refinement, 2026-09-01:

| Evidence Area | Command | Result |
|---|---|---|
| CSS weight scan | `rg -n "font-weight: (700\|800\|bold)" public/assets/css/app.css` | Pass: no heavy `700`, `800`, or `bold` weights remain in the stylesheet. |
| PHP syntax | `find app public views -name '*.php' -print0 \| xargs -0 -n1 php -l` | Pass: all checked PHP files reported no syntax errors. |
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | Pass: app rebuilt and started on `localhost:8082`. |
| HTTP smoke | `curl` login/products/CSS | Pass: login `302`; products `200`; CSS `200 text/css`; no heavy font weights in served CSS; SaaS font markers present. |
| Full regression tests | `composer test` | Pass: 87 tests, 336 assertions. |
| Static analysis | `composer analyse` | Pass: PHPStan level 5, 97 files, no errors. |

Login/font/pagination refinement, 2026-09-01:

| Evidence Area | Command | Result |
|---|---|---|
| Root cause check | Inspect `views/auth/login.php`, `public/assets/js/app.js`, and pagination list views | Login was still wrapped by JS app shell; list pagination used plain text. |
| PHP syntax | `find app public views -name '*.php' -print0 \| xargs -0 -n1 php -l` | Pass: all checked PHP files reported no syntax errors. |
| JavaScript syntax | `node --check public/assets/js/app.js` | Pass. |
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | Pass: app rebuilt and started on `localhost:8082`. |
| HTTP smoke | `curl` login/products/PO/SO/CSS/JS | Pass: login `302`; list pages returned `200`; auth-body, shell-skip, SaaS font, and pagination markers were present. |
| Full regression tests | `composer test` | Pass: 87 tests, 336 assertions. |
| Static analysis | `composer analyse` | Pass: PHPStan level 5, 97 files, no errors. |

Manual visual review notes:

- Desktop: pages now use a consistent operational shell, page headers, toolbar actions, table styling, metric cards, report panels, and a cleaner SaaS visual system with modern typography, surfaces, shadows, and controls.
- 360px/narrow: CSS stacks headers, nav, filters, forms, metric cards, and report panels; wide data tables remain horizontally scrollable inside the page surface.
- No screenshot artifact was produced in this session because no browser screenshot tool was available.

## UI Shell, Dashboard, and Datatable Enhancement

Date: 2026-09-01

| Evidence Area | Command | Result |
|---|---|---|
| PHP syntax | `find app public views -name '*.php' -print0 \| xargs -0 -n1 php -l` | Pass: all checked PHP files reported no syntax errors. |
| JavaScript syntax | `node --check public/assets/js/app.js` | Pass. |
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | Pass: app rebuilt and started on `localhost:8082`. |
| HTTP smoke | `curl` with Admin session and CSRF token | Pass: login `302`; CSS `200 text/css`; JS `200 application/javascript`; dashboard/products/users returned `200`; sidebar, chart, modal, export, table, and loading markers appeared in served assets/pages. |
| Full regression tests | `composer test` | Pass: 87 tests, 336 assertions. |
| Static analysis | `composer analyse` | Pass: PHPStan level 5, 97 files, no errors. |

Browser automation note:

- Playwright import through the available node REPL failed with `The requested module './index.js' does not provide an export named 'default'`, so visual verification used HTTP smoke plus CLI syntax/test/static analysis evidence.

Hidden dialog bugfix, 2026-09-01:

| Evidence Area | Command | Result |
|---|---|---|
| Root cause check | Inspect `public/assets/css/app.css` and `public/assets/js/app.js` | Confirm dialog used `hidden`, but CSS display rules for `.confirm-backdrop` overrode default hidden behavior. |
| PHP syntax | `find app public views -name '*.php' -print0 \| xargs -0 -n1 php -l` | Pass: all checked PHP files reported no syntax errors. |
| JavaScript syntax | `node --check public/assets/js/app.js` | Pass. |
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | Pass: app rebuilt and started on `localhost:8082`. |
| HTTP smoke | `curl` login/CSS/JS/favicon/dashboard | Pass: login `302`; CSS `200 text/css` includes `[hidden]`; JS `200 application/javascript`; favicon `200 image/svg+xml`; dashboard returned `200`. |
| Full regression tests | `composer test` | Pass: 87 tests, 336 assertions. |
| Static analysis | `composer analyse` | Pass: PHPStan level 5, 97 files, no errors. |

## Enterprise Security Hardening

Date: 2026-09-01

| Evidence Area | Command | Result |
|---|---|---|
| Audit/rate-limit/logging focused tests | `composer test -- --filter 'AuditLoggerTest\|LoginRateLimiterTest\|JsonFileLoggerTest\|RequestAuditRecorderTest\|AuthServiceTest\|SecurityAuditRepositoryIntegrationTest'` | Pass: 12 tests, 48 assertions. |
| PHP syntax | `find app public tests -name '*.php' -print0 \| xargs -0 -n1 php -l` | Pass: all checked files reported no syntax errors. |
| Existing Docker DB schema update | DDL-only `docker compose exec -T db mysql ... CREATE TABLE IF NOT EXISTS audit_logs/login_attempts ...` | Pass: security tables were applied without replaying the full seed script. |
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | Pass: app container rebuilt and started on `localhost:8082`; DB container healthy. |
| Runtime rate-limit smoke | `curl` login form + 6 failed login attempts for `audit-smoke@example.test`, then MySQL aggregate query | Pass: first 5 attempts returned `200` with generic failure, attempt 6 returned `200` while audit showed `auth.login_failed` x5 and `auth.login_blocked` x1. |
| Full regression tests | `composer test` | Pass: 96 tests, 394 assertions. First sandboxed run failed with MySQL `Operation not permitted`; approved rerun passed. |
| Static analysis | `composer analyse` | Pass: PHPStan level 5, 107 files, no errors. |

Notes:

- A broader cleanup deleting old Admin audit history was rejected by the approvals reviewer, so runtime smoke cleanup was restricted to a unique smoke-test email.
- The MySQL CLI warned about password usage on the command line; credentials are the local Docker defaults from `compose.yaml`.

## Full Page Functionality Audit

Date: 2026-09-07

| Evidence Area | Command | Result |
|---|---|---|
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | Pass: app image rebuilt, DB became healthy, app started with `0.0.0.0:8082->8080/tcp`. |
| Full regression tests | `docker compose exec -T app composer test` | Pass: 96 tests, 394 assertions. |
| Static analysis | `docker compose exec -T app composer analyse` | Pass: PHPStan level 5, 107 files, no errors. |
| PHP syntax | `docker compose exec -T app sh -lc "find app public views -name '*.php' -print0 \| xargs -0 -n1 php -l"` | Pass: all checked PHP files reported no syntax errors. |
| JavaScript syntax | `node --check public/assets/js/app.js` | Pass. |
| Public assets | `curl` for `/assets/css/app.css`, `/assets/js/app.js`, and `/favicon.svg` | Pass: CSS `200 text/css`, JS `200 application/javascript`, favicon `200 image/svg+xml`. |
| Admin GET smoke | `curl` with Admin session and CSRF token | Pass: `/`, dashboard, reports, report CSVs, product API, users, categories, warehouses, products, suppliers, customers, purchase orders, sales orders, create pages, edit pages, and show pages returned expected statuses. |
| Internal link crawl | `curl` discovered internal `href` and non-mutating `action` targets from Admin pages | Pass: all discovered internal GET links returned `200`; `/does-not-exist` returned expected `404`. |
| Role-based smoke | `curl` with Sales and Warehouse Staff sessions | Pass: Sales can access SO pages/API and receives expected `403` for user management and PO create; Warehouse Staff can access PO pages/SO list/API and receives expected `403` for user management and SO create. |
| Create form validation | `curl` invalid POST payloads with valid CSRF to `/users`, `/categories`, `/warehouses`, `/products`, `/suppliers`, `/customers`, `/purchase-orders`, `/sales-orders` | Pass: all returned handled `422` validation responses; no page crash. |
| Edit form validation | `curl` invalid POST payloads with valid CSRF to update routes for users, categories, warehouses, products, suppliers, and customers | Pass: all returned handled `422` validation responses; no page crash. |
| Low-stock CLI | `docker compose exec -T app php scripts/check-low-stock.php` | Pass: printed seeded low-stock product/warehouse rows. |

Findings:

- No runtime page defect was found during this audit, so no production code changes were required.
- Local host PHP is not available in PATH; PHP verification was run inside the Docker app container.
- The workspace still is not an active Git repository, so Git diff/commit evidence remains unavailable here.

## Brief Master-Data Alignment

Date: 2026-09-07

| Evidence Area | Command | Result |
|---|---|---|
| Docker rebuild | `APP_PORT=8082 docker compose up -d --build` | Pass: app image rebuilt and started on `localhost:8082`; first default-port attempt failed because local port `8080` was occupied. |
| Existing Docker DB schema update | `docker compose exec -T db mysql ... ALTER TABLE ...` | Pass: added `categories.description`, `products.purchase_price`, `products.selling_price`, `suppliers.address`, `customers.address`, and product price non-negative constraints to the existing local DB. |
| Schema replay | `docker compose exec -T db mysql ... < database/schema-and-seed.sql` | Pass: updated schema/seed file executed successfully against the existing DB. |
| Focused regression tests | `docker compose exec -T app vendor/bin/phpunit --filter 'Product(Service\|Controller\|Search\|Repository)Test\|MasterDataRepositoryIntegrationTest'` | Pass: 16 tests, 54 assertions. Pre-migration run failed as expected because the existing DB did not yet have the new master-data columns. |
| Full regression tests | `docker compose exec -T app composer test` | Pass: 97 tests, 406 assertions before and after adding runtime product price constraints. |
| Static analysis | `docker compose exec -T app composer analyse` | Pass: PHPStan level 5, 107 files, no errors. PHPStan emitted an advisory that the installed PHPStan 1.12 series is old. |
| HTTP smoke | `curl` with Admin session and CSRF token | Pass: `/products`, `/products/create`, `/categories`, `/categories/create`, `/suppliers`, `/suppliers/create`, `/customers`, and `/customers/create` returned `200`. |
| Field marker smoke | `curl` served form HTML + `rg` marker scan | Pass: product create form includes `purchase_price` and `selling_price`; category create form includes `description`; supplier/customer create forms include `address`. |

Notes:

- Product catalog now stores purchase and selling prices separately. The legacy `products.price` column remains as a compatibility mirror of selling price.
- Dashboard inventory valuation now uses product purchase price.
- Product image upload remains outside mandatory scope because the official brief marks it optional.

## Default Dashboard Route and Auth Gate

Date: 2026-09-07

| Evidence Area | Command | Result |
|---|---|---|
| Docker rebuild | `APP_PORT=8082 docker compose up -d --build` | Pass: app image rebuilt and restarted on `localhost:8082`. |
| Focused dashboard tests | `docker compose exec -T app vendor/bin/phpunit --filter DashboardServiceTest` | Pass: 4 tests, 9 assertions. |
| Full regression tests | `docker compose exec -T app composer test` | Pass: 99 tests, 410 assertions. |
| Static analysis | `docker compose exec -T app composer analyse` | Pass: PHPStan level 5, 107 files, no errors. PHPStan emitted an advisory that the installed PHPStan 1.12 series is old. |
| Root auth smoke | `curl http://127.0.0.1:8082/` without session | Pass: returned `302` redirect to `/login`. |
| Root dashboard smoke | `curl` after Admin login | Pass: login returned `302`; `/` returned `200` and served Dashboard content. |

Notes:

- `/` now dispatches to `DashboardController::index`, so dashboard is the default authenticated page.
- Unauthenticated browser access to `/` follows the existing 401-to-`/login` redirect behavior.

## CRUD, Export, and Import Audit

Date: 2026-09-07

| Evidence Area | Command | Result |
|---|---|---|
| Docker rebuild | `APP_PORT=8082 docker compose up -d --build` | Pass: app image rebuilt and restarted on `localhost:8082`. |
| Import TDD red check | `docker compose exec -T app vendor/bin/phpunit --filter 'CsvImportTest\|ProductControllerTest'` after adding tests | Expected fail before implementation: missing `App\Support\CsvImport` and `ProductController::import()`. |
| User import TDD red check | `docker compose exec -T app vendor/bin/phpunit --filter UserControllerTest` after adding test | Expected fail before implementation: missing `UserController::import()`. |
| Focused import tests | `docker compose exec -T app vendor/bin/phpunit --filter 'CsvImportTest\|ProductControllerTest\|UserControllerTest'` | Pass: 7 tests, 13 assertions. |
| Full regression tests | `docker compose exec -T app composer test` | Pass: 103 tests, 423 assertions. |
| Static analysis | `docker compose exec -T app composer analyse` | Pass: PHPStan level 5, 108 files, no errors. One earlier parallel run exited 137 during rebuild overlap and was rerun successfully. |
| CRUD runtime smoke | `curl` Admin session against create/update/deactivate/activate POST flows | Pass: Users, Categories, Warehouses, Products, Suppliers, and Customers all returned expected `302` redirects and persisted IDs. |
| Import runtime smoke | `curl` Admin session against `/users/import`, `/categories/import`, `/warehouses/import`, `/products/import`, `/suppliers/import`, and `/customers/import` | Pass: all returned `302`; DB checks returned `1` imported row for each target. |
| Server CSV export smoke | `curl` Admin session against `/reports/orders.csv` and `/reports/stock-ledger.csv` | Pass: both returned `200 text/csv; charset=UTF-8`. |
| Table CSV export marker | `curl /assets/js/app.js` + marker scan | Pass: client-side table export code is present via `Export CSV` / `exportTable` markers. |

Notes:

- CSV import accepts pasted `csv_data` or a `csv_file` upload up to 1 MB.
- Import is Admin-only and goes through existing service validation; it does not bypass authorization or stock-service invariants.
- Transactional PO/SO stock movement was not altered by CRUD/import work.

## Frontend Modularization and HTTP Smoke

Date: 2026-09-10

| Evidence Area | Command | Result |
|---|---|---|
| Docker runtime | `APP_PORT=8081 docker compose up -d app` | Pass: app served on `localhost:8081`; MySQL healthy. |
| Full PHPUnit suite | `docker compose run --rm app composer test` | Pass: 103 tests, 423 assertions. |
| Static analysis | `docker compose run --rm app composer analyse` | Pass: PHPStan level 5, 108 files, no errors. |
| JavaScript behavior tests | `node --test tests/JavaScript/*.test.js` | Pass: 13 tests, 0 failures. |
| JavaScript syntax | `node --check public/assets/js/*.js` | Pass: all modules parse successfully. |
| Unauthenticated routes | `curl` `/login`, `/dashboard`, `/products`, and API availability | Pass: login `200`, browser routes `302`, API `401 JSON`. |
| Authenticated pages | `curl` Admin session `/dashboard`, `/products`, `/purchase-orders`, `/sales-orders` | Pass: all returned `200`. |
| API and CSV | `curl` existing product availability and orders CSV | Pass: API `200 application/json`; CSV `200 text/csv`. |
| Module loading | script include scan across views and `HomeController` | Pass: all entry points load required JavaScript modules. |

Notes:

- Default host port `8080` was occupied, so the runtime smoke used `APP_PORT=8081`.
- Login redirect smoke returned an intermediate `403` when curl followed a POST redirect; a cookie-consistent session subsequently authenticated successfully and dashboard/products returned `200`.
- Logout was not asserted because the served dashboard did not expose a logout form/CSRF field for the curl probe.

## Rebuilt Asset and Role Authorization Smoke

Date: 2026-09-10

| Evidence Area | Result |
|---|---|
| Rebuilt Docker app image | Pass: all nine JavaScript assets returned `200 application/javascript`. |
| Admin session | Dashboard and users returned `200`. |
| Sales session | Dashboard and sales orders returned `200`; users and PO create returned expected `403`. |
| Warehouse session | Purchase orders returned `200`; SO create returned expected `403`. |
| Authenticated API | Existing SKU availability returned `200 application/json`. |
| Invalid CSRF | Login POST returned expected `403`. |

The first post-rebuild probe used stale PHP session cookies and returned redirects; the
role results above were rerun with fresh cookies after the image restart.
