# Test Scenarios

## Phase 0

| Scenario | Command | Expected Result |
|---|---|---|
| Composer autoload smoke | `composer test -- --filter BootstrapSmokeTest` | PHPUnit passes config/autoload checks. |
| Router unit behavior | `composer test -- --filter RouterTest` | Known route returns 200; missing route raises 404. |
| Static analysis | `composer analyse` | PHPStan level 5 completes without errors. |
| Docker boot | `docker compose up --build` | App and MySQL services start. |
| App HTTP response | `curl -i http://localhost:8080/` | HTTP 200 with Phase 0 bootstrap message. |

## Phase 0 Verification Results

| Command | Result | Notes |
|---|---|---|
| `php -v` | Pass | Host PHP is now `PHP 8.5.10`, satisfying the PHP 8.2+ requirement. |
| `composer --version` | Pass | Composer `2.10.3` is installed and using PHP 8.5.10. |
| `composer install` | Pass | Development dependencies installed locally; `composer.lock` generated. |
| `composer validate --strict` | Pass | `composer.json` is valid after adding the project license metadata. |
| `composer test` | Pass | PHPUnit 10.5.64 passed 5 tests and 10 assertions. |
| `composer analyse` | Pass | PHPStan completed with no errors; output recorded in `docs/quality/phpstan-report.txt`. |
| `docker compose config` | Pass | Compose configuration rendered services `app` and `db`. |
| `ruby -rjson -e 'JSON.parse(File.read("composer.json"))'` | Pass | `composer.json` is valid JSON. |
| `docker info` | Pass after Docker Desktop start | Docker daemon became reachable after opening Docker Desktop. |
| `php -S 127.0.0.1:8081 -t public` + `curl -i http://127.0.0.1:8081/` | Pass | Local PHP server returned HTTP 200 with `Phase 0 bootstrap is running.` |
| `docker compose build app` | Pass after Docker Desktop restart and image repair | Initial BuildKit/containerd metadata errors were resolved by restarting Docker Desktop. A corrupt local `php:8.2-cli` image had empty PHP binaries, so the Docker base was moved to `php:8.3-cli`, still satisfying PHP 8.2+. |
| `docker compose build --no-cache app` | Pass | Dockerfile now installs `unzip` and copies `composer.lock` before `composer install`, making dependency installation deterministic. |
| `APP_PORT=8082 docker compose up -d app` | Pass | MySQL container is healthy and app container is running with host port `8082` mapped to container port `8080`. |
| `docker compose exec -T app php -v` | Pass | App container runs PHP 8.3.33. |
| `docker compose exec -T app composer test` | Pass | Container PHPUnit passed 5 tests and 10 assertions. |
| `curl -i http://localhost:8082/` | Pass | Docker-hosted app returned HTTP 200 with `Phase 0 bootstrap is running.` |
| `docker compose up --build` with default port `8080` | Blocked locally | Host port `8080` is already used by `httpd`; use `APP_PORT=8082 docker compose up -d app` or free port `8080`. |

## Phase 1

| Scenario | Command | Expected Result |
|---|---|---|
| User entity behavior | `composer test:unit -- --filter UserEntityTest` | User exposes identity/role/active state and rejects unsupported roles. |
| In-memory user repository | `composer test:unit -- --filter UserRepositoryTest` | Fake repository supports lookup, create, update, and active-state changes. |
| Session handling | `composer test:unit -- --filter SessionManagerTest` | Auth context can be stored and cleared without PHP globals. |
| Auth service | `composer test:unit -- --filter AuthServiceTest` | Active user with valid password logs in; invalid password/inactive user is rejected; logout clears session. |
| Authorization and user service | `composer test:unit -- --filter 'AuthorizationTest|UserServiceTest'` | Admin can manage users; non-Admin cannot. |
| MySQL user repository | `composer test:integration -- --filter UserRepositoryIntegrationTest` | Seeded Admin can be read from MySQL; repository create/update/deactivate works against real MySQL. |
| HTTP login page | `curl -i http://localhost:8082/login` | Returns HTTP 200 login form. |
| HTTP auth guard | `curl -i http://localhost:8082/users` | Unauthenticated request redirects to `/login`. |
| HTTP admin login | `curl -i -c /tmp/tugasakhir-cookies.txt -b /tmp/tugasakhir-cookies.txt -d 'email=admin@example.test&password=password' -X POST http://localhost:8082/login` | Valid demo Admin login redirects to `/users`. |
| HTTP user list | `curl -i -b /tmp/tugasakhir-cookies.txt http://localhost:8082/users` | Authenticated Admin sees seeded users. |

## Phase 1 Verification Results

| Command | Result | Notes |
|---|---|---|
| `composer test:unit` | Pass | 16 unit tests and 44 assertions passed after Phase 1 unit implementation. |
| `docker compose up -d db` | Pass | MySQL 8 container started and reported healthy. |
| `docker compose exec -T db mysql ... < database/schema-and-seed.sql` | Pass | Phase 1 schema and demo users applied to the running MySQL container. |
| `composer test:integration -- --filter UserRepositoryIntegrationTest` | Pass | 2 MySQL-backed repository tests and 8 assertions passed. |
| `composer test` | Pass | Full suite passed: 19 tests and 53 assertions. |
| `composer analyse` | Pass | PHPStan scanned 23 files and reported no errors. |
| `APP_PORT=8082 docker compose up -d --build app` | Pass | App container rebuilt with Phase 1 source and started with MySQL dependency healthy. |
| `curl -i http://localhost:8082/login` | Pass | Login page returned HTTP 200. |
| `curl -i http://localhost:8082/users` | Pass | Unauthenticated user list request returned HTTP 302 to `/login`. |
| `curl -i ... POST http://localhost:8082/login` | Pass | Demo Admin `admin@example.test` / `password` returned HTTP 302 to `/users`. |
| `curl -i -b /tmp/tugasakhir-cookies.txt http://localhost:8082/users` | Pass | Authenticated Admin user list returned HTTP 200 with seeded users. |

## Phase 2

| Scenario | Command | Expected Result |
|---|---|---|
| Product entity/search behavior | `composer test -- --filter ProductSearchTest` | Product fields, low-stock calculation, and search criteria defaults work. |
| Product repository behavior | `composer test -- --filter ProductRepositoryTest` | Fake repository rejects duplicate SKU and paginates products. |
| Master data authorization | `composer test -- --filter MasterDataAuthorizationTest` | Admin can write master data; Sales and Warehouse Staff cannot. |
| Product service validation | `composer test -- --filter ProductServiceTest` | Product service enforces Admin-only writes and required product fields. |
| Product controller routing | `composer test -- --filter ProductControllerTest` | Product list renders and Admin-only create route is guarded. |
| Master data MySQL schema | `composer test:integration -- --filter MasterDataRepositoryIntegrationTest` | SKU uniqueness, active warehouses, seeded multi-warehouse stock, and non-negative stock constraints are verified against MySQL. |
| Product HTTP screen | `curl -b <cookie> http://localhost:8082/products` | Authenticated Admin sees `SKU-DEMO-001` with different quantities in Main and Secondary warehouses. |
| Other master data HTTP screens | `curl -b <cookie> http://localhost:8082/{categories,warehouses,suppliers,customers}` | Authenticated Admin sees each master data index page. |

## Phase 2 Verification Results

| Command | Result | Notes |
|---|---|---|
| `composer test -- --filter 'Product|MasterData|Category|Warehouse|Supplier|Customer'` | Pass | 16 tests and 42 assertions passed with MySQL access enabled. |
| `APP_PORT=8082 docker compose up -d --build app` | Pass | App image rebuilt from Phase 2 source; MySQL dependency was healthy and app container started. |
| `docker compose exec -T db mysql ... < database/schema-and-seed.sql` | Pass | Phase 2 schema and seed data were applied to the running MySQL container. |
| `composer test` | Pass | Full PHPUnit suite passed: 35 tests and 95 assertions. |
| `composer analyse -- --no-progress` | Pass | PHPStan scanned 54 files and reported no errors; report refreshed in `docs/quality/phpstan-report.txt`. |
| Authenticated `curl` smoke test for `/products`, `/categories`, `/warehouses`, `/suppliers`, `/customers` | Pass | Admin login succeeded; product page contained `SKU-DEMO-001`, `Main Warehouse: 25`, and `Secondary Warehouse: 4`; all other master data index pages rendered. |

## Phase 3

| Scenario | Command | Expected Result |
|---|---|---|
| PO repository state | `composer test -- --filter PurchaseOrderRepositoryTest` | Draft creation, Ordered transition, duplicate product-line rejection, and received quantity persistence work in fake repository. |
| Receipt stock transaction service | `composer test -- --filter StockServiceReceiptTest` | Receipt validates positive quantities, locks deterministic product order, increments stock, writes `Receipt` ledger, and rolls back on ledger failure. |
| PO service receipt rules | `composer test -- --filter PurchaseOrderServiceTest` | Partial/full receipt status, over-receipt rejection, invalid-status rejection, and Sales forbidden behavior are enforced. |
| PO controller smoke | `composer test -- --filter PurchaseOrderControllerTest` | Purchase order list renders and Sales cannot open create form. |
| PO schema | `composer test -- --filter PurchaseOrderSchemaIntegrationTest` | `purchase_orders`, `purchase_order_items`, and `stock_ledger` tables and key indexes exist. |
| PO receipt integration | `composer test -- --filter PurchaseOrderReceiptIntegrationTest` | Full receipt increments stock and writes ledger, partial receipt updates status/remaining, forced ledger failure rolls back stock and item state. |
| PO HTTP workflow | Admin `curl` session posts create/order/receive routes | Created PO reaches `Received`; one `Receipt` ledger row exists for the PO reference. |

## Phase 3 Verification Results

| Command | Result | Notes |
|---|---|---|
| `composer test -- --filter 'PurchaseOrderRepositoryTest|StockServiceReceiptTest|PurchaseOrderServiceTest|PurchaseOrderControllerTest'` | Pass | 14 tests and 24 assertions passed. |
| `docker compose exec -T db mysql ... < database/schema-and-seed.sql` | Pass | Phase 3 schema with PO and stock ledger tables applied to MySQL. |
| `composer test -- --filter 'PurchaseOrderSchemaIntegrationTest|PurchaseOrderReceiptIntegrationTest'` | Pass | 4 MySQL-backed tests and 18 assertions passed. |
| `composer analyse -- --no-progress` | Pass | PHPStan reported no errors after Phase 3 implementation; report refreshed in `docs/quality/phpstan-report.txt`. |
| `composer test` | Pass | Full PHPUnit suite passed: 53 tests and 137 assertions. |
| `APP_PORT=8082 docker compose up -d --build app` | Pass | Docker app rebuilt and restarted on port `8082`. |
| Admin HTTP smoke workflow | Pass | `PO-SMOKE-20260831165449` was created, ordered, fully received, ended with status `Received`, and had one `Receipt` ledger row. |

## Phase 4

| Scenario | Command | Expected Result |
|---|---|---|
| Sales order entity behavior | `composer test -- --filter SalesOrderEntityTest` | SO exposes fixed statuses, ownership user id, and item quantity/price fields. |
| Sales order repository behavior | `composer test -- --filter SalesOrderRepositoryTest` | Fake repository creates Draft, submits, approves, cancels, fulfills, and rejects duplicate item lines. |
| Stock issue service | `composer test -- --filter StockServiceIssueTest` | Issue locks product rows deterministically, decrements stock, writes `Issue` ledger, and rejects insufficient stock. |
| Sales order service rules | `composer test -- --filter SalesOrderServiceTest` | Sales cannot approve, Sales can manage only own orders, Draft cannot issue, Approved can issue to Fulfilled, and Fulfilled cannot be resubmitted. |
| Sales order controller smoke | `composer test -- --filter SalesOrderControllerTest` | Sales list shows only own orders; approval route rejects Sales server-side. |
| Oversell integration | `composer test -- --filter SalesOrderIssueIntegrationTest` | First issue consumes available stock; second issue fails; final stock remains non-negative and failed SO gets no ledger row. |
| SO HTTP workflow | Admin `curl` session posts create/submit/approve/issue routes | Created SO reaches `Fulfilled`; one `Issue` ledger row exists and stock is decremented. |

## Phase 4 Verification Results

| Command | Result | Notes |
|---|---|---|
| `composer test -- --filter 'SalesOrderEntityTest|SalesOrderRepositoryTest|StockServiceIssueTest|SalesOrderServiceTest|SalesOrderControllerTest'` | Pass | 12 tests and 24 assertions passed. |
| `docker compose exec -T db mysql ... < database/schema-and-seed.sql` | Pass | Phase 4 schema with SO tables and `Issue` ledger movement applied to MySQL. |
| `composer test -- --filter SalesOrderIssueIntegrationTest` | Pass | 1 MySQL-backed oversell test and 4 assertions passed. |
| `composer analyse -- --no-progress` | Pass | PHPStan reported no errors after Phase 4 implementation; report refreshed in `docs/quality/phpstan-report.txt`. |
| `composer test` | Pass | Full PHPUnit suite passed: 66 tests and 165 assertions. |
| `APP_PORT=8082 docker compose up -d --build app` | Pass | Docker app rebuilt and restarted on port `8082`. |
| Admin HTTP smoke workflow | Pass | `SO-SMOKE-20260831212948` was created, submitted, approved, issued, ended with status `Fulfilled`, had one `Issue` ledger row, and stock moved from 5 to 3. |

## Phase 5

| Scenario | Command | Expected Result |
|---|---|---|
| Search criteria | `composer test -- --filter SearchCriteriaTest` | Product/order criteria normalize positive pages, max 10 page size, allow-listed sort keys, valid direction, and query trimming. |
| Dashboard role scope | `composer test -- --filter DashboardServiceTest` | Admin dashboard includes global inventory/order metrics; Sales dashboard is scoped to own user id. |
| CSV reports | `composer test -- --filter ReportServiceTest` | Report CSV validates date range, escapes CSV fields, and neutralizes formula-leading values. |
| Product availability API | `composer test -- --filter ProductAvailabilityApiTest` | Authenticated SKU returns JSON 200 shape; unauthenticated returns JSON 401; missing SKU returns JSON 404. |
| Low-stock policy | `composer test -- --filter LowStockPolicyTest` | Low stock is detected per product/warehouse where quantity is below reorder point. |
| Demo seed volume | `composer test -- --filter DemoSeedIntegrationTest` | Seed has at least 30 products, at least 25 combined PO/SO records, PendingApproval example, and Cancelled example. |
| Low-stock CLI | `docker compose exec -T app php scripts/check-low-stock.php` | Command prints low-stock rows from real DB data. |
| Dashboard/report/API HTTP smoke | Admin `curl` session checks `/dashboard`, report CSV routes, and `/api/products/SKU-DEMO-001/availability` | Authenticated responses render/return expected data; unauthenticated API returns JSON 401. |

## Phase 5 Verification Results

| Command | Result | Notes |
|---|---|---|
| `composer test -- --filter 'SearchCriteria|Dashboard|Report|ProductAvailability|LowStock|PurchaseOrderController|SalesOrderController'` | Pass | 16 tests and 39 assertions passed. |
| `docker compose exec -T db mysql ... < database/schema-and-seed.sql` | Pass | Phase 5 schema/seed expansion applied to MySQL. |
| `composer test -- --filter DemoSeedIntegrationTest` | Pass | 1 MySQL-backed seed contract test and 8 assertions passed. |
| `docker compose exec -T app php scripts/check-low-stock.php` | Pass | Printed low-stock rows including `SKU-DEMO-001`, `SKU-DEMO-002`, and seed low-stock examples. |
| HTTP dashboard/report/API smoke | Pass | `/dashboard`, `orders.csv`, `stock-ledger.csv`, authenticated availability JSON, and unauthenticated API 401 were verified on `localhost:8082`. |
| `composer test` | Pass | Full PHPUnit suite passed: 77 tests and 197 assertions. |
| `composer analyse -- --no-progress` | Pass | PHPStan reported no errors; report refreshed in `docs/quality/phpstan-report.txt`. |

## Phase 6

| Scenario | Command | Expected Result |
|---|---|---|
| Shared validation helpers | `composer test -- --filter Validation` | Required strings, email, enum, positive integer, non-negative money, and ISO date values normalize or raise validation errors. |
| Safe browser/API errors | `composer test -- --filter ErrorResponse` | Browser validation errors render escaped HTML with 422; API errors return JSON status and message. |
| Session and CSRF hardening | `composer test -- --filter SecurityAudit` | Session cookie options include `HttpOnly`, `SameSite=Lax`, environment-aware `Secure`; CSRF comparison is strict; every POST form carries a token. |
| Escaping helper | `composer test -- --filter ViewEscaping` | Shared HTML escaping converts `<`, `>`, and quotes using `ENT_QUOTES` UTF-8 escaping. |
| Full regression | `composer test` | Unit and MySQL integration test suite remains green. |
| Static analysis | `composer analyse` | PHPStan reports no errors. |
| Docker runtime | `APP_PORT=8082 docker compose up -d --build app` | App image rebuilds and starts with healthy MySQL dependency on port `8082`. |
| HTTP CSRF/runtime smoke | Admin `curl` session against `localhost:8082` | Login page exposes CSRF token; valid login returns 302; dashboard/report/API return 200; POST login without CSRF returns 403. |
| Low-stock regression | `APP_PORT=8082 docker compose exec -T app php scripts/check-low-stock.php` | Low-stock CLI still prints real DB rows after CSRF/session changes. |

## Phase 6 Verification Results

| Command | Result | Notes |
|---|---|---|
| `composer test -- --filter 'Validation|ErrorResponse|SecurityAudit|ViewEscaping'` | Pass | 9 tests and 136 assertions passed. |
| `composer test` | Pass | Full PHPUnit suite passed: 86 tests and 333 assertions with MySQL access enabled. |
| `composer analyse` | Pass | PHPStan scanned 96 files and reported no errors. |
| `APP_PORT=8082 docker compose up -d --build app` | Pass | Docker app rebuilt and restarted on port `8082`. |
| HTTP CSRF/runtime smoke | Pass | CSRF token extracted from `/login`; valid login returned `302`; `/dashboard`, `/reports?type=stock`, and `/api/products/SKU-DEMO-001/availability` returned `200`; login POST without CSRF returned `403`. |
| `APP_PORT=8082 docker compose exec -T app php scripts/check-low-stock.php` | Pass | Printed low-stock rows including `SKU-DEMO-001` and seeded low-stock examples. |

## Phase 7

| Scenario | Command / Evidence | Expected Result |
|---|---|---|
| As-built architecture evidence | `docs/architecture/class-diagram-as-built.md` | Diagram reflects current controllers, services, repository interfaces, MySQL/in-memory repositories, support classes, and manual composition root. |
| ADR package | `docs/architecture/adr-001-layered-repository.md`, `adr-002-stock-concurrency.md`, `adr-003-stock-ledger-source-of-truth.md` | Decisions document layered repository boundary, row-lock stock concurrency, and ledger/current-balance split. |
| Quality evidence | `docs/quality/refactor-log.md`, `srp-audit.md`, `tech-debt.md`, `critique.md` | Refactors, SRP boundaries, real debt, and critique are recorded honestly. |
| Test result package | `docs/testing/test-results.md` | Unit, integration, static analysis, Docker, HTTP smoke, and environment limitations are summarized with exact commands/results. |
| AI usage evidence | `ai-usage-log.md` | AI-assisted work is logged with human review and verification notes, without credentials. |
| Git evidence availability | `git log --oneline --grep='^refactor:'` | Blocked in this workspace because it is not a Git repository; limitation is recorded in tech debt and test results. |

## Phase 7 Verification Results

| Command | Result | Notes |
|---|---|---|
| Required evidence file scan | Pass | All Phase 7 evidence files listed in the spec exist. |
| `composer test` | Pass | Full PHPUnit suite passed: 86 tests and 333 assertions. |
| `composer analyse` | Pass | PHPStan level 5 scanned 96 files and reported no errors. |
| `APP_PORT=8082 docker compose up -d --build app` | Pass | Docker app rebuilt and restarted on port `8082`. |
| `git log --oneline --grep='^refactor:'` | Blocked | Current workspace is not a Git repository. |

## Phase 8

| Scenario | Command | Expected Result |
|---|---|---|
| Clean isolated Docker startup | `COMPOSE_PROJECT_NAME=rudis_phase8 APP_PORT=8083 DB_HOST_PORT=3307 docker compose up -d --build app` | Builds app, creates clean DB volume, waits for healthy MySQL, starts app on `localhost:8083`. |
| Empty DB seed verification | MySQL aggregate query against clean `rudis_phase8` DB | Seed contains required demo roles, two warehouses, 30 products, 25 combined PO/SO rows, pending/cancelled examples, and low-stock examples. |
| Runtime smoke | `curl` login/session requests against `localhost:8083` | Admin login with CSRF works; home, products, PO, SO, dashboard, CSV report, and API routes return expected status. |
| Final full tests | `DB_PORT=3307 composer test` | Full PHPUnit suite passes against clean DB. |
| Unit tests | `DB_PORT=3307 composer test:unit` | Unit suite passes. |
| Integration tests | `DB_PORT=3307 composer test:integration` | Integration suite passes against real MySQL. |
| Static analysis | `composer analyse` | PHPStan level 5 reports no errors. |
| Secret audit | `.env` existence check and grep for key/password/token patterns | `.env` absent; matches are placeholders, docs, tests, demo credential handling, or CSRF variable names. |
| Forbidden technology audit | grep for forbidden framework/ORM/frontend-library names outside vendor | Matches are documentation statements only; runtime dependencies remain native PHP/PDO/PHPUnit/PHPStan. |

## Phase 8 Verification Results

| Command | Result | Notes |
|---|---|---|
| `COMPOSE_PROJECT_NAME=rudis_phase8 APP_PORT=8083 DB_HOST_PORT=3307 docker compose up -d --build app` | Pass | Clean isolated Compose project started without touching existing volume. |
| Empty DB seed query | Pass | 1 Admin, 2 Sales, 2 Warehouse Staff, 2 warehouses, 30 products, 13 POs, 12 SOs, 7 low-stock rows. |
| HTTP runtime smoke | Pass | Login `302`; home/products/PO/SO/dashboard/orders CSV/API all returned `200`; product page included `SKU-DEMO-001`. |
| `DB_PORT=3307 composer test` | Pass | 87 tests, 336 assertions. |
| `DB_PORT=3307 composer test:unit` | Pass | 75 tests, 285 assertions. |
| `DB_PORT=3307 composer test:integration` | Pass | 12 tests, 51 assertions. |
| `composer analyse` | Pass | PHPStan level 5 scanned 96 files and reported no errors. |
| Git release checks | Blocked | Workspace is not a Git repository, so status/log/tag checks cannot run. |

## Operational UI Enhancement

| Scenario | Command | Expected Result |
|---|---|---|
| CSS design system | Inspect `public/assets/css/app.css` | Custom CSS defines operational shell, page headers, toolbars, forms, filters, tables, metric cards, status badges, report panels, alerts, empty states, and mobile rules. |
| Priority page smoke | `curl` with Admin session for dashboard/products/PO/SO/reports/API | Enhanced pages render with `page-header`, `data-table`, `metric-grid`, `report-grid`, and `status-badge` markers where applicable. |
| Regression | `composer test` and `composer analyse` | Business behavior and static analysis remain green after presentation changes. |

## Operational UI Enhancement Verification Results

| Command | Result | Notes |
|---|---|---|
| PHP lint over views/public | Pass | All modified PHP views and front-controller files are syntactically valid. |
| Focused UI tests | Pass | 17 tests and 159 assertions passed. |
| `composer test` | Pass | 87 tests and 336 assertions passed. |
| `composer analyse` | Pass | PHPStan level 5 reported no errors. |
| `APP_PORT=8082 docker compose up -d --build app` | Pass | Docker app rebuilt and started on `localhost:8082`. |
| HTTP smoke | Pass | Login `302`; dashboard/products/PO/SO/reports/orders CSV/API returned `200`; UI class markers appeared in HTML. |

## Modern SaaS Clean Refinement Verification Results

| Command | Result | Notes |
|---|---|---|
| `find app public views -name '*.php' -print0 \| xargs -0 -n1 php -l` | Pass | All PHP controllers, public entry files, and views reported no syntax errors. |
| `APP_PORT=8082 docker compose up -d --build app` | Pass | Docker app rebuilt and started on `localhost:8082`. |
| HTTP smoke | Pass | Login `302`; CSS asset returned `200 text/css`; `/users` and `/` returned `200` with modern UI markers. |
| `composer test` | Pass | 87 tests and 336 assertions passed. |
| `composer analyse` | Pass | PHPStan level 5 scanned 97 files and reported no errors. |

## UI Shell, Dashboard, and Datatable Enhancement

| Scenario | Command | Expected Result |
|---|---|---|
| Sidebar shell | Load any authenticated page with `/assets/js/app.js` enabled | JavaScript wraps `main.page` with `.app-shell`, `.sidebar`, and `.main-content`; links remain usable without JavaScript. |
| Dashboard charts | Load `/dashboard` as Admin | Dashboard includes `data-chart` widgets rendered from server-provided status counts, plus empty states when no chart data exists. |
| Datatable tools | Load master/list pages | Tables receive a client-side toolbar with table search, sortable headers, CSV export, and empty state handling. |
| Modal forms | Click create/edit links with JavaScript enabled | Form route content opens in a modal; validation errors stay in the modal; no-JS fallback remains normal navigation. |
| Confirm/loading states | Submit mutating action forms | Custom confirmation dialog appears before state-changing POST actions; submit buttons and page navigation show loading states. |

## UI Shell, Dashboard, and Datatable Verification Results

| Command | Result | Notes |
|---|---|---|
| `find app public views -name '*.php' -print0 \| xargs -0 -n1 php -l` | Pass | All PHP controllers, public files, and views reported no syntax errors. |
| `node --check public/assets/js/app.js` | Pass | JavaScript syntax is valid. |
| `APP_PORT=8082 docker compose up -d --build app` | Pass | Docker app rebuilt and started on `localhost:8082`. |
| HTTP smoke | Pass | Login `302`; CSS and JS assets returned `200`; dashboard/products/users returned `200`; UI feature markers were present. |
| `composer test` | Pass | 87 tests and 336 assertions passed. |
| `composer analyse` | Pass | PHPStan level 5 scanned 97 files and reported no errors. |

## Enterprise Security Hardening

| Scenario | Command | Expected Result |
|---|---|---|
| Audit logger behavior | `composer test -- --filter AuditLoggerTest` | Audit events are delegated to the repository with actor, action, entity, status, request metadata, and structured metadata. |
| Login rate limiter behavior | `composer test -- --filter LoginRateLimiterTest` | Five failures in the configured window block subsequent login attempts for the same email/IP; successful login resets the counter. |
| Auth service security events | `composer test -- --filter AuthServiceTest` | Login failure, login block, login success, and logout continue to preserve auth behavior while writing audit events. |
| MySQL audit persistence | `composer test -- --filter SecurityAuditRepositoryIntegrationTest` | `audit_logs` stores JSON metadata and `login_attempts` counts/reset behavior works against MySQL. |
| Structured error logging | `composer test -- --filter JsonFileLoggerTest` | JSON-lines log entries include timestamp, level, message, context, and exception metadata. |
| Runtime login throttling | `curl` six failed login attempts for a unique email on `localhost:8082/login` | First five failures are recorded; sixth attempt is blocked and audited as `auth.login_blocked`. |

## Enterprise Security Hardening Verification Results

| Command | Result | Notes |
|---|---|---|
| Focused security tests | Pass | Audit, rate limiter, structured logger, request recorder, auth service, and MySQL security repository tests passed: 12 tests and 48 assertions. |
| Docker runtime smoke | Pass | `audit-smoke@example.test` produced 5 `auth.login_failed` rows and 1 `auth.login_blocked` row in MySQL. |
| `composer test` | Pass | 96 tests and 394 assertions passed after approved MySQL access. |
| `composer analyse` | Pass | PHPStan level 5 scanned 107 files and reported no errors. |
