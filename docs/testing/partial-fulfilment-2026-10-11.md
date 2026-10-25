# Partial SO fulfilment — verification (11 October 2026)

User decision D-14 (not trainer), ADR-020. **Adds the SO status `PartiallyFulfilled`, which is outside the official brief's status set.** Requirement support: SO-01, DASH-01, REPORT-01, DB-01, ARCH-01/02, TEST-01/02. Optional scope approved by the project owner on 2026-10-11 (Series 2, stage A).

## Commands and results (all run in this session)

| Command | Result |
|---|---|
| `python3 scripts/build-fmcg-seed.py` (once, after generator and schema were final) | exit 0; [summary](partial-fulfilment-2026-10-11/seed-summary.json) |
| `docker compose --profile quality run --build --rm test sh -c 'php -r "require \"vendor/autoload.php\"; Tests\Support\TestDatabase::reset();" && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G --no-progress'` | exit 0: **OK (732 tests, 5503 assertions)**, PHPStan **[OK] No errors** (final run after the last code change) |
| `node --test tests/JavaScript/*.test.js` | 74 pass, 0 fail ([output](partial-fulfilment-2026-10-11/node-test.txt)); only CSS changed on the front end |
| `APP_PORT=18093 DB_HOST_PORT=13313 docker compose -p inventory-e2e-partial up -d --build --wait app` | fresh disposable stack from the rebuilt seed; removed afterwards with `down -v` |
| `python3 tests/HTTP/end-to-end.py --project inventory-e2e-partial --url http://localhost:18093 --output …` | exit 0, **235/235** ([JSON](partial-fulfilment-2026-10-11/end-to-end-http.json)) |
| `python3 tests/HTTP/business-enhancements.py --project inventory-e2e-partial --url http://localhost:18093 --output …` | exit 0, **131/131** ([JSON](partial-fulfilment-2026-10-11/business-enhancements-http.json)) |

The HTTP runs used the build before the final timeline-text fix (legacy issue rows without unit metadata now read "Goods issued; quantities in the Issue ledger rows" instead of "Shipped 0 units"); that fix is covered by the final PHPUnit run and the 8080 smoke below.

Intermediate runs: the first full run (before new tests and the seed rebuild) had 1 failure, my InMemory cancel message change (reverted to the original text for Fulfilled). The run after the seed rebuild had 1 failure, `MySqlOperationalQueryCoverageIntegrationTest::testWarehouseDashboardQueuesAndLowStockRows`, which expected the issue queue to count only Approved orders (12 vs 7); updated to the new rule (listed below).

## Seed summary

600 SO: Draft 4, PendingApproval 8, Approved 7, **PartiallyFulfilled 25** (20 with a closed remainder, 5 open), Fulfilled 519, Cancelled 37. `so_shipments 700`, `so_split_orders 136`, `so_with_multiple_shipments 119`, `so_lines_partially_issued 96`, `so_remainders_closed 20`. Invariants: `ledger_mismatches 0`, `negative_stock 0`, `reservation_mismatches 0` (D-14 formula), `reserved_above_quantity 0` (44 pairs / 528 units reserved), `issued_ledger_mismatches 0`, `fulfilment_status_mismatches 0`, `closure_mismatches 0`, `order_numbers_off_pattern 0`, `order_number_duplicates 0`, `sequence_mismatches 0`, `price_override_without_reason 0`, `so_lines_below_purchase_price 0`, `sales_discount_above_cap 0`, `approve_short 0`, `issue_skipped 0`.

## Tests

Added:
- `tests/Unit/PartialFulfilmentTest.php` (13 cases): partial → partial → full with audit metadata and ledger rows; issue without quantities ships the remainder; over-remaining 422 with nothing changed; zero/empty/negative/foreign line 422; same-key replay no-op and different payload 409; close-remaining releases the reservation, stores closure metadata and blocks further issue/closure; close rules (Admin only, Approved refused, reason required); cancel refused for PartiallyFulfilled and releasing for Approved; issue authorization before any stock lock; source availability with own remainder; shipment grouping; timeline presentation (shipment, closure, legacy rows); detail page columns/inputs/history/closure and controller issue/close paths.
- `tests/Integration/PartialFulfilmentIntegrationTest.php` (7 cases, MySQL): seed invariants and schema version; persistence/ledger/reservation/audit with replay and 409; DB CHECK `issued_quantity <= quantity`; closure persistence, issue refusal and timeline; cancel rules; outstanding (Σ ordered − issued, closed excluded), work queue, warehouse/admin dashboards and order report; migration backfill (Fulfilled lines, `updated_at` kept), conflict report without schema version, repair and repeatable run.
- `tests/Integration/ParallelStockIntegrationTest.php` (2 real-process cases): `testConcurrentPartialIssuesCannotExceedRemaining`, `testIssueRacingCloseRemainingStaysConsistent`; `tests/Support/concurrency-worker.php` gained per-line issue quantities and `close_remaining`.
- HTTP: partial-shipment scenario in `tests/HTTP/end-to-end.py` (per-line inputs, zero/over 422, partial issue, replay, 409, detail columns/history, cancel 422, Sales close 403, empty reason 422, close, issue after close 422, closure audit, two-shipment completion) and in `tests/HTTP/business-enhancements.py` (partial issue, reserved remainder, work queue, outstanding CSV, customer return bounded by the partial Issue row, close, queue exclusion, timeline).

Existing tests updated because the rule intentionally changed:
- `tests/Integration/StockReservationIntegrationTest.php::testDemoSeedSatisfiesTheReservationInvariant` — expected reserved total now Σ(quantity − issued) of Approved/PartiallyFulfilled lines without closure.
- `tests/Integration/OutstandingReportIntegrationTest.php::testAdminScopeCountsEveryOpenDocumentAndExcludesClosedRemainder` — open SO count includes PartiallyFulfilled without closure.
- `tests/Integration/OutstandingReportIntegrationTest.php::testWarehouseScopeHoldsOnlyFulfilmentWorkWithRemainingQuantities` — allows `SO PartiallyFulfilled` and checks SO quantity = Σ(ordered − issued).
- `tests/Integration/MySqlOperationalQueryCoverageIntegrationTest.php::testWarehouseDashboardQueuesAndLowStockRows` — issue queue = Approved + PartiallyFulfilled without closure.
- `tests/HTTP/end-to-end.py` — product API total after the new scenario is 3 (was 7).
- `tests/HTTP/business-enhancements.py` — reservation invariant query uses the D-14 formula.

## Running app on port 8080 (user demo data kept)

1. Backup: `var/backups/dev-before-partial-fulfilment-20261012-032225.sql.gz` (mysqldump `--single-transaction --no-tablespaces`, "Dump completed").
2. Before: 606 SO (Draft 3, PendingApproval 9, Approved 10, Fulfilled 527, Cancelled 57).
3. `docker compose build app`, then `docker compose run --rm --no-deps app php scripts/migrate-partial-fulfilment.php` twice → both `{"status":"ready",…,"fulfilled_orders":527,"reservation_updated_pairs":0,"reserved_units":914,"reservation_mismatches":0,"issued_ledger_mismatches":0,"fulfilment_status_mismatches":0,"closure_mismatches":0}`; then `docker compose up -d --build --wait app` (and again after the timeline fix).
4. After: same 606 SO and status counts; schema version `partial-fulfilment-1` recorded.
5. Smoke (curl, admin@example.test): Approved SO 574 detail shows Ordered/Issued/Remaining, `quantities[3096]` number input (min 0, max 11), `id="issue-form"`, "Issued 0 of 102 units", "Reserved: 102 units held at the source since approval", Cancel still offered; Fulfilled SO 604 shows "Shipment History", "Shipment 1 · 2026-10-09 16:18:24 · 100 units", "Issued 100 of 100 units", "Released at issue", customer-return link, no issue form; SO timeline shows "Goods issued (shipment)" with "Goods issued; quantities in the Issue ledger rows" for the pre-D-12 audit row; `/sales-orders?status=PartiallyFulfilled` 200 with the option selected ("No sales orders found." — the demo data has no partial orders yet); `/work-queue?type=Issue` and `/reports?type=outstanding` 200. No shipment or closure was posted on the user's data.
