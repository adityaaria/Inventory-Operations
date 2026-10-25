# Stock reservation at SO approval — verification 2026-10-10

Scope: user decision D-12 (not trainer), ADR-018. Optional enhancement; official SO statuses unchanged. All results below come from commands run on 10 October 2026 in this repository.

## Changes covered

- Schema: `product_stocks.reserved_quantity INT UNSIGNED NOT NULL DEFAULT 0`, `CHECK (reserved_quantity <= quantity)`, schema version `stock-reservation-1`.
- `StockService::reserve()` / `release()`, `issue(..., releaseReservation)`, reservation guard in `adjust()`; `StockRepositoryInterface` `reservedQuantity()`, `reserve()`, `release()`, `issueReserved()` (MySQL + in-memory).
- `SalesOrderService::approve()` reserves, `issue()` consumes, `rejectOrCancel()` releases; strict audit for approve/cancel; `RequestAuditRecorder` skips their success path.
- Read models: SO detail availability/reservation summary, product detail and list, availability API, timeline detail, draft check, low stock (`LowStockSql::AVAILABLE`/`IS_LOW`), replenishment, low-stock command.
- Migration `database/migrations/20261010-stock-reservation.sql` + `scripts/migrate-stock-reservation.php` (`StockReservationReconciler`).
- Seed generator: SO lines from available stock, `approve_short` counter, invariant checks in the summary.

## Seed rebuild

`python3 scripts/build-fmcg-seed.py` (run once after the generator and schema were final; exit 0). Summary: 400 PO, 600 SO; SO statuses Draft 6, PendingApproval 4, Approved 7, Fulfilled 532, Cancelled 51; `approve_short: 0`, `issue_skipped: 0`, `ledger_mismatches: 0`, `negative_stock: 0`, `reservation_mismatches: 0`, `reserved_above_quantity: 0`, `reserved_pairs: 45`, `reserved_units: 600`, `low_stock_pairs: 34` (available rule), `low_stock_pairs_physical_quantity: 34`, `order_numbers_off_pattern: 0`, `order_number_duplicates: 0`, `sequence_mismatches: 0`, 6,586 ledger rows, 3,567 audit rows. The document history differs from the previous seed (previously Approved 9 / PendingApproval 10 / Fulfilled 524, 6,405 ledger rows, 22 low pairs), because SO lines are now drawn from available stock and Approved orders hold their stock.

## Automated tests

- `docker compose --profile quality run --build --rm test sh -c 'php -r "require \"vendor/autoload.php\"; Tests\Support\TestDatabase::reset();" && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G --no-progress'` → exit 0: **OK (674 tests, 5106 assertions)**; PHPStan **[OK] No errors** (before: 647 tests).
- `node --test tests/JavaScript/*.test.js` → 72 tests, 72 pass, 0 fail.
- `vendor/bin/phpunit --filter ParallelStockIntegrationTest` repeated 3 times → OK (10 tests, 88 assertions) each time.

New tests:
- `tests/Unit/StockReservationTest.php` (16): approve reserves (no ledger, product-order locks, audit metadata); short approval refused with nothing changed (no partial reservation, no audit); other orders' reservations counted; approval Admin-only; issue consumes reservation; same-key replay does not release twice; cancel of Approved releases (repeat is a no-op); cancel of Pending/Draft releases nothing; reject leaves other reservations; missing reservation rolls back; unreserved issue respects reservations; adjustment decrease guard (increase unaffected); transfer out and supplier return guards, posting allowed after release; `ProductStock` available/low; SO detail availability (other reservations excluded, own reservation added back, no "Short" for Approved, reservation text); controller approval shortage → 422 detail page.
- `tests/Integration/StockReservationIntegrationTest.php` (10, MySQL): seed invariant and ledger reconciliation; approve → issue lifecycle with audit metadata, one Issue ledger row, replay; short approval leaves status, approver, reservation and audit count unchanged; cancel releases in the same transaction and the timeline shows "Reserved 9 units at the source warehouse" / "Released reservation of 9 units"; failing approval audit rolls back reservation and status; adjustment, transfer and supplier return (real PO receipt) refused with 422 then increase and covered decrease post; DB CHECK fires and unsigned underflow is rejected; dashboard, Products filter, low-stock rows, replenishment (`10 − 8 − 0 = 2`), command output `quantity=12 | reorder=10 | reserved=4 | available=8`, API fields and product read model use available; migration on a database without the column reports the conflicting order (exit 2, nothing reserved, no schema version, order unchanged), then succeeds after the shortage is resolved and repeats with `updated_pairs: 0`; reconciler repairs drift.
- `tests/Integration/ParallelStockIntegrationTest.php`: new `testApprovalRacingTransferOutNeverViolatesAvailableStock` (real processes, exactly one of approval/transfer commits, available ≥ 0, invariant holds).

Tests changed because behaviour intentionally changed:
- `ParallelStockIntegrationTest::testDifferentOrdersCompetingForSameStockCannotOversell`: both orders used to be approved and race at issue; now two processes race at **approval** — one Approved (4 reserved), one PendingApproval (422); issuing the winner leaves 1 on hand and 0 reserved. Oversell guarantee kept.
- `SalesOrderIssueIntegrationTest::testSalesOrderIssuePreventsOversellAndKeepsLedgerCleanOnFailure`: the second order now fails at approve ("Insufficient available stock") instead of at issue; asserts PendingApproval, unchanged quantity/reservation/ledger.
- `AuditRemediationIntegrationTest`: `InterleavingStock` delegates the four new repository methods; the interleaved cancellation test also asserts the reservation is released once; the forced audit failure now targets only `sales-orders.issue` (approval is audited strictly too) and asserts the reservation survives the rolled-back issue.
- `SalesOrderServiceTest::testRepeatedApprovalIsAnHttp422ValidationFailure`: seeds 5 units (approval needs available stock).
- `SalesOrderWorkflowControllerCoverageTest::testAdminCancelsApprovedOrderButSalesCannot`: the repository-approved fixture now has its reservation seeded; asserts release on cancel.
- `RequestAuditRecorderTest`: the generic success example uses `/sales-orders/submit`; approve/cancel join the atomic-success list.
- `ReorderPointOverrideIntegrationTest`: the expected low-pair count uses `LowStockSql::IS_LOW` (available rule).
- `tests/HTTP/end-to-end.py`: the over-quantity SO is now refused at approval (422, PendingApproval, reserved 0) and issue stays 422.
- `tests/HTTP/business-enhancements.py`: new reservation scenario (approve reserves without ledger, API fields, product columns, competing approval 422, transfer refused, cancel releases, timeline trace, transfer posts after release, reverse transfer, invariant).
- `tests/HTTP/dashboard-low-stock.py`: expected count uses the available rule.

## HTTP on a disposable stack

`APP_PORT=18085 DB_HOST_PORT=13308 docker compose -p inventory-e2e-verify up -d --build --wait app`, then on a fresh stack:
- `python3 tests/HTTP/end-to-end.py --project inventory-e2e-verify --url http://localhost:18085 --output …` → exit 0, **211/211** checks.
- `python3 tests/HTTP/business-enhancements.py --project inventory-e2e-verify --url http://localhost:18085 --output …` → exit 0, **100/100** checks. (A first run on the previous stack failed 2 checks because the new scenario left warehouse 1 at 0, which made the following count-adjustment baseline stale (409); the reverse transfer was added and the fresh-stack rerun passed.)
- `python3 tests/HTTP/replenishment-selection.py --url http://localhost:18085` → exit 0, 15 checks.
- `docker compose -p inventory-e2e-verify down -v` afterwards.

## Application database on 8080 (user demo data)

1. Read-only pre-check: no Approved order needed more than its source stock (10 Approved orders, 914 units).
2. Backup: `var/backups/dev-before-stock-reservation-20261010-174123.sql.gz` (mysqldump `--single-transaction --no-tablespaces`, completed).
3. Before: 1,503 pairs, total quantity 163,610, 6,419 ledger rows, 3,677 audit rows, 22 low pairs (physical rule).
4. `docker compose build app` then `docker compose run --rm --no-deps app php scripts/migrate-stock-reservation.php` → `{"status":"ready",...,"updated_pairs":60,"reserved_units":914,"reservation_mismatches":0}`; second run → `updated_pairs: 0`.
5. `docker compose up -d --build app`.
6. After: quantity 163,610 and ledger 6,419 unchanged, 60 reserved pairs / 914 units, 0 pairs with reserved > quantity, schema version present, 22 low pairs (available rule).

Smoke on http://localhost:8080 (admin login with curl-style client, read-only): Approved SO #574 detail shows "Reserved: 102 units held at the source since approval", no "Short" badge and the own-reservation hint; PendingApproval SO #588 shows "Not reserved yet (reserved at approval)"; BIS-0002 product detail shows Total Stock 429 / Reserved 10 / Available 419 and the Reserved/Available columns; `/api/products/BIS-0002/availability` returns `total_reserved: 10`, `total_available: 419` and per-warehouse `reserved_quantity`/`available_quantity` (Cikarang 98/10/88); dashboard low stock 22; replenishment shows the Available column; timeline 200. `php scripts/check-low-stock.php` prints 22 lines with `| reserved=… | available=…`. `python3 tests/HTTP/dashboard-low-stock.py` → passed, 11 checks, 22 low pairs (it logs in, so auth audit rows were added). No order or stock was changed on 8080 by the smoke check.

## Known gaps

- No reservation expiry: a long-open Approved order blocks its units until issued or cancelled.
- A physical count below the reserved quantity cannot be posted until an Approved order is cancelled or stock arrives.
- Browser (CDP) suites were not rerun for this change; the SO and product pages were checked through HTML only.
