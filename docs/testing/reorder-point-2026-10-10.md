# Evidence: one low-stock rule and per-warehouse reorder point (10 October 2026)

Scope: user decision D-10, ADR-016 (optional scope, not a trainer decision). All results below come from commands run on 10 October 2026.

## Quality container (full suite + static analysis)

Command:

```bash
docker compose --profile quality run --build --rm test sh -c 'php -r "require \"vendor/autoload.php\"; Tests\Support\TestDatabase::reset();" && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G --no-progress'
```

Result (final run, exit 0):

```text
OK (626 tests, 4759 assertions)
[OK] No errors        (PHPStan level 5)
```

Earlier runs in the same session failed and were fixed before the final run: a helper named `count()` clashed with `TestCase::count()`; the audit query used `metadata` instead of `metadata_json`; the command test gave the third warehouse zero stock (so it was correctly low); MySQL JSON reorders object keys (the test now compares sorted keys).

JavaScript: `node --test tests/JavaScript/*.test.js` → 72 pass, 0 fail (only CSS changed on the client side).

## New and changed tests

- New `tests/Unit/ReorderThresholdTest.php`: effective threshold, `<` at the boundary (equal is not low) for default and override, zero override, inactive product/warehouse never low, input parsing and range.
- New `tests/Unit/ReorderPointServiceTest.php`: Admin sets/clears with audit metadata; no-op save writes no audit; Sales and Warehouse Staff get 403 with no change; out-of-range 422 and unknown pair 404 without audit; in-memory filter uses any low warehouse; controller redirect, 422 with retained value, read-only page for non-admins, 403 before input parsing.
- New `tests/Integration/ReorderPointOverrideIntegrationTest.php` (MySQL): dashboard count and warehouse rows with override and strict boundary; Products filter low/normal per warehouse (total ignored) and low + normal = active products; replenishment `max(0, 40 − 12 − 5) = 23` with an Ordered PO; `scripts/check-low-stock.php` output `quantity=8 | reorder=9 (warehouse override)`; audit row written atomically (failing audit rolls back), no ledger or quantity change, Sales 403; migration run twice from a database without the column.
- Changed `tests/Integration/MySqlMasterDataCoverageIntegrationTest.php::testProductSearchFiltersByCategoryAndStockStatus`: was total stock `<=`/`>` product reorder point; now per-warehouse effective rule, and badges must agree with the filter.
- Changed `tests/Integration/MySqlOperationalQueryCoverageIntegrationTest.php` (admin count and warehouse rows): expected SQL now uses `COALESCE(ps.reorder_point_override, p.reorder_point)`.
- Changed `tests/Integration/DemoSeedIntegrationTest.php`: new check that the seed has overrides and one `products.reorder-point` audit row per override.
- Changed HTTP acceptance scripts `tests/HTTP/dashboard-low-stock.py` and `tests/HTTP/end-to-end.py` to the effective-threshold SQL (not run in this session).
- `tests/Unit/EntityGettersTest.php`, `ProductSearchTest.php`, `LowStockPolicyTest.php` needed no change (3 < 5 is low under both rules).

## Seed rebuild

`python3 scripts/build-fmcg-seed.py` (run once, exit 0). Summary: 400 PO, 600 SO, `reorder_overrides: 15`, `ledger_mismatches: 0`, `negative_stock: 0`, `low_stock_pairs: 22` (effective rule, active only), `low_stock_pairs_product_default_only: 22`. A per-table comparison with the previous seed showed identical data for every table except `product_stocks` (new column, 15 overrides) and `audit_logs` (15 new rows). The overrides (10 fast movers in Gudang Pusat Cikarang at 2 × default, 5 slow movers in Gudang Regional Makassar at ½ × default) did not change the low-pair count for this dataset.

## Local app on port 8080 (user demo data kept)

1. Backup: `var/backups/dev-before-reorder-override-20261010-165051.sql.gz`.
2. `docker compose build app`, then `docker compose run --rm --no-deps app php scripts/migrate-reorder-point-override.php` twice → `{"status":"ready","migration":"20261010-reorder-point-override","destructive":false}` both times.
3. `docker compose up -d --build app`. Database after migration: 501 products, 1,503 pairs, 6,419 ledger rows, total quantity 163,610 (unchanged), schema version `reorder-override-1` present, 22 low pairs.

Smoke check with curl (admin, warehouse1, sales1 / `password`):

- Product 1 (BIS-0001) detail: columns Warehouse, Quantity, Reorder Point, Source, Status, plus an Admin form per warehouse.
- Admin set Gudang Pusat Cikarang to 206 (stock 205) → 302; row shows `205 206 Warehouse override Low stock`; Products filter `q=BIS-0001`: found under Low, not under Normal; Admin and Warehouse dashboards "Low Stock Rows" 22 → 23; command prints `BIS-0001 | … | Gudang Pusat Cikarang | quantity=205 | reorder=206 (warehouse override)` (23 lines); replenishment row `205 206 warehouse 0 1` (open PO supply 0, suggested 1).
- Admin invalid `-3` and `abc` → 422 with the alert "Warehouse reorder point must be a whole number from 0 to 4294967295, or empty to use the product default." and the value kept.
- Warehouse and Sales POST → 403; their detail page shows the values without the form.
- Admin cleared the override (empty) → 302; BIS-0001 back under Normal; dashboard 22.

The smoke check left two success audit rows (`products.reorder-point`, set and clear) and four failure rows (two 422, two 403) in the 8080 database; no overrides remain set there, and the ledger was not changed.
