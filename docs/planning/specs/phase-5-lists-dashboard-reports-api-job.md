# Phase 5 Specification: Lists, Dashboard, Reports, API, and Low-Stock Job

Status: Approved for planning  
Date: 2026-08-31  
References: `AGENTS.md`, `SDD.md`, `PLAN.md`, `docs/planning/CONSTITUTION.md`

## 1. Purpose

Phase 5 completes user-facing discovery and operational evidence surfaces: search/filter/sort/pagination, role-scoped dashboards, CSV reports, product availability JSON API, low-stock script, and required seed volume.

Exit gate: all role views and required evidence are demoable.

## 2. Requirement Trace

- `FIND-01`: search, filter, sort, pagination for products and orders.
- `DASH-01`: role-scoped dashboard aggregates.
- `REPORT-01`: CSV stock/order reports by date range.
- `API-01`: product availability endpoint with JSON responses.
- `JOB-01`: standalone low-stock command.
- `VIEW-01`: data and empty states.
- `VAL-01`: date/filter/sort validation.
- `ERR-01`: safe browser/API failures.
- `DB-01`: seed contract, indexes, report queries.

## 3. In Scope

- Search/filter/sort/pagination for product and order lists, 10 rows per page.
- Server-side allow-list for every sortable column.
- Seed data expanded to at least 30 products and at least 25 combined PO/SO records.
- Role-scoped dashboard:
  - Admin: inventory value, low-stock products, PO/SO pending counts grouped by status.
  - Sales: own Sales Order counts/value grouped by status.
  - Warehouse Staff: PO receipt queue, SO issue queue, low-stock products.
- CSV stock movement report by date range.
- CSV order status report by date range.
- JSON endpoint `GET /api/products/{sku}/availability`.
- Standalone `scripts/check-low-stock.php`.
- Empty-state rendering for lists/reports/dashboards.

## 4. Out of Scope

- New core workflows beyond reading/reporting existing PO/SO/master-data state.
- Dashboard chart optional feature.
- Low-stock-to-PO recommendation optional feature.
- CSV import optional feature.
- External schedulers or production cron infrastructure.

## 5. Architecture Contract

List, dashboard, report, API, and job behavior must flow through services/repositories rather than embedding query logic in views. Controllers and scripts may parse input and call services, but report/dashboard/API aggregation belongs in service/repository boundaries.

API failures must return JSON and must not render HTML error pages.

## 6. Data and Query Contract

Required query behavior:

- Product search by name/SKU.
- Product category filter.
- Product low-stock/normal filter.
- Order search by order number, supplier, or customer.
- Order status filter.
- Order date sort ascending/descending.
- Pagination fixed at 10 rows per page.
- Dashboard values generated from real database state.

Low-stock baseline for product/warehouse: `quantity < reorder_point`. Dashboard semantics should prefer per-warehouse low-stock and must document any aggregate interpretation.

Inventory value baseline: `SUM(product_stocks.quantity * products.purchase_price)`.

## 7. Authorization Rules

- Admin sees global dashboard and full CSV reports.
- Sales sees own Sales Order dashboard/report data only.
- Warehouse Staff sees stock-oriented dashboard/report data.
- API endpoint requires authentication.
- Unauthenticated API returns JSON 401.
- Missing product API request returns JSON 404.

## 8. Validation and Errors

- Date ranges must be valid dates.
- Page numbers must be positive.
- Sort keys and directions must use allow-lists.
- CSV output must escape fields correctly.
- CSV text fields beginning with `=`, `+`, `-`, or `@` should be neutralized.
- API responses must set `Content-Type: application/json`.
- No report/API error may leak SQL, stack trace, or raw exception details.

## 9. Tests

Required tests:

- Product search by SKU/name.
- Product category and low-stock filters.
- Pagination returns 10 rows per page.
- Order search/status/date sorting.
- Dashboard aggregation uses real repository data.
- Sales dashboard/report excludes other users' orders.
- API availability returns 200 shape for existing SKU.
- API availability returns JSON 401 when unauthenticated.
- API availability returns JSON 404 for missing SKU.
- Low-stock script returns expected low-stock rows.

Integration tests should validate report/API queries against seeded MySQL data.

## 10. Acceptance Criteria

1. Product and order lists support required discovery controls.
2. Dashboards are role-scoped and not hardcoded.
3. CSV reports work by date range and role scope.
4. Product availability API returns correct 200/401/404 JSON responses.
5. Low-stock script can run manually in Docker.
6. Seed data satisfies the demo seed contract.
7. Tests and PHPStan remain acceptable.

## 11. Verification Commands

```bash
composer test -- --filter Search
composer test -- --filter Dashboard
composer test -- --filter Report
composer test -- --filter ProductAvailability
composer test -- --filter LowStock
composer test
composer analyse
docker compose run --rm app php scripts/check-low-stock.php
docker compose up --build
```

## 12. Risks and Decisions

- Dashboard low-stock semantics across warehouses must be documented before implementation.
- Report date boundaries must be consistent across UI, CSV, and tests.
- Do not add chart libraries or frontend frameworks for dashboards.

## 13. Handoff to Implementation Plan

Plan tasks should implement query specs/repositories, pagination helpers, dashboard services, report exporters, API controller, low-stock script, seed expansion, tests, and evidence updates.
