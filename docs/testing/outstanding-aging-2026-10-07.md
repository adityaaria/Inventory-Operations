# Outstanding & aging report acceptance — 7 October 2026

Update (same day): completed target #4 by adding stock proposals (OP) awaiting approval/posting, document and age-bucket filters and the `OutstandingCriteria` value object. Figures below are from the final run.

Requirement IDs: REPORT-01, DASH-01, AUTH-01/02, ARCH-01/02, DB-01, TEST-01/02/03. Mandatory/Optional: optional reporting enhancement authorized by user continuation (ADR-012).

Files changed: OperationalQueryRepositoryInterface (scope constants, age buckets, three outstanding methods); MySqlOperationalQueryRepository (prepared outstanding source, shared unbuffered `streamRows` helper); InMemoryOperationalQueryRepository; ReportService (role scope, metrics, CSV); ReportController (`outstanding` CSV action, generic export URL); public/index.php route; views/reports/index.php; tests/Unit/ReportPreviewTest.php; tests/Integration/OutstandingReportIntegrationTest.php; tests/HTTP/outstanding-report.py; ADR-012, SDD, KNOWLEDGE, DECISIONS_PENDING (D-07), AI usage log.

Behavior implemented: `/reports?type=outstanding` with cards (documents, open over 30 days, oldest age, PO units awaiting receipt, SO units awaiting issue; Sales sees own-order equivalents), charts by age bucket and by type/status, oldest-first ten-row preview, created-date filters and streamed `/reports/outstanding.csv`. Page states age is days since creation, not a due date/SLA/lateness.

Security/authorization: scope is chosen in ReportService from AuthContext only, never from request input. Sales owner bound as a prepared parameter; Warehouse limited to fulfilment work; unauthenticated page/CSV requests denied. CSV keeps formula-injection escaping.

Transaction/invariants: read-only; no schema, stock, ledger, transaction or status change. Closed PO remainders and terminal statuses excluded. Summary, page and CSV share one source query per scope.

Tests added/updated:
- Unit: role→scope/owner mapping for all three roles (mocked repository, no fallback to legacy report queries); metrics keep inbound/outbound units separate and count 31+ day documents; outstanding CSV streams scoped rows with age columns and escaped cells.
- MySQL integration: Admin total equals independent open-document count and CSV; closing a PartiallyReceived remainder removes it; Warehouse rows only PO Ordered/PartiallyReceived and SO Approved, with PO outstanding quantity equal to Σ(ordered − received); Sales rows owned only; documents aged 45/70 days land in 31–60/61+ buckets, sort oldest first, and creation-date filter isolates the 45-day document.
- MySQL integration (OP/filters): pending and approved proposals listed for Admin, only approved for Warehouse, posted/rejected excluded, no unit total; document+age filter isolates the 40-day proposal in preview and CSV.
- Unit: Sales cannot request PO/OP; unknown document/age and array input rejected before any query, on both preview and CSV.
- HTTP: 21 checks on a fresh disposable stack, including filter persistence in page links and CSV.

Commands executed + result:
- `docker compose --profile quality run --build --rm test` — **393 PHP tests / 1823 assertions OK**, PHPStan level 5 **no errors**, **54 JavaScript tests passed**. [Quality log](outstanding-aging-2026-10-07/quality.txt).
- `python3 tests/HTTP/outstanding-report.py --url http://localhost:18091` against disposable project `inventory-e2e-outstanding` — **21 checks passed** (Admin 18, Warehouse 7, Sales 3 open documents on seed data, which holds no stock proposals; OP behavior is proven by the MySQL test that inserts PendingApproval/Approved/Posted/Rejected proposals). [HTTP result](outstanding-aging-2026-10-07/http.json). Project and volumes removed afterwards.
- `git diff --check` on changed paths — passed.

Not run: browser/Chrome-emulation layout checks for the new columns at 360/390/768/1440 and physical devices. The table reuses the existing table-local scroll container, but responsive behavior for this page was not re-verified. The localhost:8080 runtime was **not** rebuilt; it still serves the previous image.

Known gaps/risks: age is not time in current status (no status-entry timestamp exists for every transition); bucket bounds and Warehouse focus are temporary defaults (D-07). No due date/SLA, so no overdue flag.

Recommended next task: browser layout check of the report at 360px, then activate on localhost:8080 once you approve the rebuild.
