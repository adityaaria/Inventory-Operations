# Inventory & Order Management System

Native PHP 8.2+ inventory and order management system for the final project assessment.

## Stack

- PHP 8.2+ native OOP.
- MySQL 8 with PDO prepared statements.
- HTML, custom CSS, Vanilla JavaScript.
- Docker Compose.
- PHPUnit and PHPStan level 5.

No Laravel, CodeIgniter, Symfony, Slim, ORM, frontend framework, jQuery, CSS framework, or admin template is used.

## Setup

Start the application and database:

```bash
docker compose up -d --build app
```

Open:

```text
http://localhost:8080
```

If host port `8080` is already used locally, override the app port:

```bash
APP_PORT=8082 docker compose up -d --build app
```

Open:

```text
http://localhost:8082
```

If host MySQL port `3306` is already used and you need a second clean Compose project, override the DB host port too:

```bash
COMPOSE_PROJECT_NAME=rudis_phase8 APP_PORT=8083 DB_HOST_PORT=3307 docker compose up -d --build app
```

The `db` service loads `database/schema-and-seed.sql` on first volume initialization.

## Demo Data (FMCG)

The seed is a fictional FMCG distributor with realistic-looking but invented brands, suppliers and outlets:
- **Locations and catalogue:** 3 warehouses (Cikarang, Surabaya, Makassar), 12 categories, 500 SKUs (for example `MIE-0001`, `MIN-0038`), 15 principals and 66 retail outlets.
- **Documents:** 400 purchase orders and 600 sales orders dated 10 April to 8 October 2026, with matching stock ledger and audit history.
- **Stock:** every quantity comes from receipts and issues run through `StockService`, and stock equals the ledger for every product/warehouse pair.
- **Units (ADR-022):** stock is kept in the smallest unit (`pcs`, or `pak` for diapers sold by the `bal`). Products also have `karton`/`bal` (purchase and default sales unit, own prices) and, where it fits, `box` (12 pcs) or `renceng` (10 pcs); every unit has an EAN-13-style barcode. POs are in cartons; wholesale outlets buy cartons, minimarkets and shops buy pieces or boxes.

To rebuild it (deterministic), run `python3 scripts/build-fmcg-seed.py`. It runs `scripts/generate-fmcg-seed.php` in the quality test container against the disposable test database and rewrites the generated section of the seed file.

A database volume only loads the seed when it is first created. To switch an existing local database to a new seed, back it up, remove the `mysql-data` volume and start the stack again. This deletes the local data.

## Demo Credentials

These are fake local assessment credentials from the seed file. Every seeded user has the password `password`.

| Role | Email | Scope in the seed |
|---|---|---|
| Admin | `admin@example.test` | All documents |
| Sales | `sales1@example.test` | Own sales orders, Cikarang region |
| Sales | `sales2@example.test` | Own sales orders, Surabaya region |
| Warehouse Staff | `warehouse1@example.test` | Cikarang receipts and issues |
| Warehouse Staff | `warehouse2@example.test` | Surabaya receipts and issues |

Named staff accounts (for example `rina.kartika@sumberniaga.test`, `yusuf.hidayat@sumberniaga.test`) appear as document creators and approvers.

## Quality Checks

```bash
docker compose --profile quality run --build --rm test
```

This builds a PHP 8.3/Node test image, runs PHPUnit, PHPStan level 5 and JavaScript tests, and resets a dedicated MySQL test database before and after the checks. The `test-db` service has no host port and uses disposable storage. Integration connections refuse database names that do not end in `_test`; application data is not a test fixture. Run one quality job at a time per Compose project.

For native development, PHP 8.2+ and Node are required. Install dependencies with `composer install`. `composer test:unit` and `composer analyse` do not need MySQL; full/integration suites require a separately seeded `_test` database and its environment variables. Use the Docker command above on workstations running older PHP.

Low-stock command (pairs below the effective reorder point; overrides are marked `(warehouse override)`):

```bash
docker compose exec -T app php scripts/check-low-stock.php
```

Per-warehouse reorder point (ADR-016, user decision D-10): Admin sets or clears it on the product detail page under "Stock by Warehouse"; empty uses the product default. For an existing database, back up first and run `docker compose run --rm --no-deps app php scripts/migrate-reorder-point-override.php` with the newly built image before activating it (additive, idempotent).

Order numbers (ADR-017, user decision D-11): the server assigns `PO-YYMM-NNNN` / `SO-YYMM-NNNN` when a draft is saved (YYMM in UTC, the same clock as the order date); the create forms have no order-number field. For an existing database, back up first and run `docker compose run --rm --no-deps app php scripts/migrate-order-number-sequences.php` with the newly built image before activating it (additive, idempotent; existing numbers are not changed).

Stock reservation (ADR-018, user decision D-12): Admin approval of a sales order reserves its lines at the source warehouse and is refused with "Insufficient available stock" when on hand minus already reserved stock is short; Issue Goods consumes the reservation and cancelling an Approved order releases it. Product pages and the availability API show on hand, reserved and available; low stock and replenishment use available. For an existing database, back up first and run `docker compose run --rm --no-deps app php scripts/migrate-stock-reservation.php` with the newly built image before activating it (additive, repeatable). If Approved orders already need more stock than is on hand, it reserves nothing, lists those orders and exits 2: resolve them, then run it again.

Negotiated line prices (ADR-019, user decision D-13): PO/SO lines start at the product master price and may be changed with a reason (3–255 characters). A sales line may not be below the product's purchase price, and Sales may discount at most `SALES_MAX_DISCOUNT_PERCENT` percent below master (default `10`, whole number 0–100, set in `.env` / Compose; Admin has no cap). PO and SO detail pages show the master price, the difference and the reason, with a notice before approval/ordering. For an existing database, back up first and run `docker compose run --rm --no-deps app php scripts/migrate-negotiated-prices.php` with the newly built image before activating it (additive, repeatable; existing lines get their current price as list price).

Partial sales order fulfilment (ADR-020, user decision D-14; adds the SO status `PartiallyFulfilled`, which is not in the brief's status set): on an Approved or PartiallyFulfilled order, Warehouse/Admin enter an "Issue Now" quantity per line (prefilled with the remaining quantity, 0 skips a line) and press Issue Goods; each shipment lowers stock and the reservation and writes one Issue ledger row per line. The detail page shows Ordered / Issued / Remaining, the shipment history and any closure. Admin can "Close Remaining Fulfilment" of a PartiallyFulfilled order with a reason: the unshipped remainder is released from the reservation and no further issue is accepted. A PartiallyFulfilled order cannot be cancelled. For an existing database, back up first and run `docker compose run --rm --no-deps app php scripts/migrate-partial-fulfilment.php` with the newly built image before activating it (additive, repeatable; existing Fulfilled lines get issued quantity = ordered quantity). It exits 2 with a report, without recording the schema version, if issued quantities do not match the Issue ledger.

Transfers with goods in transit (ADR-021, user decision D-15; adds the operation-document statuses `Dispatched` and `Received` for transfers, not PO/SO statuses): after independent approval, Warehouse/Admin press **Dispatch Transfer** — the goods leave the source warehouse (available stock only, reservations are kept) and are in transit. The destination opens the transfer (work queue "Receive transfer" or the **In Transit** page, `/inventory-operations/in-transit`) and enters good and damaged units per line; anything missing is short. Only good units enter stock; damaged and short units are written off and need a reason. A dispatched transfer cannot be cancelled. Product detail shows "In Transit (inbound)" per warehouse (never part of available), and replenishment subtracts goods in transit. For an existing database, back up first and run `docker compose run --rm --no-deps app php scripts/migrate-transit-transfer.php` with the newly built image before activating it (additive, repeatable; existing Posted transfers become Received, Approved ones stay Approved, stock and ledger unchanged). It exits 2 with a report, without recording the schema version, if transfer lines do not match their ledger rows.

Units of measure (ADR-022, user decision D-16): every stock quantity is stored in the product's base unit (the smallest unit, e.g. pcs); quantities are shown with a breakdown such as "2 karton + 5 pcs (101 pcs)". Admin manages a product's units on its detail page (**Units of Measure**: add a unit with its factor, barcode and optional own prices per unit, set the purchase/sales default, deactivate); a unit already used on a document keeps its code and factor — deactivate it and add a new one. PO/SO lines and stock operations choose a unit per line; prices are per that unit (a unit without its own price costs base price × factor), and receipts and shipments are entered in whole line units. Replenishment suggests whole purchase units, rounded up. The availability API adds `base_unit` and `units`; its existing fields stay in base units. CSV product import is unchanged (`unit` becomes the base unit). For an existing database, back up first, then with the new image run `docker compose run --rm --no-deps app php scripts/migrate-multi-unit.php --dry-run` (read-only report) and, if it is clean, the same command without `--dry-run` while the app is stopped. Products whose name ends with "(N pcs)" or "(N pak)" move to that base unit (all quantities × N, the old unit kept as a unit with the old prices); other products keep their unit as the base. The run is one transaction, re-checks every invariant, the stock value (within rounding) and every PO/SO line total before committing, and is a no-op when repeated.

Batches, expiry and FEFO (ADR-023, user decision D-17): every unit belongs to a lot with a code, an optional expiry date and a unit cost. Products of food and beverage categories track expiry (checkbox on the product form). When receiving a PO line, enter one or more lots (code, expiry, quantity) on the PO detail page — **Add another lot** splits a delivery; the quantities must add up. Sales orders ship the earliest-expiring non-expired lot first (FEFO, automatic); expired lots are never sold, shipped or transferred and do not count as available, so low stock and replenishment ignore them. **Expiry** (sidebar, Admin/Warehouse) lists near-expiry (default 30 days, `NEAR_EXPIRY_DAYS`) and expired lots with CSV export, reservations that now exceed the non-expired stock, and a form to propose writing off a warehouse's expired lots (another Admin approves, then it is posted like any adjustment). **Lot Trace** finds a lot code and shows its receipt (PO, supplier), transfers, shipments (SO, customer) and returns. Adjustments count one lot per line (or a new lot found at the count). "Today" for expiry is the date in `APP_TIMEZONE` (default Asia/Jakarta). For an existing database, back up first, then with the new image run `docker compose run --rm --no-deps app php scripts/migrate-batch-expiry.php --dry-run` (read-only report) and, if clean, the same command without `--dry-run`: existing stock becomes one `LEGACY` lot per product and warehouse (no expiry, current purchase price), food and beverage products start tracking expiry, earlier ledger rows keep no lot; the run is one transaction, re-checks every invariant and is a no-op when repeated.

Barcodes and stock counts (ADR-024, user decision D-18): on a PO or SO detail page, scan unit barcodes with a USB/Bluetooth scanner (it types the code and Enter) — **Scan to receive** adds one line unit to the matching receive line, **Start scan to pick** counts picked units and fills the issue quantities; a camera button appears only in browsers with `BarcodeDetector`. The JSON lookup is `GET /api/barcodes/{code}` (Admin/Warehouse). **Stock Counts** (sidebar): an Admin creates a count (warehouse, all products, one category or selected products, counters, reason) and starts it (snapshot); the assigned counters count blind on the sheet (scan or type quantities in any unit, add found lots) and submit; an Admin who did not count reviews expected/counted/variance/value, may request recounts (above `COUNT_RECOUNT_TOLERANCE_PERCENT`, default 2, or `COUNT_RECOUNT_TOLERANCE_UNITS`; done by another counter), approves, and Admin/Warehouse posts: each variance is added to the current lot quantity, so movements during the count are kept. **Count Report** (`/reports/stock-counts`, CSV) summarizes posted variances. For an existing database, back up first, then run `docker compose run --rm --no-deps app php scripts/migrate-stock-counts.php --dry-run` and the same command without `--dry-run` (additive tables only; re-running is a no-op).

Price lists, quotations, delivery notes and invoices (ADR-025, user decision D-19): **Price Lists** (Admin, sidebar) hold quantity tiers per product and unit (for example karton 16.500 from 1, 16.000 from 10); assign a customer on the list page. A new SO or quotation line takes the customer's tier for its unit and quantity (else the master price): the form shows the list price and its source and updates it when the quantity crosses a tier (`GET /api/price-resolution`); a different price still needs a reason, and the Sales cap and cost floor apply to the list price. **Quotations** (Admin, Sales own): Draft → Sent → Accepted/Rejected, Cancelled; shown as Expired after *valid until*; an accepted, valid quotation converts once into an SO draft at the quoted prices; printable. Every shipment (Issue Goods, optionally with vehicle and driver) creates a **Delivery Note** / surat jalan `DO-YYMM-NNNN` with the lots used, printable from the SO page or **Delivery Notes**. Admin creates one **Invoice** `INV-YYMM-NNNN` per delivery note, records payments (no overpayment; status Unpaid/PartiallyPaid/Paid computed, Overdue after the due date) and may void an unpaid invoice with a reason; Sales see their own orders' invoices. **Receivables Aging** and **Sales by Customer** reports (Admin, CSV) and an Admin dashboard card show outstanding receivables. PPN settings (assumptions to be verified by a tax adviser): `VAT_RATE_PERCENT` (default 12), `VAT_DPP_NUMERATOR`/`VAT_DPP_DENOMINATOR` (default 11/12, i.e. effective 11 %), DPP and PPN rounded to whole Rupiah per invoice; `INVOICE_PAYMENT_TERMS_DAYS` (default 30) sets the due date; invalid values stop the app at boot; each invoice keeps a snapshot of the settings. Customer NPWP (15/16 digits) / NIK and tax name/address are optional on the customer form. For an existing database, back up first, stop the app, run `docker compose run --rm app php scripts/migrate-sales-documents.php --dry-run` and the same without `--dry-run` with the newly built image (additive; creates one delivery note per historical shipment, no invoices; re-running is a no-op), then `docker compose up -d --build app`.

Inventory valuation (ADR-026, user decision D-20): stock is valued **FIFO per batch** — every lot keeps the unit cost it was received at, every movement records its cost (quantity × lot cost), and a shipment costs the lots FEFO actually took. **Valuation** (Admin, sidebar) shows the value on hand by warehouse, category, product or lot, now or as of a past date, plus goods in transit at their lot cost (CSV). From there: **Reconciliation** (opening + receipts + transfers ± adjustments and counts − issues − write-offs − transit losses = closing, per warehouse, to the cent), **Gross Margin** (invoice net revenue excl. PPN − cost of the shipped lots, per customer, product or invoice line; void invoices excluded; shipments not yet invoiced listed apart) and **Losses** (expired write-offs, transit damage/shortage, count shortfalls at cost), each with CSV. The Admin dashboard's Inventory Value is now lots at cost + in transit (the old "at master purchase price" figure is shown as a reference); SO, delivery-note and invoice pages show COGS and margin to Admin only. Sales and Warehouse get 403 on these reports. Movements recorded before the lot migration have no cost ("before valuation start"). For an existing database, back up first, build the new image, run `docker compose run --rm -T app php scripts/migrate-inventory-valuation.php --dry-run` (rows to backfill and value totals), stop the app, run it without `--dry-run` (one transaction; adds the cost columns, backfills every row that has a lot from its lot, re-checks every invariant, then adds the CHECK; re-running is a no-op), then `docker compose up -d --build app`.

PHPUnit line coverage (`app/` + `views/`) is 99.42% with 592 tests; the command, HTML report location and the 33 unreachable lines are in `docs/testing/coverage-2026-10-09.md`.

Static analysis evidence is stored in `docs/quality/phpstan-report.txt`. Test evidence is stored in `docs/testing/test-results.md` and `docs/testing/test-scenarios.md`.

### SonarQube

Configuration is in `sonar-project.properties`. Generate coverage, run a local SonarQube and scan:

```bash
mkdir -p var/coverage && chmod 777 var/coverage
docker compose --profile quality run --build --rm -v "$PWD/var/coverage:/var/www/html/var/coverage" test php scripts/coverage-report.php
node --test --experimental-test-coverage --test-reporter=lcov --test-reporter-destination=var/coverage/lcov.info tests/JavaScript/*.test.js
SONAR_TOKEN=<token> sonar-scanner -Dsonar.host.url=http://127.0.0.1:9100
```

Latest result (7 October 2026): Quality Gate passed; Security, Reliability and Maintainability rated A; 0 hotspots; coverage 69.9% (PHP and templates 79.4%). Full report, reviewed issues and the local SonarQube container command: `docs/quality/sonarqube.md`.

## Environment

Copy `.env.example` to `.env` for local development. Keep `.env` out of source control.

The checked-in `.env.example` uses placeholder-only local values:

```bash
cp .env.example .env
```

## Evidence

- Architecture diagrams and ADRs: `docs/architecture/` (as-built class diagram updated 7 October 2026).
- Database ERD: `database/erd.dbml` (21 tables, matches `database/schema-and-seed.sql`).
- Refactoring log: `docs/quality/refactor-log.md`; SonarQube report: `docs/quality/sonarqube.md`.
- Test scenarios and results: `docs/testing/`.
- Quality evidence: `docs/quality/`.
- Planning and release readiness: `docs/planning/`.
- AI usage log: `ai-usage-log.md`.

## Current behavior and evidence

- Product detail is readable by authenticated roles; edit remains Admin-only.
- PO/SO transitions lock the source order before stock rows. Stock, ledger, final status and required movement audit commit or roll back together.
- Authentication guards re-read current role/active state. Deactivated users lose access on their next authenticated request.
- CSV imports are atomic; a failed row rolls back the entire file. List exports are labeled **Export Current Page**; Reports exports all authorized filtered rows through streaming CSV. Both paths neutralize formula-leading cells.
- Sort links for supported Product/PO/SO columns apply to the full filtered result and retain filter state. Other table headings explicitly sort the current page.
- Audit/remediation evidence: `docs/quality/project-audit-2026-10-06.md` and `docs/testing/audit-remediation-2026-10-06.md`.

Git is initialized and history is available. Existing evidence about unavailable Git is historical. Physical iOS/Android/Safari and soft-keyboard checks still need actual devices; Chrome emulation is not equivalent. Host port overrides remain available as documented above.

### Session lifecycle

Default limits: idle 30 minutes, absolute 8 hours, ID rotation every 15 minutes. Configure `SESSION_IDLE_SECONDS`, `SESSION_ABSOLUTE_SECONDS`, `SESSION_ROTATION_SECONDS` and `SESSION_COOKIE_SECURE` using `.env.example`. Limits must be positive, idle ≤ absolute, rotation ≤ absolute; invalid configuration fails closed. Local HTTP uses `auto`; production uses `APP_ENV=production` and requires HTTPS/Secure cookies. For trusted TLS termination set `SESSION_COOKIE_SECURE=true`; forwarded headers are not trusted automatically.

The Compose `session-data` volume preserves sessions across app container recreation. Existing sessions from the previous implementation require a fresh login once. Logout clears the current session; deactivation/password-hash changes revoke affected sessions on their next request. Concurrent requests carrying an ID just rotated receive authentication failure; failed mutations are never replayed automatically. See [ADR-005](docs/architecture/ADR-005-session-lifecycle.md) and [module alignment](docs/quality/training-module-alignment.md) for decisions and deployment limits.

HTTP smoke checks (Python standard library): `python3 tests/HTTP/session-smoke.py --url http://localhost:8080`. `--short-timeouts` requires a separate target configured with idle=4, absolute=10, rotation=1 seconds and separate session storage; never shorten the normal app settings for testing. Native adapter lifecycle tests belong to the integration suite; SessionPolicy and guard/response tests run without native sessions or SQL.

### End-to-end checks and opening balances

Product and warehouse creation now initializes all product/warehouse stock pairs at zero, in the same transaction. Opening zero balances create no receipt/issue ledger movement. Product/warehouse CSV imports retain that atomic boundary. To fill historical missing pairs safely, run `docker compose exec app php scripts/initialize-stock-balances.php`; it preserves existing quantities and ledger. Do not replay schema/seed against an existing application database.

Repeatable full HTTP workflows require a disposable Compose project named `inventory-e2e-*` and its own app/DB ports. Example: `APP_PORT=18085 DB_HOST_PORT=13308 docker compose -p inventory-e2e-verify up -d --build app`, then `python3 tests/HTTP/end-to-end.py --project inventory-e2e-verify --url http://localhost:18085 --output /tmp/inventory-e2e-results.json`. The script checks project labels/port mappings before writes and uses read-only SQL to verify states, balances, ledger/audits and rollback. Never point it at a project containing real data.

Browser regression sources are `tests/Browser/end-to-end-mobile.cjs` and `end-to-end-dialog.cjs`; they require a local Chrome CDP endpoint on 9223 and the disposable app (default :18085, override E2E_URL). Each script closes its browser when finished. Current evidence and exact offline-build limitations are in `docs/testing/end-to-end-verification-2026-10-07.md`.

Offline verification support: `tests/Support/Dockerfile.offline-verification` and `Dockerfile.offline-runtime` reuse existing locally verified images. Use this fallback only with matching `composer.lock`; it does not establish a fresh internet dependency build. Results and limitations: [end-to-end verification](docs/testing/end-to-end-verification-2026-10-07.md).

## Operational enhancements

Production uses the standalone `compose.production.yaml` (Nginx, PHP-FPM, TLS, internal MySQL, persistent sessions/logs). Backup/restore tooling rejects in-place overwrite; restore a fresh target and verify it before promotion. Monitoring emits local JSON alerts; CI includes quality, production builds and isolated recovery/HTTPS/load verification. Follow the [operations runbook](docs/operations/runbook.md), [ADR-007](docs/architecture/ADR-007-operational-readiness.md), [Android/iPhone acceptance checklist](docs/operations/device-acceptance.md), and [pending business decisions](docs/planning/DECISIONS_PENDING.md). Backups, TLS keys and production environment files must remain outside Git.

### Optional business operations

Admin/WarehouseStaff can access `/inventory-operations` and `/replenishment`. Propose a count, direct warehouse transfer or original-movement return; a different Admin reviews it, then WarehouseStaff/Admin posts. An Admin proposer needs another Admin reviewer. Customer returns require goods-fit confirmation. PO details allow Admin to close remaining partial supply; SO details allow reasoned rejection. Recommendations prefill a PO for review. See [ADR-009](docs/architecture/ADR-009-business-operations.md).

For an existing database: back up first, run `docker compose run --rm --no-deps app php scripts/migrate-business-operations.php` using the newly built image, then activate that image and verify readiness. This additive migration does not replay seeds. Older backups require migration after guarded restore and before activation. New schema backups contain all 21 tables.

### Work queue

Open `/work-queue` for current tasks by role. Admin sees reviews and stock tasks, Warehouse receipts/issues/posting, Sales own drafts/order progress. Filter and open the original document to act. Age means days since creation; the queue does not reserve stock. See [ADR-010](docs/architecture/ADR-010-role-work-queue.md).

### Outstanding and aging report

`/reports?type=outstanding` lists open documents by role: Admin sees open PO, SO and stock proposals; Warehouse sees receipts, issues and approved proposals awaiting action; Sales sees own open SO. Age is whole days since creation in 0–2/3–7/8–30/31+ buckets, with days since approval for approved SO and proposals. It is not an SLA or lateness measure. Filters (created date, document type, age) apply to the preview and the CSV export.

### Multi-item orders, replenishment selection and form drafts

PO and SO create accept up to 100 distinct product lines. On `/replenishment`, choose one warehouse, tick recommendations (selection is kept while paging) and open one prefilled PO; supplier, quantities and prices are reviewed before saving. Long create forms keep a browser-local draft per user and role for the session lifetime (8 hours by default); restoring a draft rechecks permission, master-data status and current stock. List pages sort by clicking column-header arrows.

### Transaction timeline

Use Transaction Timeline from PO/SO/Stock Operations details to inspect recorded actions, ledger and linked returns/original orders. Latest 100 events shown; missing old events are not reconstructed. Sales has own-SO visibility only. See [ADR-011](docs/architecture/ADR-011-document-timeline.md).
