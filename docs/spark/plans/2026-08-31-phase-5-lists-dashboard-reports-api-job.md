# Phase 5 Lists Dashboard Reports API Job Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement discovery controls, role dashboards, CSV reports, product availability JSON API, low-stock command, and required seed volume.

**Architecture:** Keep query composition in repositories and aggregation rules in services. Controllers/scripts parse request inputs and delegate; API controllers return JSON for all success and failure paths.

**Tech Stack:** PHP 8.2+, MySQL 8, PDO prepared statements, PHPUnit 10, PHPStan level 5, Docker Compose, native PHP CSV output, Vanilla JS only where useful.

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
- Dashboard values and reports must be generated from real queries, never hardcoded.
- Sort columns must use server-side allow-lists.
- Phase 5 must not implement optional dashboard charts, CSV import, low-stock-to-PO recommendation, or production cron infrastructure.

---

## File Structure Map

- Create support: `app/Support/Pagination.php`, `app/Support/PaginatedResult.php`, `app/Support/CsvResponse.php`.
- Add query criteria: `app/Service/ProductSearchCriteria.php`, `app/Service/OrderSearchCriteria.php`.
- Modify product/order repositories for search/filter/sort/page methods.
- Create services: `DashboardService.php`, `ReportService.php`, `LowStockService.php`, `ProductAvailabilityService.php`.
- Create controllers: `DashboardController.php`, `ReportController.php`, `Api/ProductAvailabilityController.php`.
- Create script: `scripts/check-low-stock.php`.
- Create views: `views/dashboard/index.php`, report links/forms, list empty states.
- Modify seeds in `database/schema-and-seed.sql`.
- Add tests for search, dashboard, reports, API, and low-stock command.

---

### Task 1: Pagination and Search Criteria

**Files:**
- Create: `app/Support/Pagination.php`, `app/Support/PaginatedResult.php`, `app/Service/ProductSearchCriteria.php`, `app/Service/OrderSearchCriteria.php`
- Test: `tests/Unit/SearchCriteriaTest.php`

**Interfaces:**
- Produces: normalized page size 10, positive page numbers, and allow-listed sort directions.

- [ ] Add tests for page normalization, max 10 per page, invalid sort rejection, and query preservation.
- [ ] Implement immutable criteria objects with explicit allowed sort keys for products and orders.
- [ ] Run `composer test -- --filter SearchCriteriaTest`.
- [ ] Commit with `feat: add search criteria pagination`.

### Task 2: Repository Search Methods

**Files:**
- Modify: product, purchase order, and sales order repository contracts/implementations
- Test: `tests/Unit/SearchRepositoryTest.php`, integration query tests as needed

**Interfaces:**
- Produces: `searchProducts(ProductSearchCriteria $criteria): PaginatedResult`, `searchOrders(OrderSearchCriteria $criteria): PaginatedResult`.

- [ ] Add tests for SKU/name search, category filter, low-stock filter, status filter, and date sorting.
- [ ] Implement MySQL queries with prepared values and allow-listed column names only.
- [ ] Implement fake repository equivalents for service tests.
- [ ] Run `composer test -- --filter Search`.
- [ ] Commit with `feat: add searchable repositories`.

### Task 3: Demo Seed Expansion

**Files:**
- Modify: `database/schema-and-seed.sql`
- Test: `tests/Integration/DemoSeedIntegrationTest.php`

**Interfaces:**
- Produces: at least 30 products, at least 25 combined PO/SO records, varied statuses, low-stock examples.

- [ ] Add integration test counting products, users, warehouses, orders, and required statuses.
- [ ] Extend seed data with varied reorder points and warehouse stock.
- [ ] Ensure at least one `PendingApproval` and one `Cancelled` example.
- [ ] Run `docker compose run --rm app composer test -- --filter DemoSeedIntegrationTest`.
- [ ] Commit with `chore: expand demo seed data`.

### Task 4: Dashboard Services and Views

**Files:**
- Create: `app/Service/DashboardService.php`, `app/Controller/DashboardController.php`, `views/dashboard/index.php`
- Modify: repositories and `public/index.php`
- Test: `tests/Unit/DashboardServiceTest.php`

**Interfaces:**
- Produces: role-scoped dashboard arrays for Admin, Sales, and Warehouse Staff.

- [ ] Add tests that Admin gets global totals, Sales gets own SO totals, Warehouse Staff gets stock/fulfillment queues.
- [ ] Implement real query-backed aggregate methods; no constants for totals.
- [ ] Wire `GET /dashboard`.
- [ ] Render empty states for no dashboard rows.
- [ ] Run `composer test -- --filter Dashboard`.
- [ ] Commit with `feat: add role dashboards`.

### Task 5: CSV Reports

**Files:**
- Create: `app/Service/ReportService.php`, `app/Controller/ReportController.php`, `app/Support/CsvResponse.php`
- Modify: `public/index.php`
- Test: `tests/Unit/ReportServiceTest.php`

**Interfaces:**
- Produces: `GET /reports/stock-ledger.csv`, `GET /reports/orders.csv`.

- [ ] Add tests for role scoping, date range validation, CSV escaping, and formula neutralization.
- [ ] Implement stock ledger and order status report queries by date range.
- [ ] Set download content type and escaped CSV body.
- [ ] Wire report routes.
- [ ] Run `composer test -- --filter Report`.
- [ ] Commit with `feat: add csv reports`.

### Task 6: Product Availability API

**Files:**
- Create: `app/Controller/Api/ProductAvailabilityController.php`, `app/Service/ProductAvailabilityService.php`
- Modify: `public/index.php`, router path parameter support if absent
- Test: `tests/Unit/ProductAvailabilityApiTest.php`

**Interfaces:**
- Produces: `GET /api/products/{sku}/availability` with JSON 200/401/404.

- [ ] Add tests for authenticated 200 shape, unauthenticated JSON 401, and missing SKU JSON 404.
- [ ] Add minimal router path-parameter support if needed.
- [ ] Implement API controller with `Content-Type: application/json`.
- [ ] Ensure API failures never render HTML.
- [ ] Run `composer test -- --filter ProductAvailability`.
- [ ] Commit with `feat: add product availability api`.

### Task 7: Low-Stock Command

**Files:**
- Create: `app/Service/LowStockService.php`, `scripts/check-low-stock.php`
- Test: `tests/Unit/LowStockPolicyTest.php`

**Interfaces:**
- Produces: manual command `php scripts/check-low-stock.php`.

- [ ] Add tests for `quantity < reorder_point` per product/warehouse.
- [ ] Implement service that returns low-stock rows from repository data.
- [ ] Implement CLI script using bootstrap/config and safe text output.
- [ ] Run `composer test -- --filter LowStock`.
- [ ] Run `docker compose run --rm app php scripts/check-low-stock.php`.
- [ ] Commit with `feat: add low stock command`.

### Task 8: Verification and Evidence

**Files:**
- Modify: `docs/testing/test-scenarios.md`, `docs/quality/phpstan-report.txt`, `ai-usage-log.md`

- [ ] Run `composer test -- --filter Search`.
- [ ] Run `composer test -- --filter Dashboard`.
- [ ] Run `composer test -- --filter Report`.
- [ ] Run `composer test -- --filter ProductAvailability`.
- [ ] Run `composer test -- --filter LowStock`.
- [ ] Run `composer test`.
- [ ] Run `composer analyse > docs/quality/phpstan-report.txt`.
- [ ] Run `docker compose up --build` and manually verify all role views.
- [ ] Update evidence with exact pass/fail output.
- [ ] Commit with `test: verify phase 5 operational views`.

## Self-Review

This plan covers search/filter/sort/pagination, dashboards, CSV reports, API, low-stock command, seed contract, validation, authorization scope, and evidence. It excludes optional chart/import/recommendation work.

## Execution Handoff

Plan complete and saved to `docs/spark/plans/2026-08-31-phase-5-lists-dashboard-reports-api-job.md`. Use Subagent-Driven or Inline Execution before implementation.
