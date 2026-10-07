# Reports enhancement — 2026-10-06

Requirements: REPORT-01, UI-01, FIND-01, ERR-01. Dashboard visual components reused (DASH-01 design reference); no changes to Dashboard business behavior. Native PHP OOP, Controller → Service → repository boundary, PDO native prepared statements, custom CSS/Vanilla JS retained.

Behavior:
- Reports becomes a dashboard-style analytical page: four dataset summary cards and two charts for the selected report.
- Order Status: total PO/SO, completed orders and status/type breakdown. Sales sees own order summary (open/completed/cancelled), with creator scope taken from authenticated identity.
- Stock Movements: movement count, received/issued quantities, net quantities and movement/warehouse breakdown. Warehouse defaults to stock reporting.
- Report type + from/to GET filters, explicit all-date default, 10-row detail pagination, filter-preserving links, empty/error states. Filters/table/pagination have no vertical gaps.
- Export CSV lives in the page header and includes the whole filtered dataset, independently of pagination. Tables JS skips page-only generated exports for data-export=server but retains existing sorting enhancement.
- Initial page, metrics and charts recap the same role/date-scoped repository rows used by CSV endpoints.

Query fixes:
- Global PO/SO UNION filters applied once outside the union so native PDO placeholders are not duplicated; deterministic Date/Type/ID ordering.
- Ledger date predicates qualify sl.created_at to avoid joined-table ambiguity; end date uses an exclusive next-day boundary to include the entire selected day.
- Real calendar validation (including invalid February dates), reversed-range rejection and malformed array query rejection; invalid filters return 422 with no misleading export.

Security/transaction invariants:
- Preview requires authentication. Sales stock preview denied in service, stock export denied server-side as before; own-order ID comes from session, not user query.
- Existing Warehouse order-export capability retained; default presentation is stock-oriented. No new role/status, schema, dependency, stock write or transaction introduced. HTML escaped and CSV formula protection preserved. New integration tests only read existing database data.

Changed files:
- app/Controller/ReportController.php
- app/Service/ReportService.php
- app/Repository/MySql/MySqlOperationalQueryRepository.php
- views/reports/index.php
- public/assets/css/app.css; public/assets/js/tables.js
- tests/Unit/ReportPreviewTest.php; tests/Integration/ReportQueriesIntegrationTest.php
- tests/JavaScript/tables.test.js; view-components.test.js
- SDD.md; KNOWLEDGE.md; ai-usage-log.md; docs/quality/design-system.md; this report/screenshots.

Commands and actual results:
- node --test tests/JavaScript/*.test.js: 46 passed.
- Docker phpunit --filter ReportPreviewTest|ReportServiceTest|ReportQueriesIntegrationTest: 17 tests / 68 assertions passed.
- Docker composer test: all 265 tests / 1109 assertions passed.
- Docker PHPStan level 5 app config public: 110 files, no errors; pre-existing dependency-age advisory.
- Docker Compose app rebuild/restart passed.
- git diff --check passed.

Browser evidence:
- Chrome: Admin/Sales/Warehouse at 1440px and 360px; 10 combinations of role, type and viewport.
- Metrics/chart totals match preview total and full exported CSV count; row count <=10. Filter values and pagination links retain type/from/to.
- Server-export link only, no generated duplicate CSV button. Zero filter/table/pagination gaps. No horizontal document overflow or runtime exceptions.
- Sales stock preview and CSV return 403; stock option absent.
- Empty date range renders 0 metrics, empty table and both chart empty states.
- Six malformed/calendar/reversed requests return 422, including CSV invalid date.
- Four original screenshots captured; desktop orders and mobile ledger visually reviewed.

Evidence: screenshots/reports/browser-results.json, orders-1440.png, orders-360.png, stock-ledger-1440.png, stock-ledger-360.png. These show the current local dataset, including existing integration-fixture records, rather than hardcoded statistics.

Known limits: report aggregation currently loads the filtered rows in memory before slicing the preview, consistent with the existing CSV path. Large production datasets would benefit from SQL aggregation and paged detail queries/streamed exports. Table sorting applies to the visible page; no new global sorting requirement inferred. There is no inventory snapshot valuation claim in date-filtered reports.

## Status badge follow-up

UI-01 / REPORT-01: order report Status cells now use the existing order badges: Draft neutral; Ordered/PartiallyReceived/PendingApproval warning; Approved/Received/Fulfilled success; Cancelled danger. Label text and CSV contents remain unchanged and escaped. No persistence/authorization changes.

Changed: views/reports/index.php and documentation/AI log. Existing tests were sufficient; no new mirrored UI test. Unit run first caught a view variable name colliding with the controller HTTP status; renamed it to reportRowStatus and reran successfully. Final checks: 243 unit tests / 702 assertions passed; 46 JS tests passed; PHPStan level 5 no errors; view syntax passed. App view synchronized to running Docker container. Integration not rerun because persistence unchanged.
