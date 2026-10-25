# Negotiated line prices — verification (10 October 2026)

User decision D-13 (not trainer), ADR-019; partially supersedes D-09/ADR-015. Requirement support: PO-01, SO-01, VAL-01, DB-01, ARCH-01/02, TEST-01/02. Optional scope approved by the project owner; no official brief requirement and no status change.

## Commands and results (all run in this session)

| Command | Result |
|---|---|
| `python3 scripts/build-fmcg-seed.py` (once, after generator and schema were final) | exit 0; [summary](negotiated-price-2026-10-10/seed-summary.json) |
| `docker compose --profile quality run --build --rm test sh -c 'php -r "require \"vendor/autoload.php\"; Tests\Support\TestDatabase::reset();" && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G --no-progress'` | exit 0: **OK (710 tests, 5307 assertions)**, PHPStan level 5 **No errors** ([output](negotiated-price-2026-10-10/phpunit-phpstan.txt)) |
| `vendor/bin/phpunit --filter Negotiated --testdox` (same container) | 36 negotiated-price cases passed (unit 23, controller 7, MySQL integration 6) |
| `node --test tests/JavaScript/*.test.js` | 74 pass, 0 fail ([output](negotiated-price-2026-10-10/node-test.txt)) |
| `APP_PORT=18091 DB_HOST_PORT=13311 docker compose -p inventory-e2e-nego up -d --build --wait app` | fresh disposable stack from the rebuilt seed; removed afterwards with `down -v` |
| `python3 tests/HTTP/end-to-end.py --project inventory-e2e-nego --url http://localhost:18091 --output …` | exit 0, **211/211** ([JSON](negotiated-price-2026-10-10/end-to-end-http.json)) |
| `python3 tests/HTTP/business-enhancements.py --project inventory-e2e-nego --url http://localhost:18091 --output …` | exit 0, **109/109** ([JSON](negotiated-price-2026-10-10/business-enhancements-http.json)) |
| `python3 tests/HTTP/replenishment-selection.py --url http://localhost:18091` | exit 0, 15 checks ([output](negotiated-price-2026-10-10/replenishment-http.json)) |
| headless Chrome 154 (`--headless=new --remote-debugging-port=9226`), `node tests/Browser/form-drafts.cjs http://localhost:18091 http://127.0.0.1:9226` | exit 0, **18/18**, 0 page exceptions ([output](negotiated-price-2026-10-10/form-drafts-browser.txt)) |

Seed summary (unchanged invariants): `ledger_mismatches 0`, `negative_stock 0`, `reservation_mismatches 0`, `reserved_above_quantity 0`, `order_numbers_off_pattern 0`, `order_number_duplicates 0`, `sequence_mismatches 0`, `approve_short 0`, `issue_skipped 0`. New: `price_overrides` PO 12 / SO 16 (`price_overrides_above_master` SO 1), `price_override_without_reason 0`, `so_lines_below_purchase_price 0`, `sales_discount_above_cap 0`. The generator's exit code now also requires the three new invariants to be zero. Every seed table except `purchase_order_items` and `sales_order_items` is byte-identical to the previous seed (overrides are chosen from document counters, not `mt_rand`).

New business HTTP checks: different price without reason 422, Sales discount above the cap 422, below-cost price 422 even for Admin (no order created in any of the three), negotiated price stored as `19.00|20.00|Promo akhir bulan HTTP` with a posted `list_price=19` ignored, approval page shows `-5,00% · -Rp 1,00 per unit` and "1 line priced away from master; total difference -Rp 2,00", anonymous detail not served. Browser: the restored three-line PO draft keeps the negotiated line's price and reason (required, visible), the stored draft has no `list_price`, and the created PO detail shows "Negotiated", the reason and the Draft notice.

## Running app on port 8080 (user demo data kept)

1. Backup: `var/backups/dev-before-negotiated-prices-20261010-180506.sql.gz` (mysqldump `--single-transaction --no-tablespaces`, "Dump completed").
2. Before: 407 PO, 605 SO, 3,370 PO lines, 3,256 SO lines, Σ quantity 163,610, 6,419 ledger rows.
3. `mysql … < database/migrations/20261010-negotiated-prices.sql` in the DB container, then `docker compose up -d --build --wait app`, then `docker compose exec -T app php scripts/migrate-negotiated-prices.php` (re-run, idempotent) → `{"status":"ready","destructive":false,"purchase_order_items":{"lines":3370,"overridden":0,"override_without_reason":0},"sales_order_items":{"lines":3256,"overridden":0,"override_without_reason":0}}`. Line prices and `updated_at` checksums identical before and after; Σ quantity and ledger count unchanged; `schema_versions` has `negotiated-price-1`; `scripts/health-check.php` → ready.
4. Smoke with curl-style HTTP client ([output](negotiated-price-2026-10-10/smoke-8080.json), [cap](negotiated-price-2026-10-10/smoke-8080-cap.json)):
   - sales2 `GET /sales-orders/create`: editable `selling_price` number input, `price_reason`, "Use master price", cap hint 10 %, 0 `readonly`.
   - sales2 POST BIS-0001 (master Rp 293.600, purchase Rp 244.700) at 278920 without reason → 422 "A reason of 3–255 characters is required…"; at 146800 → 422 below purchase price; at 258368 (12 % off) → 422 "Sales may discount at most 10 %… lowest Rp 264.240,00"; none created.
   - sales2 POST at 278920.00 (−5 %), reason "Smoke D-13: promo akhir bulan", posted `list_price=1` → 302: **SO id 606, `SO-2610-0040`**, stored `278920.00 | 293600.00 | Smoke D-13: promo akhir bulan` (client list price ignored). Detail: `-5,00% · -Rp 14.680,00 per unit · -Rp 29.360,00 line` with the reason. Submitted → PendingApproval; Admin detail notice "1 line priced away from master; total difference -Rp 29.360,00. Check the reasons above before approving." placed above Approve. Timeline: "1 line priced away from master". sales1 opening it → 403.
   - The first catalogue product in the user's demo data (KES-9999) has a selling price below its purchase price, so any changed price for it is refused as below cost; the smoke therefore used seed product BIS-0001. No data was changed for this.
   - Smoke document created and left in the demo data: **SO-2610-0040 (id 606, sales2, PendingApproval)**. Nothing was deleted.

## Tests added or changed

Added: `tests/Unit/NegotiatedPriceTest.php`, `tests/Unit/NegotiatedPriceControllerTest.php`, `tests/Integration/NegotiatedPriceIntegrationTest.php`; JavaScript cases in `tests/JavaScript/order-items.test.js` (cents, difference, hint) and `tests/JavaScript/form-drafts.test.js` (price/reason stored, `list_price` excluded); negotiated-price block in `tests/HTTP/business-enhancements.py`.

Changed because ADR-015 behaviour intentionally changed (assertions moved to the new rule, none weakened):
- `tests/Unit/PurchaseOrderServiceTest::testLinePriceComesFromProductMasterNotFromInput` → `testWithoutSubmittedPriceTheMasterPriceIsUsedAndTamperedKeysAreIgnored` (legacy key and client `list_price` ignored; also asserts list price and null reason).
- `tests/Unit/SalesOrderServiceTest::testLinePriceComesFromProductMasterNotFromInput` → same rename and assertions for SO.
- `tests/Unit/PurchaseOrderControllerTest::testCreatePrefillsSelectedReplenishmentLinesWithoutCreatingAnOrder`: price is now a named, editable input prefilled with the master price `250.00` (was: read-only, unnamed `Rp 250,00`); asserts no `readonly`.
- `tests/Unit/PurchaseOrderControllerTest::testStoreCreatesOneDraftWithEveryReviewedItem`: the `250.50` line now carries a reason and is stored as negotiated (250.50, list 250.00, reason); the master-equal line keeps a null reason (was: posted prices ignored).
- `tests/Unit/SalesOrderControllerTest::testSalesCreatesOneOwnDraftWithEveryLineAndTemplateHasNoFieldNames`: the `90.25` line (above master) now carries a reason and is stored as 90.25 with list 90.00 (was: ignored).
- `tests/Browser/form-drafts.cjs`: restored prices are editable named inputs; one negotiated line with reason round-trips through the draft, `list_price` is never stored, created PO detail shows the override (was: read-only unsubmitted Rupiah display).
- `tests/HTTP/replenishment-selection.py`: no longer posts arbitrary prices (`1000`/`250`) that are not the master; empty price means master.

## Known gaps

- Discount cap is global (not per customer, product or user); Admin overrides are not separately approved.
- The cost floor uses the current product purchase price, not an average or landed cost; a master selling price that is itself below cost is accepted unchanged.
- PO/SO creation audit stays request-level without a document id; override details are shown from line data on the timeline and detail pages rather than in audit metadata.
- Physical mobile devices not tested; Chrome emulation widths 360/390/768/1440 passed in the drafts suite.
