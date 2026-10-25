# Price lists, quotations, delivery notes and invoices with PPN — evidence (12 October 2026)

User decision D-19 (not trainer), ADR-025. Every result below comes from commands run on 12 October 2026; raw output is in `sales-documents-2026-10-12/`.

## Commands and results

| Command | Result |
|---|---|
| Baseline before any change: the quality command below | OK (820 tests, 6402 assertions), PHPStan [OK] |
| `python3 scripts/build-fmcg-seed.py` (run once, after the schema and generator were final) | exit 0, every invariant 0 ([seed-build.txt](sales-documents-2026-10-12/seed-build.txt), [seed-summary.json](sales-documents-2026-10-12/seed-summary.json)). Before the build the generator was run once directly in the test container (no seed written) to find bugs; that trial reported one non-zero count, `sequence_mismatches` 21, because the existing check treated the new QT/DO/INV counter rows as PO/SO counters without orders. The check now covers only PO/SO, and a new `document_number_mismatches` checks QT/DO/INV numbering. |
| `docker compose --profile quality run --build --rm test sh -c 'php -r "require \"vendor/autoload.php\"; Tests\Support\TestDatabase::reset();" && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G --no-progress'` (final) | **OK (856 tests, 6916 assertions)**, PHPStan **[OK] No errors** ([quality-run.txt](sales-documents-2026-10-12/quality-run.txt)) |
| `node --test tests/JavaScript/*.test.js` | **94 pass, 0 fail** ([js-tests.txt](sales-documents-2026-10-12/js-tests.txt)) |
| `ParallelStockIntegrationTest` run 3 more times (20 real-process tests including the 4 new races) | OK (20 tests, 192 assertions) each time ([parallel-repeat.txt](sales-documents-2026-10-12/parallel-repeat.txt)) |
| `APP_PORT=18099 DB_HOST_PORT=13319 docker compose -p inventory-e2e-salesdocs up -d --build --wait app` | fresh disposable stack from the final seed; removed with `down -v` afterwards |
| `python3 tests/HTTP/end-to-end.py --project inventory-e2e-salesdocs --url http://localhost:18099 --output …` | **329/329** ([JSON](sales-documents-2026-10-12/end-to-end-http.json)) |
| `python3 tests/HTTP/business-enhancements.py --project inventory-e2e-salesdocs --url http://localhost:18099 --output …` | **347/347** ([JSON](sales-documents-2026-10-12/business-enhancements-http.json)) |
| headless Chrome 154 (`--headless=new --remote-debugging-port=9226`), `node tests/Browser/form-drafts.cjs http://localhost:18099 http://127.0.0.1:9226` | **20/20**, no page exceptions ([output](sales-documents-2026-10-12/form-drafts-browser.txt)) |
| `node tests/Browser/barcode-scan.cjs http://localhost:18099 http://127.0.0.1:9226 93 8991301202755 480 8991077202751 4 8991059202618` | **8/8** ([output](sales-documents-2026-10-12/barcode-scan-browser.txt)) |
| `node tests/Browser/price-resolution.cjs http://localhost:18099 http://127.0.0.1:9226 6 1 karton 284792.00 10 278920.00 1` (new) | **5/5**: below the 10-karton tier the 1-karton tier applies and the next tier is shown; crossing the tier updates price and source live; an overridden price is kept and needs a reason; the printable surat jalan has a Print button, print CSS and no overflow at 360/768 px; no page exceptions ([output](sales-documents-2026-10-12/price-resolution-browser.txt)). The first run of this new test failed: an answer to the request made at page load (customer 50, product 127) was still shown after the user changed customer and product. Fixed in `price-resolution.js`: every change takes a new ticket immediately, answers for another product or an older ticket are ignored, and a pending line shows "Checking list price…". |

The HTTP suites and the barcode and draft browser suites were first run on the same stack before that JavaScript fix (329/329, 347/347, 8/8, 20/20); the table shows the final runs on a fresh stack after it.

## Seed

Rebuilt once. Price lists created by Admin before the document window (4 lists, 2,577 tiers, 63 customers assigned; customers 1–3 stay on master prices because tests and HTTP fixtures use them): **Grosir** (karton 97/95/93 % of master from 1/10/50), **Minimarket Modern** (valid Apr–Dec; pcs 99 % from 1 and 97 % from 24, inner box 97 %), **Toko Tradisional** (pcs 99 % from 6, 98 % from 12 for staples), **Promo Ramadan 2026** (valid only until 30 April; 96 % coffee/tea cartons for two supermarkets, who then fall back to master). Tiers are whole Rupiah and never below cost. Companies have an NPWP, some shops a NIK.

About one order in fifteen starts as a quotation (sent, accepted next day, converted), plus 34 standalone quotations: 74 quotations — Accepted 49 (43 converted), Rejected 7, Cancelled 5, Draft 1, Sent 2, Expired 10 (as of 12 October). 600 SO events → 603 sales orders (3 extra from converted standalone quotations): Draft 3, PendingApproval 17, Approved 8, PartiallyFulfilled 21, Fulfilled 498, Cancelled 56; 1,621 SO lines priced from a price list. 664 shipments → **664 delivery notes** (all with vehicle and driver). 592 invoices: Paid 498, PartiallyPaid 14, Unpaid 79, Void 1 (voided for a wrong NPWP and the note re-invoiced); 14 overdue; 73 delivery notes not invoiced; 534 payments; outstanding Rp 1.166.707.976,00 of Rp 7.828.331.226,00 invoiced (PPN Rp 775.780.576,00).

New invariants, all **0**: `issue_rows_without_delivery_note`, `delivery_note_line_mismatches`, `delivery_note_issue_mismatches`, `invoice_line_mismatches`, `invoice_total_mismatches`, `invoice_payment_mismatches`, `quotation_conversion_mismatches`, `price_source_mismatches`, `price_list_price_mismatches` (seed: every price-list line carries its tier), `price_list_not_applied` (seed: no master-priced line where a valid tier existed), `document_number_mismatches`. Every earlier one also **0**: `ledger_mismatches`, `negative_stock`, reservation, fulfilment, closure, the five transit checks, unit checks, `line_list_price_mismatches` (now for master-priced lines), price-override checks, order-number and sequence checks, the seven lot checks, the five count checks, `barcode_check_digit_errors`. The document history differs from the D-18 build (price lists change prices, quotation conversions shift order timing): 40 transfers planned (37 created), 4,090 lots, 1 issue blocked by expired stock, 40 low-stock pairs.

## Migration on 8080 (user demo data)

1. Backup: `var/backups/dev-before-sales-documents-20261012-143443.sql.gz` (`mysqldump --single-transaction --no-tablespaces`, ends with "Dump completed").
2. `docker compose build app`; dry run `docker compose run --rm -T app php scripts/migrate-sales-documents.php --dry-run` ([dev-dryrun.json](sales-documents-2026-10-12/dev-dryrun.json)): 10 tables missing; 529 orders with shipments → 529 shipments to create from 2,820 Issue rows, **no conflicts**. (The first dry run could not preview the grouping before the tables existed; the dry run now groups without them.)
3. App stopped, migration applied ([dev-apply.json](sales-documents-2026-10-12/dev-apply.json)): status **ready**, 529 delivery notes created (origin Migration), 2,820 Issue rows linked, every sales-document and lot check 0, no invoice created, version `sales-documents-1`. Re-run ([dev-rerun.json](sales-documents-2026-10-12/dev-rerun.json)): `already_applied: true`, 0 created, checks 0.
4. `docker compose up -d --build --wait app`.

## Smoke on 8080

`docs/testing/sales-documents-2026-10-12/smoke-8080.py` (curl-style HTTP through the UI, read-only SQL), **30/30** ([smoke-8080.txt](sales-documents-2026-10-12/smoke-8080.txt)): new pages render for Admin, dashboard card, SO form price resolution, a migrated surat jalan prints, role refusals; then one flow on a dedicated smoke customer. Documents created on the user's database:

- customer #67 "Smoke D-19 Toko Uji 2026-10-12" (NPWP 0123456789012345, PT Smoke Uji);
- price list #1 "Smoke D-19 2026-10-12" with one tier (product #96, karton, from 2, Rp 677.642) and the smoke customer assigned (no existing customer's prices changed);
- quotation **QT-2610-0001** (sent, accepted, converted) → **SO-2610-0043** (submitted, approved, issued with vehicle "B 1 SMK", driver "Smoke Driver") → **DO-2610-0039** → **INV-2610-0001** (one refused overpayment, then paid in full Rp 1.504.365,00).

The first smoke run stopped on a script error after creating the customer, the list, its tier and the assignment; a manual retry of the quotation POST from a stale CSRF token was refused with 403 (a request-audit failure row); the corrected script reused those records. After the smoke every sales-document, lot and fulfilment invariant was 0.

## Tests added

- `tests/Unit/SalesDocumentsTest.php` (20): tier resolution per unit, quantity and next tier; validity dates and active flag; Admin-only maintenance with cost floor, unknown unit, duplicate tier and audit; a tier that fell below cost refused at use; SO lines store source and resolved price, cap and reason measured against it, client list price ignored; price API authorization and validation; quotation lifecycle, conversion once with quoted prices after a list change, expiry computed (send/accept/convert refused), default 14-day validity, Sales own/Admin all, reject reason, conversion re-checking the cost floor; delivery notes per shipment with lines, lots, vehicle/driver, shipment numbers, audit; a failing note rolls back stock and reservation; note access; invoice arithmetic and rounding edges (DPP 5,50 → 6, PPN 0,72 → 1, PPN 5,50 → 6, sub-Rupiah DPP → 0), settings validation and boot config, snapshot independence from later settings; payment status machine, overpayment, date and amount rules; computed status and overdue; void rules and re-invoicing; access and reports (aging buckets, CSV, sales by customer, dashboard summary); NPWP/NIK normalization.
- `tests/Unit/SalesDocumentsControllerTest.php` (6): pages, forms, JSON statuses (200/401/403/422), print pages, payment form visibility per role, customer tax fields and NPWP 422.
- `tests/Integration/SalesDocumentsIntegrationTest.php` (6, MySQL): seed invariants and coverage of every status; price-list lines persisted and API from MySQL; delivery note linked to the Issue ledger rows and a failure rolling back stock, ledger, lots, audit and the DO counter; quotation conversion and the invoice lifecycle persisted with reports; 15 database guards; migration (dry run before DDL, conflict exit 2 without changes, apply, idempotent re-run, a new shipment afterwards).
- `tests/Integration/ParallelStockIntegrationTest.php` (+4 real-process races): two payments of 60 % on one invoice → one committed, Σ ≤ total; two conversions of one quotation → one SO; two invoice creations from one delivery note → one invoice; two concurrent partial shipments of one order → two delivery notes (shipment 1 and 2).
- `tests/JavaScript/price-resolution.test.js` (8), `tests/Browser/price-resolution.cjs` (new, 5 checks), D-19 scenarios in `tests/HTTP/end-to-end.py` and `tests/HTTP/business-enhancements.py`; `tests/Support/concurrency-worker.php` wires delivery notes into issues and adds the payment, conversion and invoice-creation jobs.

## Tests changed because behaviour intentionally changed

- `tests/Integration/MultiUnitIntegrationTest.php`: the "list price = unit master price" seed check is limited to `price_source = 'MASTER'` lines (a price-list line's list price is its tier since D-19); the pre-D-16 fixture also empties the sales-document tables (they reference the deleted rows).
- `tests/Integration/BatchExpiryIntegrationTest.php`: the pre-lot downgrade also drops the delivery note and invoice tables (they reference `stock_lots` and `stock_ledger`; they cannot exist before D-17).

## Known gaps

One price list per customer; tiers match the line unit exactly (no conversion across units); no per-customer product exceptions or date promotions inside a list; quotation expiry is computed, not notified; no credit notes and returns are not netted against invoices; no e-Faktur number or export; the PPN rule, rounding and payment terms are configurable assumptions to be verified by a tax adviser; historical delivery notes use the customer's current address and have no vehicle/driver; the SO, quotation and delivery-note timeline integration was not added (documents link to each other instead); the class diagram was not updated in this stage.
