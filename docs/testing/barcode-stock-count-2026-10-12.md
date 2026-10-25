# Barcodes and stock count sessions — evidence (12 October 2026)

User decision D-18 (not trainer), ADR-024. All results below come from commands run on 12 October 2026; raw output is in `barcode-stock-count-2026-10-12/`.

## Commands and results

| Command | Result |
|---|---|
| `docker compose --profile quality run --build --rm test sh -c 'php -r "require \"vendor/autoload.php\"; Tests\Support\TestDatabase::reset();" && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G --no-progress'` (after the final seed) | **OK (820 tests, 6402 assertions)**, PHPStan **[OK] No errors** ([quality-run.txt](barcode-stock-count-2026-10-12/quality-run.txt)) |
| `node --test tests/JavaScript/*.test.js` | 86 pass, 0 fail ([js-tests.txt](barcode-stock-count-2026-10-12/js-tests.txt)) |
| `ParallelStockIntegrationTest` run 3 extra times (16 real-process tests incl. the new count-posting race) | OK (16 tests, 162 assertions) each time ([parallel-repeat.txt](barcode-stock-count-2026-10-12/parallel-repeat.txt)) |
| `python3 scripts/build-fmcg-seed.py` | see "Seed" below; final build exit 0, every invariant 0 ([seed-summary.json](barcode-stock-count-2026-10-12/seed-summary.json)) |
| `APP_PORT=18098 DB_HOST_PORT=13318 docker compose -p inventory-e2e-count up -d --build --wait app` | fresh disposable stack from the final seed; removed with `down -v` afterwards |
| `python3 tests/HTTP/end-to-end.py --project inventory-e2e-count --url http://localhost:18098 --output …` | **307/307** ([JSON](barcode-stock-count-2026-10-12/end-to-end-http.json)) |
| `python3 tests/HTTP/business-enhancements.py --project inventory-e2e-count --url http://localhost:18098 --output …` | **270/270** ([JSON](barcode-stock-count-2026-10-12/business-enhancements-http.json)) |
| headless Chrome 154 (`--headless=new --remote-debugging-port=9226`), `node tests/Browser/barcode-scan.cjs http://localhost:18098 http://127.0.0.1:9226 386 8991103202755 485 8991158202618 4 8991051202616` | **8/8**: typing a barcode + Enter (real key events) into the PO scan field increments the matching receive line (1, then 2), unknown and misread codes show an error and change nothing, scan to pick prefills the issue quantity, scanning on the blind count sheet adds to the lot line, no overflow at 360/768 px, no page exceptions ([output](barcode-stock-count-2026-10-12/barcode-scan-browser.txt)). The first attempt on this stack failed its camera-button assertion because it ran before the scanner initialised; the test now waits for initialisation and compares with `BarcodeDetector` + `getUserMedia` support (true in this Chrome). |
| `node tests/Browser/form-drafts.cjs http://localhost:18098 http://127.0.0.1:9226` | **20/20**, no page exceptions ([output](barcode-stock-count-2026-10-12/form-drafts-browser.txt)) |

## Seed

The generator creates four counts through `StockCountService`: count 1 (Cikarang, Kesehatan) posted with small ±1 variances; count 2 (Surabaya, Biskuit & Wafer) with one line just above the 2 % tolerance, recounted by the second counter (variance −1) and posted; count 3 (Makassar, Perawatan Diri) with a found lot `TEMUAN-0925-01` created at posting; count 4 (Cikarang, Bumbu & Saus) still **Counting**, half counted. Approvers are Admins who counted nothing.

Build attempts (the build script rewrites the seed, so each attempt is reported):
1. Failed: the generator's recount line (variance −3) was within the 2 % tolerance for large lots, so `requestRecount` refused it (422, as designed). Fixed: the variance is now just above the tolerance.
2. Succeeded, but the count timestamps were all equal: the generator's `$schedule()` kept the `time` of the first step in follow-up events (`$data + ['time' => …]`). This **pre-existing generator bug** also gave transfers and expired write-offs the clock of their first step (e.g. a transfer created, approved, dispatched and received at the same second). Fixed in `$schedule()` (`['time' => $time] + $data`).
3. Final build, exit 0. Because follow-up events now run at their own time (expiry and availability are evaluated then), the generated history differs from the D-17 build: 400 PO, 600 SO (Draft 3, PendingApproval 11, Approved 10, PartiallyFulfilled 23, Fulfilled 513, Cancelled 40), 49 stock operations (40 transfers: 37 received, 2 in transit, 1 approved; 9 expired write-offs, all posted, 175 lots), 4,333 lots, 1 issue blocked by expired stock, 37 low-stock pairs, 6,400 units in transit. Counts: 4 (3 Posted, 1 Counting), 384 count lines, 33 posted variance lines (= 33 `STOCK_COUNT` ledger rows), 1 recounted line, 1 found lot created, net −4 units / Rp −19.132,84.

Invariants, all **0**: new `count_posted_delta_mismatches`, `count_below_reserved`, `count_approved_by_counter`, `count_expected_mismatches`, `count_open_overlaps`; and every earlier one — `ledger_mismatches`, `negative_stock`, `reservation_mismatches`, `reserved_above_quantity`, `issued_ledger_mismatches`, `fulfilment_status_mismatches`, `closure_mismatches`, the five transit checks, `line_unit_mismatches`, `whole_unit_mismatches`, `base_unit_mismatches`, `line_list_price_mismatches`, `barcode_check_digit_errors`, order-number and sequence checks, price-override checks, `lot_aggregate_mismatches`, `lot_ledger_mismatches`, `lot_count_mismatches`, `ledger_rows_without_lot`, `issue_from_expired_lot`, `fefo_violations`, `transfer_lot_mismatches`.

## Migration on 8080 (user demo data)

1. Backup: `var/backups/dev-before-stock-counts-20261012-132413.sql.gz` (documented `mysqldump` plus `--no-tablespaces`).
2. `docker compose build app`; dry run ([dev-dryrun.json](barcode-stock-count-2026-10-12/dev-dryrun.json)): four tables missing, lot source CHECK without `STOCK_COUNT`, 0 barcodes in the user's data.
3. `php scripts/migrate-stock-counts.php`: status **ready**, tables created, CHECK widened, count checks and lot checks all 0, version `stock-count-1` ([dev-apply.json](barcode-stock-count-2026-10-12/dev-apply.json)). Re-run: `already_applied: true`, ready ([dev-rerun.json](barcode-stock-count-2026-10-12/dev-rerun.json)).
4. `docker compose up -d --build app`.

## Smoke on 8080

Scratch script (curl-style HTTP), 25/25 ([smoke-8080.txt](barcode-stock-count-2026-10-12/smoke-8080.txt)). Changes made on the user's database:

- Barcode **2000000001012** (valid EAN-13, in-store range) set on the `pcs` base unit of product #1 through the audited Admin unit form (the script ran this step twice because its first run stopped on a script error after it; the second call kept the same value, so there are two `products.units` audit rows).
- **Stock count #1**: warehouse 1, product #1, counter warehouse1@example.test; started, both lots counted exactly, submitted, approved by admin@example.test (who did not count), posted with zero variance — no ledger row, ledger sum unchanged.

Verified: barcode API 200 (product #1, factor 1), 403 Sales, 404 unknown, 422 wrong check digit, 401 anonymous; PO 379 shows scan to receive; SO 574 shows scan to pick; blind sheet for the counter; reviewer sees expected; work queue "Count to review"; list, create, report, report CSV and work queue pages 200; Sales 403. After the smoke the runner re-verified count and lot checks: all 0.

## Tests added

- `tests/Unit/StockCountTest.php` (13): barcode validation (alphabet, EAN-13), lookup with product/unit/factor/active and 403/404, API 401/403/404/422/200; tolerance; variance = snapshot + movements between snapshot and count, delta applied to the current lot after later movements, repeat posting no-op; blind count vs reviewer (value impact); recount above tolerance by a different counter (first counter refused, unflagged line refused, within-tolerance refused, one recount per line); a second counter is required and approval by an Admin who counted is 403; posting refused below the reservation listing every line, and a negative lot listed by `StockService::postCount()`; found lots (new lot at purchase price with source STOCK_COUNT, existing empty lot with a ledger-derived snapshot, tracked product needs expiry, out-of-scope product refused, duplicate refused); authorization (create/start/cancel Admin, unassigned counter 403, Sales 403); overlapping open counts 409; controller render, 302 and 422.
- `tests/Integration/StockCountIntegrationTest.php` (5, MySQL): full lifecycle with real SO issues and receipts between snapshot, count, recount and posting (expected and variance checked against the ledger; result 19 = current 20 − 1), work queue, report, CSV, lot trace, invariants; posting below the reservation refused and 8 database CHECKs; found lot and barcode lookup from the seed; seed invariants and statistics; migration dry run, apply, idempotent re-run, unchanged stock/ledger, then a full count on the migrated schema.
- `tests/Integration/ParallelStockIntegrationTest.php` (+1 real-process race): count posting (variance −1) vs SO issue (4) on the same lot; both commit in either order and the lot ends at 5.
- `tests/JavaScript/barcode-scan.test.js` (8): validation, exact conversions, receive/pick/count counting rules, lookup statuses, scanner component (Enter, cleared field, no form submit, live region, events), camera support detection.
- `tests/Browser/barcode-scan.cjs` (new) and count/barcode scenarios in `tests/HTTP/end-to-end.py` and `tests/HTTP/business-enhancements.py`.

## Tests changed because behaviour intentionally changed

- `tests/Integration/BatchExpiryIntegrationTest.php`: the pre-lot downgrade also drops the stock count tables (they reference `stock_lots`; count sessions cannot exist before lots).

## Known gaps

One recount per line; recounts need a second assigned counter; a count locks every pair of its scope briefly at start; overlapping open counts of a warehouse are refused; physical moves between counting a shelf and saving the count are invisible to the system; scan to receive/pick state is lost on reload (by design, not drafted); camera scanning only where `BarcodeDetector` exists; the found-lot cost is the current purchase price; the ADR-023 seed figures were superseded by this build (see above).
