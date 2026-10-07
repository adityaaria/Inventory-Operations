# Warehouse dashboard low-stock correction — 7 October 2026

Requirement IDs: DASH-01, AUTH-01/02, UI-01, TEST-01/02/03. Mandatory/Optional: correction of mandatory role-scoped dashboard behavior; optional business workflows unchanged.

Before: Warehouse queries supplied low_stock_rows, but the view required low_stock_count inside the Inventory Value block. WarehouseStaff does not receive Inventory Value, so the metric was missing even when stock was low. After: DashboardService derives the count from the existing filtered warehouse-pair rows; the view renders Low Stock Rows independently of Inventory Value. Empty rows produce zero. One product below threshold in two active warehouses counts as two pairs, following the current D-05 temporary default without inventing trainer approval.

Files changed: app/Service/DashboardService.php; views/dashboard/index.php; tests/Unit/DashboardServiceTest.php; tests/HTTP/dashboard-low-stock.py; docs/operations/device-acceptance.md; docs/planning/trainer-review-business-2026-10-07.md; documentation/evidence listed below.

Security/authorization: existing authenticated role-specific repository selection retained. Warehouse sees the low-stock count without the Admin inventory valuation; Sales receives neither global metric. No authorization or session behavior changes.

Transaction/invariants: read-only presentation/data mapping; no schema, persistence or stock mutation changes. Existing active product/warehouse and quantity-below-reorder-point SQL filters remain authoritative. No extra database query is added.

Tests added/updated: unit regression for zero and same-SKU multi-warehouse counts, plus actual Warehouse controller rendering without Inventory Value. HTTP acceptance verifies Admin/Warehouse counts against MySQL, Sales scope, normal login and stock/ledger preservation.

Commands executed + result: isolated `docker compose -p inventory-quality-dashboard --profile quality run --build --rm test` passed **367 PHP tests /1617 assertions**, PHPStan level 5 without errors and **54 JavaScript tests**. [Quality log](warehouse-dashboard-2026-10-07/quality.txt). Runtime build and local activation are checked separately below. No physical-device result is implied by these checks.

Docs/evidence updated: this report, KNOWLEDGE, debt register, AI usage log; Android/iPhone checklist now includes all six optional business flows and Warehouse metric; trainer-review packet retains Pending answers.

Known gaps/risks: Android/iPhone physical acceptance, trainer policy answers and external deployment configuration remain pending. Device checklist rows stay NOT RUN until actual execution. Existing optional scope limitations in ADR-009 remain.

Recommended next task: execute the physical-device checklist on an isolated acceptance dataset and obtain actual trainer answers using the prepared review packet.

Local runtime verification: `python3 tests/HTTP/dashboard-low-stock.py` passed **11 checks**. Admin and Warehouse both displayed **7 active low-stock product–warehouse pairs**, matching MySQL; Warehouse did not expose Inventory Value and Sales exposed neither global metric. Stock digest and ledger row count were unchanged. [HTTP results](warehouse-dashboard-2026-10-07/http.json). `scripts/health-check.php` returned ready. Local runtime and production target (`inventory-operations-production:local`) builds succeeded; local app activated on port 8080. No external deployment. `git diff --check` passed. Initial smoke used a nonexistent stock id column; corrected the test to the actual composite primary key before the successful run.
