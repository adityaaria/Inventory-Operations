# Batch/lot tracking, expiry and FEFO — evidence (12 October 2026)

User decision D-17 (not trainer), ADR-023. All results below come from commands run on 12 October 2026; raw output is in `batch-expiry-2026-10-12/`.

## Commands and results

| Command | Result |
|---|---|
| `docker compose --profile quality run --build --rm test sh -c 'php -r "require \"vendor/autoload.php\"; Tests\Support\TestDatabase::reset();" && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G --no-progress'` | **OK (801 tests, 6149 assertions)**, PHPStan **[OK] No errors** ([quality-run.txt](batch-expiry-2026-10-12/quality-run.txt)) |
| `node --test tests/JavaScript/*.test.js` | 78 pass, 0 fail |
| `python3 scripts/build-fmcg-seed.py` (once, after generator and schema were final) | exit 0; every invariant 0 ([seed-summary.json](batch-expiry-2026-10-12/seed-summary.json)) |
| `ParallelStockIntegrationTest` (real processes) run 3 extra times | OK (16 tests) each time |
| `APP_PORT=18097 DB_HOST_PORT=13317 docker compose -p inventory-e2e-batch up -d --build --wait app` | fresh disposable stack from the rebuilt seed; removed afterwards with `down -v` |
| `python3 tests/HTTP/end-to-end.py --project inventory-e2e-batch --url http://localhost:18097 --output …` | **296/296** ([JSON](batch-expiry-2026-10-12/end-to-end-http.json)) |
| `python3 tests/HTTP/business-enhancements.py --project inventory-e2e-batch --url http://localhost:18097 --output …` | **217/217** ([JSON](batch-expiry-2026-10-12/business-enhancements-http.json)) |
| headless Chrome (`--headless=new --remote-debugging-port=9226`), `node tests/Browser/form-drafts.cjs http://localhost:18097 http://127.0.0.1:9226` | **20/20**, 0 page exceptions, no page-level overflow at 360/390/768/1440 px ([output](batch-expiry-2026-10-12/form-drafts-browser.txt)) |

## Seed summary (rebuilt once)

400 PO, 600 SO (Draft 5, PendingApproval 7, Approved 7, PartiallyFulfilled 25, Fulfilled 521, Cancelled 35), 49 stock operations (40 transfers, 9 expired write-offs: 7 posted, 2 refused by the reservation guard and left Approved). 4,466 lots (4,181 with stock), 360 products tracking expiry, 730 receipt lines split into two lots, 136 transfer lot rows, 115 shipment lines served from more than one lot, 111 lots written off, 2 issues refused because the reservation outgrew the non-expired stock (`issue_blocked_expired`), 1 over-reserved pair. At the business date of the build: 169 near-expiry lots (≤ 30 days) and 121 expired lots with stock.

Invariants, all **0**: `lot_aggregate_mismatches`, `lot_ledger_mismatches`, `lot_count_mismatches`, `ledger_rows_without_lot`, `issue_from_expired_lot` (each Issue row checked against its lot's expiry at the issue time), `fefo_violations` (no earlier non-expired lot with stock was skipped; no override exists), `transfer_lot_mismatches`, plus every earlier check: `ledger_mismatches`, `negative_stock`, `reservation_mismatches`, `reserved_above_quantity`, `issued_ledger_mismatches`, `fulfilment_status_mismatches`, `closure_mismatches`, the five transit checks, the three unit checks, `line_list_price_mismatches`, `barcode_check_digit_errors`, order-number checks, `price_override_without_reason`, `so_lines_below_purchase_price`, `sales_discount_above_cap`. Low-stock pairs (available without expired stock) 29.

## Migration on 8080 (user demo data)

1. Backup: `var/backups/dev-before-batch-expiry-20261012-105948.sql.gz` (the documented `mysqldump` command plus `--no-tablespaces`, which the app user needs without the PROCESS privilege).
2. `docker compose build app`, then `docker compose run --rm --no-deps -T app php scripts/migrate-batch-expiry.php --dry-run` reported the tables missing (1,480 pairs / 6,000,078 units to convert). The additive DDL (`database/migrations/20261012-batch-expiry.sql`) was applied with the `mysql` client, then the dry run repeated ([dev-dryrun.json](batch-expiry-2026-10-12/dev-dryrun.json)): 1,480 pairs, 6,000,078 units, legacy stock value Rp 37.362.316.027,20, 23 pairs at zero, 6,421 ledger rows kept without lot, 8 food/beverage categories, 360 products to track expiry, 0 in-transit transfers without lots, **all unsafe counts 0**.
3. App stopped; `php scripts/migrate-batch-expiry.php`: status **ready**, 1,480 LEGACY lots, 360 products flagged, every check 0 ([dev-apply.json](batch-expiry-2026-10-12/dev-apply.json)); watermark = ledger id 6,421; version `batch-expiry-1`. Re-run: `already_applied: true`, `ready`, 0 lots created.
4. `docker compose up -d --build app`.

## Smoke on 8080

`smoke.py` (scratch script, curl-style HTTP as admin@example.test; output in [smoke-8080.txt](batch-expiry-2026-10-12/smoke-8080.txt)). Documents created on the user's database:

- **SO-2610-0041** (first run): 3 pcs of product #1 shipped from its LEGACY lot. That run's PO POSTs were refused with 403 because the script reused a stale CSRF token; its receipt attempts hit an existing PO (#407) and were refused with 422, so nothing else changed ([run 1](batch-expiry-2026-10-12/smoke-8080-run1.txt)).
- **PO-2610-0020**: 5 pcs of product #1. A receipt without lots was refused (422). The receipt was then split into lots SMOKE-NEAR (expiry +25 days, 2) and SMOKE-FAR (+240 days, 3), with unit cost 10.195,83.
- **SO-2610-0042**: 3 pcs approved and issued **FEFO**: SMOKE-NEAR 2, then SMOKE-FAR 1. The LEGACY lot, which has no expiry and so sorts last, was untouched.

All of these returned 200 with the expected content: product lots, Expiry CSV, lot search, lot trace ("Issue to"), SO detail lots, PO "Lots Received", dashboard expiry card, timeline "lot SMOKE-NEAR" and the adjustment lot selector. The near-expiry report correctly no longer lists SMOKE-NEAR (0 left). The API returns `total_available_excluding_expired` and `nearest_expiry` = SMOKE-FAR's date. After the smoke: Σ lots = stock for every pair, and 0 ledger rows without a lot after the watermark.

## Tests added

- `tests/Unit/BatchExpiryTest.php` (19): receipt splits with cost per base and ledger per lot, refusal of non-matching splits, missing/expired expiry and the LEGACY code, merge and separate cost layers, half-up cost rounding, FEFO across lots skipping an expired lot, expired lots never issued or available, approval vs available without expired stock and over-reservation 422, partial shipments FEFO, lot lock order after pairs, transfer FEFO dispatch and per-lot receipt (good → damaged → short, same code/expiry/cost), supplier return from the original lot, customer return to an expired original lot (quarantine), per-lot count and new lot rules, pair-level shortfall and the sellable reservation guard, expired write-off proposal/authorization/posting per lot, write-off refusing a lot that is not expired, LotService authorization (403) and status/CSV (formula-safe), lot status/input/business date, Expiry and trace controllers.
- `tests/Integration/BatchExpiryIntegrationTest.php` (7, MySQL): seed invariants and statistics; full lifecycle (split receipt with replay/409, FEFO issue, transfer lots, customer return, expiry, write-off) with old and new invariants; over-reservation refused, partial shipment from non-expired stock, report and API; DB guards (unsigned lot, identity, opening only LEGACY, unique seq, blank code, single watermark, ledger FK); read models (trace with PO/supplier and SO/customer, search, product lots, near report, CSV, timeline, dashboard, SQL vs PHP business date); FEFO/expiry checker detects a planted violation; migration (schema-missing dry run, detailed dry run changing nothing, unsafe stock ≠ ledger refused, apply and repeat, LEGACY lots, watermark, categories, Admin choice survives re-run, FEFO on migrated stock).
- `tests/Integration/ParallelStockIntegrationTest.php` (+2, real processes): two issues competing for the last non-expired lot (one commits, the other 422, the expired lot is never used, lots = stock); an issue racing a transfer dispatch on the same pair (both commit, FEFO and expiry hold for both, lots = ledger).
- HTTP: lot scenario in `tests/HTTP/end-to-end.py` (tracked product, receipt refusals and split, FEFO issue, detail pages, cost visibility, trace/search, Sales 403, Expiry report/CSV/422, dashboard card, API, invariants, warehouse 3 write-off proposal/approval/posting or reservation-guard refusal, no issue from expired lots) and `tests/HTTP/business-enhancements.py` (every movement has a lot, returns use the original lot, balance endpoint lists lots, stale lot baseline 409, per-lot count, new lot at purchase price, invariants, detail text). Browser: `tests/Browser/form-drafts.cjs` adjustment lot selection/baseline/new lot and PO "Add another lot".

## Tests changed because behaviour intentionally changed

- `tests/Unit/PurchaseOrderWorkflowControllerCoverageTest.php`: receipts are now listed in a "Lots Received" table, not "Receipt #1 · quantity 2".
- `tests/Integration/PurchaseOrderReceiptIntegrationTest.php`, `OperationIdempotencyIntegrationTest.php`, `AuditRemediationIntegrationTest.php`: seed products of food categories now track expiry, so their receipts name a lot and expiry (replay payloads include the same splits). The test doubles `InterleavingStock` and `FailingStockLedgerRepository` implement the extended interfaces.
- `tests/Integration/MySqlMasterDataCoverageIntegrationTest.php`, `MySqlOperationalQueryCoverageIntegrationTest.php`, `ReorderPointOverrideIntegrationTest.php`, `StockReservationIntegrationTest.php`: the independent low-stock formula subtracts expired lots, `LowStockSql::IS_LOW` became `isLow()`, and adjustment counts are relative to the seed (which now holds write-offs).
- `tests/Integration/SalesOrderIssueIntegrationTest.php`: the fixture no longer writes `product_stocks` directly (stock lives in lots); it uses a fresh product received through StockService.
- `tests/Integration/TransitTransferIntegrationTest.php`: the pre-transit downgrade also removes the transfer lot rows.
- `tests/Integration/MultiUnitIntegrationTest.php`: the pre-D-16 fixture also predates lots; after the multi-unit migration the lot migration runs (migrations in order) before stock is used.

## Infrastructure note

The disposable `test-db` (tmpfs) was OOM-killed (exit 137) during a full integration run because of the binary logs. `compose.yaml` now starts it with `--skip-log-bin`. The application `db` service is unchanged.

## Known gaps

The FEFO override is not implemented (FEFO only). Transfer receipt is entered per line and distributed over lots deterministically. Adjustments count one lot per line (several lines per document). Migrated stock has no expiry (`LEGACY`). The write-off proposal covers at most 100 lots per document. A write-off is refused while a reservation exceeds the remaining stock. The expiry rule uses one fixed UTC offset per request (Asia/Jakarta has no DST). Product CSV import has no `tracks_expiry` column.
