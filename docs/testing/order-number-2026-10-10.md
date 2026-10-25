# Server-assigned order numbers — verification 10 October 2026

Scope: user decision D-11 / ADR-017 (optional, not a trainer decision; D-06 stays open). Requirement IDs: PO-01, SO-01, DB-01, ARCH-01, ARCH-02, VAL-01. All results below come from commands run in this session.

## Seed rebuild

`python3 scripts/build-fmcg-seed.py` (run once with the final generator and schema; exit 0). Generator summary: 400 PO, 600 SO, `ledger_mismatches` 0, `negative_stock` 0, `low_stock_pairs` 22, `order_numbers_off_pattern` 0 (pattern and YYMM = month of `order_date`), `order_number_duplicates` 0, `sequence_mismatches` 0 (each counter = max = count per period, no extra counters), 14 `document_sequences` rows: PO-2604 61, PO-2605 57, PO-2606 65, PO-2607 63, PO-2608 81, PO-2609 55, PO-2610 18, SO-2604 25, SO-2605 79, SO-2606 103, SO-2607 105, SO-2608 128, SO-2609 122, SO-2610 38. A diff against the previous seed shows only the added `document_sequences` INSERT in the data section: every order number and every other seed row is unchanged.

## Quality container (full suite)

`docker compose --profile quality run --build --rm test sh -c 'php -r "require \"vendor/autoload.php\"; Tests\Support\TestDatabase::reset();" && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G --no-progress'`

- PHPUnit: `OK (647 tests, 4891 assertions)`, 43 s.
- PHPStan level 5: `[OK] No errors`. (The first run reported two "Right side of && is always false" findings in the new join-or-own transaction code of the order repositories; it was replaced by `MySqlTransactionManager::run()` and the full suite was re-run with the result above.)

`--testdox --filter OrderNumber` (23 tests, 120 assertions, OK) lists the new cases: formatter (padding, growth to 10000 and 123456), period and time zone, parse of exact pattern only, six invalid-part cases, in-memory counters, services continue after 18/38, new month restarts at 0001 and SO grows to 10000, forbidden/invalid creates claim nothing; MySQL: seed counters equal the per-period maximum, continue after the current maximum, tampered `order_number` ignored through MySQL-backed PO/SO controllers, rolled-back claim reused, claim outside a transaction refused, failed draft (unique-key conflict) rolls back its claim and leaves no rows, migration initialization (case-insensitive match, 3-digit/no-dash/suffixed numbers ignored, 5-digit counted by value, legacy rows unchanged, re-run never lowers), and **6 parallel PHP processes × 8 drafts (3 PO + 3 SO workers) while the parent holds both counters and then rolls back: each prefix gets exactly base+1 … base+24, no duplicates, counter = base+24**.

`node --test tests/JavaScript/*.test.js`: 72 pass, 0 fail (no JS asset changed; run as a regression check).

## Disposable HTTP/browser stack

`APP_PORT=18091 DB_HOST_PORT=13311 docker compose -p inventory-e2e-ordnum up -d --build --wait app` (fresh DB from the rebuilt seed), then removed with `down -v`.

- `tests/HTTP/end-to-end.py --project inventory-e2e-ordnum --url http://localhost:18091`: 211/211 passed ([JSON](order-number-2026-10-10/end-to-end-http.json)), including "PO create form has no order number input", "PO number assigned by server, posted value ignored" (`PO-2610-0019`), "Next PO number is consecutive" (`PO-2610-0019` → `PO-2610-0020`), "SO number assigned by server, posted value ignored" (`SO-2610-0039`).
- `tests/HTTP/business-enhancements.py`: 76/76 passed ([JSON](order-number-2026-10-10/business-enhancements-http.json)).
- `tests/HTTP/replenishment-selection.py --url http://localhost:18091`: 15 checks passed; the posted `order_number` is absent from the detail page and the heading matches `PO-\d{4}-\d{4,}` ([output](order-number-2026-10-10/replenishment-http.json)).
- `node tests/Browser/form-drafts.cjs http://localhost:18091 http://127.0.0.1:9226` (headless Chrome): 18/18 passed, 0 page exceptions ([output](order-number-2026-10-10/form-drafts-browser.txt)); the PO form has no `order_number` control, the stored draft contains no `order_number`, and the restored draft is saved as `PO-YYMM-NNNN`.

## Running app on port 8080 (user demo data kept)

1. Backup: `var/backups/dev-before-order-number-sequences-20261010-170950.sql.gz` (mysqldump `--single-transaction --no-tablespaces`, dump completed).
2. Migration applied non-destructively through the DB container: `mysql … < database/migrations/20261010-order-number-sequences.sql`, then `docker compose up -d --build app`, then `docker compose exec -T app php scripts/migrate-order-number-sequences.php` → `{"status":"ready",…,"destructive":false,"sequences":{"PO-2610":18,…,"SO-2610":38,…}}` (same 14 counters as the seed; the 10 legacy `PO-2026…`/`SO-2026…` practice numbers were ignored and are unchanged; 406 PO / 604 SO before smoke).
3. curl smoke against http://localhost:8080:
   - warehouse1 `GET /purchase-orders/create`: 0 `name="order_number"` inputs; note "Order number is assigned when the draft is saved (PO-YYMM-NNNN, e.g. PO-2610-0001)."
   - warehouse1 `POST /purchase-orders` with `order_number=PO-HACKED-SMOKE` → 302; created PO id 407 = `PO-2610-0019` (order_date 2026-10-10, created_by 4, Draft).
   - sales1 `GET /sales-orders/create`: 0 inputs, SO note shown. `POST /sales-orders` with `order_number=SO-2610-0001` → 302; created SO id 605 = `SO-2610-0039` (created_by 2, Draft).
   - sales1 `POST /purchase-orders` → 403 (no number claimed).
   - admin detail headings: `<h1>PO-2610-0019</h1>`, `<h1>SO-2610-0039</h1>`. Counters now PO-2610 = 19, SO-2610 = 39; 0 rows contain `HACKED`.
   These two smoke drafts remain in the demo data.

## Tests changed because behaviour intentionally changed

- `tests/Unit/PurchaseOrderServiceTest.php`, `tests/Unit/SalesOrderServiceTest.php`: `createDraft()` calls without a number; `testRejectsBlankOrderNumber` replaced by `testOrderNumberIsAssignedByServerAndNotConsumedByRejectedInput`; staff-create assertion now expects `PO-YYMM-0001`.
- `tests/Unit/PurchaseOrderControllerTest.php`, `tests/Unit/SalesOrderControllerTest.php`: posted `order_number` is ignored (assert server format); create forms have no input and show the note.
- Constructor gained `DocumentSequenceRepositoryInterface` (2nd argument): `PurchaseOrderWorkflowControllerCoverageTest`, `SalesOrderWorkflowControllerCoverageTest`, `SalesOrderSourceAvailabilityTest`, `OperationIdempotencyIntegrationTest`, `PurchaseOrderReceiptIntegrationTest`, `BusinessEnhancementIntegrationTest`, `ReorderPointOverrideIntegrationTest`, `AuditRemediationIntegrationTest`, `SalesOrderIssueIntegrationTest`, `ParallelStockIntegrationTest`, `tests/Support/concurrency-worker.php`, `tests/Support/large-dataset.php` (also no longer passes `CAPACITY-PO/SO-` numbers).
- `tests/HTTP/end-to-end.py`, `tests/HTTP/business-enhancements.py`, `tests/HTTP/replenishment-selection.py`: find the created order by newest id instead of a typed number; validation retention no longer expects an `order_number` value; list search uses the assigned numbers.
- `tests/Browser/form-drafts.cjs`, `tests/Browser/demo-screenshots.cjs`: no `order_number` field; created order found by newest id.

## Not run

`tests/Browser/demo-screenshots.cjs` (writes screenshot files; only its order lookup changed), `tests/Browser/end-to-end-mobile.cjs`, `tests/HTTP/load.py`, physical devices.
