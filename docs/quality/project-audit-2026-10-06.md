# Project audit — 6 October 2026

Scope: current working tree, including uncommitted enhancements. Audit only; no application fixes. Authority: official `Project Brief - Programmer.pdf`, AGENTS.md, SDD.md, KNOWLEDGE.md, then implementation/evidence. Source references below use repository paths and line numbers.

## Remediation status — 7 October 2026

A01–A12 code/tooling fixes are implemented and regression-tested. A13's obsolete drawer/header CSS is consolidated and browser checks pass; physical iOS/Android/Safari/keyboard evidence remains pending. Original findings and reproductions below are historical, retained for traceability. See `../testing/audit-remediation-2026-10-06.md` for exact closure decisions, final test output, browser/clean-start evidence and remaining limitations. The old probe source/JSON records the original failures; current PHPUnit regression tests specify the fixed outcomes.

## Conclusion

The native PHP/PDO/custom CSS/Vanilla JS architecture remains aligned with the required stack. Existing tests pass, but they do not prove the absence of important gaps. Three high-priority findings concern duplicate goods issue, stale authorization, and integration-test isolation. Address those before further optional enhancements.

Severity: High = inventory/access/data safety; Medium = required behavior, validation, reliability, or data correctness; Low = maintenance/evidence debt. Runtime probes below are controlled interleavings, not simultaneous processes. Production deployment and physical mobile-device testing are outside this audit.

## Findings

### A01 — High: the same SO can be issued twice

Requirements: SO-01, ARCH-02; AGENTS stock safety.

Evidence: `app/Service/SalesOrderService.php:78` reads and validates Approved before `StockService` begins its transaction (`app/Service/StockService.php:98`). Stock rows are locked, but the source order is neither locked nor revalidated in that transaction. `app/Repository/MySql/MySqlSalesOrderRepository.php:140` unconditionally writes Fulfilled.

Confirmed probe: request A validates Approved, request B completes issue, request A then starts its stock transaction. For an SO ordering one unit, stock decreases by **two** and two Issue ledger rows are created. Stock remains nonnegative when sufficient inventory exists, but fulfillment and inventory are incorrect. Stock locking alone does not prevent duplicate processing.

Remediation: lock and validate source order within the same transaction as stock/ledger, use one consistent lock order for all workflow operations, and make status transitions conditional. Add duplicate-issue/interleaved issue-versus-cancel regression tests. Idempotency keys may supplement this but do not replace atomic state validation.

### A02 — High: deactivation and role changes do not revoke existing access

Requirements: AUTH-01, USR-01, server authorization.

Evidence: `app/Security/NativeSessionManager.php:28` returns the role stored at login; `app/Security/AuthGuard.php:15` trusts it without consulting current user state. `UserService` can update role/deactivate a user, but there is no session-version/revalidation mechanism.

Confirmed fake-repository probe using actual AuthService/AuthGuard: log in as Admin, change the user to Sales and inactive, then `requireUserManagement()` still returns Admin. New login is blocked correctly; the existing session retains access.

Remediation: resolve current active user/role during authenticated requests or enforce a revocable session version. Test deactivation and role downgrade against an already authenticated session.

### A03 — High: integration tests default to the application database and leave mutations

Requirements: TEST-02, DB-01, ledger integrity.

Evidence: `tests/Integration/SalesOrderIssueIntegrationTest.php:32` and PO receipt tests default to `inventory_order_management`, matching Compose. The SO test directly replaces seeded stock (`:87`) and does not restore it; PO receipt tests create orders and commit inventory/ledger changes without cleanup. Other tests do have rollback/cleanup, so this finding is not a claim that every test leaks data.

Impact: ordinary documented integration/full-test commands can change demo/dashboard data and seeded stock. Direct test stock setup also breaks opening-stock-plus-ledger reconciliation if run against the application dataset.

Remediation: dedicated test database/project, a fail-closed guard refusing the application database, deterministic reseeding/cleanup, and one documented repeatable test command. This audit ran the 22 integration tests in a separate database and removed it afterward.

### A04 — Medium: concurrent partial receipts produce stale PO status

Requirements: PO-01, ARCH-02.

Evidence: `app/Service/PurchaseOrderService.php:62` loads remaining quantities before the transaction and computes final status from that snapshot. `app/Repository/MySql/MySqlPurchaseOrderRepository.php:117` adds quantities and sets the supplied status without re-reading current receipt state.

Confirmed probe: two interleaved receipts of five against a PO quantity ten result in received_quantity=10 but status=PartiallyReceived. The schema CHECK received_quantity <= quantity protects against excess quantity by rolling back; this audit does **not** claim that constraint can be bypassed.

Remediation: lock the PO/items and calculate remaining quantities/status inside the stock transaction. Include receipt-versus-cancel and final concurrent receipt tests.

### A05 — Medium: required read-only product detail page is missing

Requirement: VIEW-01 explicitly calls for product, PO, and SO lists **and detail pages** according to role.

Evidence: `public/index.php:187` registers product index/create/edit and mutation routes, but no product show route; ProductController has no show action. Edit is Admin-only and cannot replace read-only detail for Sales/Warehouse. Stock details in the product list are useful but do not implement the specified detail page. PO/SO detail pages exist.

Remediation: read-only product detail with authorized access, category/prices/status, total stock and per-warehouse breakdown, plus 404/role coverage. The older brief-coverage audit incorrectly labels VIEW-01 fully covered.

### A06 — Medium: numeric values are cast before validation; persistence failures are not consistently mapped

Requirements: PRD-01, VAL-01, ERR-01.

Evidence: `app/Controller/ProductController.php:59` casts prices/reorder before service validation. `ProductService::assertValid()` only sees the resulting numeric values. `InputValidator` has stricter numeric functions, but this path does not use them.

Confirmed probe: purchase_price=abc, selling_price=abc, reorder_point=2.9 produces HTTP302 and stores 0.00, 0.00, 2. This is acceptance of malformed input, not valid zero-price handling.

Additional code evidence: duplicate/FK/overlength persistence errors can escape regular store handlers that only catch InvalidArgumentException; imports catch Throwable and display getMessage(), including potential database details. Scalar/array validation is also inconsistent outside the recently hardened Reports controller.

Remediation: validate raw scalar values, integer/money syntax and lengths before casts; translate expected uniqueness/FK failures to safe field errors; keep unexpected exception details in logs.

### A07 — Medium: frontend CSV export does not neutralize spreadsheet formulas

Requirements: safe export, validation/security polish; optional frontend export feature.

Evidence: `public/assets/js/ui-helpers.js:35` only quotes/escapes CSV text. Actual helper output for =1+1 is `"=1+1"`; quotes do not remove formula semantics. `tables.js:118` exports user-controlled table text. Server ReportService already prefixes formula-leading values, so the two export paths have different protection.

Impact: a formula-leading product/customer/supplier name can reach a spreadsheet through browser export. This verifies unsafe serialization, not execution in a particular spreadsheet application.

Remediation: common documented CSV neutralization policy for both paths, including leading whitespace/control characters, and tests for formula-leading names.

### A08 — Medium: failed CSV imports persist earlier rows without an explicit partial-success contract

Requirements: optional CSV import; ARCH-01, VAL-01, ERR-01.

Evidence: `app/Controller/CustomerController.php:59` loops over service creates, with no batch transaction or prevalidation; equivalent code exists in all six import controllers.

Confirmed probe: first customer row valid, second row invalid => HTTP422, but the first row remains in the database. Retrying can duplicate rows or fail on previously inserted unique values. The error does not report committed rows and resumable state.

Remediation: choose atomic import with validation then transaction, or explicitly document partial mode and return per-row results/counts. Put orchestration in a service; avoid exposing raw exception messages.

### A09 — Medium: generic export and header sorting only operate on the current page

Requirements: FIND-01 consistency; optional table export.

Evidence: `public/assets/js/tables.js:93` sorts DOM rows; `:118` exports DOM rows. Paginated lists render ten records, so generic Export CSV exports at most that page and sorting is local. Existing PO/SO filter sort controls do perform server sorting; Reports correctly uses server/full-result export.

Impact: identical Export CSV wording hides different scopes. Generic exports also serialize action text/status presentation. This is a usability/data-contract debt, not a claim that REPORT-01 CSV is page-limited.

Remediation: server export for the entire authorized filtered result, or explicit “Export current page”; route sortable headers through server criteria and keep sort state across pages.

### A10 — Medium: enabled audit records are outside the stock transaction

Requirement: AGENTS stock rule 8, enabled audit-trail invariant; audit is optional but implemented.

Evidence: `public/index.php` records request audit after router/business execution; `app/Support/RequestAuditRecorder.php:18` is HTTP-level. `app/Service/AuditLogger.php:19` swallows write errors. StockService commits without an audit dependency/callback.

Impact: stock/order/ledger commit can succeed while the corresponding audit row is absent; creates can have null entity ID. Logging an HTTP result is not atomic domain audit evidence.

Remediation: record required stock domain audit inside the transaction, with accurate entity IDs. Keep optional request telemetry separate and document its best-effort policy.

### A11 — Medium: report pagination loads every matching row; composition eagerly loads master data

Requirements: REPORT-01; performance/architecture debt.

Evidence: `app/Service/ReportService.php:31` fetches full result sets, aggregates in PHP, then array_slice at `:71`; MySQL query repository uses fetchAll. Every page request also loads all suppliers/warehouses/customers in `public/index.php:110`, even login/API routes. Product stock display and order hydration contain per-record queries.

Impact: pagination bounds rendered rows but not memory/query work. No production-size load test was performed, so this is a confirmed scaling pattern rather than a measured latency claim.

Remediation: database aggregates/count/LIMIT for preview, streaming full exports, query only dependencies required by the request, and batch stock/item lookups. Preserve owner/date filters in each query.

### A12 — Low: quality/release documentation and Docker checks are not reproducible from a fresh image

Requirements: TEST-01/02/03, DESIGN-03, release evidence.

Evidence: README and release-readiness still say no Git repository, whereas `git log -1` succeeds. Release report lists 87 tests/96 analyzed files; current audit ran 265 PHP tests/110 files. Dockerfile copies no tests/phpunit.xml/phpstan.neon and has no Node executable installation; no Compose test target/CI workflow was found. Current container runs tests because earlier work manually copied test files into it. Host PHP is 7.4 and cannot satisfy Composer's ^8.2 runtime requirement on this workstation.

Remediation: documented dev/test image or Compose target with tests/configs, consistent PHP/Node runtime and isolated DB; rerun clean-build gates after the present changes and refresh evidence without deleting historical results. PHPStan 1.x reports an old-version warning; upgrading is maintenance work, not a requirement failure.

### A13 — Low: UI stylesheet accumulation and incomplete device evidence

Requirement: UI-01, maintainability.

Evidence: app.css is 2,051 lines with successive component and breakpoint overrides, including 760px and 960px navigation rules. Existing mobile browser evidence is substantial and reports 290 page/role/viewport checks plus 110 dialog checks, but uses desktop Chrome emulation; physical iOS/Android, Safari and soft keyboard remain unverified.

Remediation: consolidate canonical component/breakpoint rules with visual regression checks; verify real mobile keyboard/dialog behavior and a second browser. This audit found no new reproduced overflow bug and does not equate stylesheet length with a functional failure.

## Optional and pending scope

- Product image upload is explicitly optional in the official brief; absent implementation is **not** counted as a mandatory gap. If selected later, implement actual MIME/size validation and random filenames.
- Existing trainer questions remain pending: SO rejection representation, self-approval policy, PO partial cancellation, multi-warehouse low-stock semantics and order-number format. Do not invent new statuses or treat temporary defaults as approved decisions.
- The native stack, repository interfaces/manual injection, password hashing, session regeneration, POST CSRF, row locking, stock/ledger rollback, and Sales owner filtering are present. Passing checks support these observations, but source-order race/revocation findings above remain open.

## Verification and evidence

Executed 6 October 2026:

| Check | Result |
|---|---|
| Docker app `composer test:unit` | PASS: 243 tests, 702 assertions |
| Docker app `composer test:integration`, DB_DATABASE=inventory_audit_20261006 | PASS: 22 tests, 399 assertions |
| `node --test tests/JavaScript/*.test.js` | PASS: 46 tests |
| Docker app PHPStan --level=5 app config public | PASS: 110 files, no errors; old-version warning |
| Controlled interleaving/authorization/validation/import probes | Reproduced A01, A02, A04, A06, A08 |
| Node CSV helper probe | =1+1 serialized as quoted formula, confirming A07 |
| Isolated audit DB cleanup | DROP DATABASE succeeded; application DB untouched by this audit |

PHP suite totals combine unit and integration commands: **265 tests, 1,101 assertions**. The previous full-run evidence recorded 1,109 assertions against a different dataset; this audit reports the actual isolated-database counts. Host PHP 7.4 could not lint the PHP 8 constructor syntax in the probe; container PHP 8.3 successfully executed and linted it.

Probe source: `docs/testing/audit-probes-2026-10-06.php`; successful JSON: `docs/testing/audit-probes-2026-10-06.json`. It refuses database names outside inventory_audit_*. It requires a freshly seeded isolated MySQL database accessible with DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD and runs from the repository/container root. It deliberately creates receipt/issue/import evidence; never run it against application data. The first attempt found insufficient stock because the SO integration fixture leaves stock depleted; the successful version prepares inventory through a real PO receipt before the SO probe.

No production-size load benchmark, independent clean Docker build, new cross-browser run, or real simultaneous-process race test was performed in this audit. Existing mobile screenshots/checks were reviewed as prior evidence, not regenerated.

## Recommended sequence

1. Isolate integration DB and make test execution fail closed.
2. Fix SO/PO source locking and atomic state transitions, with controlled race regression tests.
3. Revalidate/revoke session authorization after role/deactivation changes.
4. Implement product detail; harden raw input/error mapping and CSV formula handling.
5. Clarify export/import contracts and make stock audit atomic.
6. Optimize report queries, consolidate UI CSS, refresh repeatable clean-build/release evidence.
