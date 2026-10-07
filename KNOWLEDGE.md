# Requirement reference for enhancements and bug fixes

Reviewed on 2026-10-06 against all 19 pages of `Project Brief - Programmer.pdf` (Participant Guide, edition 1.0, October 2026), `AGENTS.md`, `SDD.md`, accepted ADRs, and pending decisions. This is a navigation aid, not a replacement for the official brief or a declaration that every requirement passes.

## Authority and scope

Follow the authority order in `AGENTS.md`: official brief → AGENTS → SDD → this reference → approved decisions → implementation/tests → enhancement ideas. Existing code and passing tests cannot override a requirement. Do not treat historical coverage reports as current verification.

Required stack: PHP 8.2+ native OOP, MySQL 8, PDO prepared statements, Controller → Service → Repository, repository interfaces, manual constructor injection, HTML/custom CSS/Vanilla JS/Fetch, Docker Compose, PHPUnit and static analysis. No backend/frontend/CSS framework, admin template, ORM, CRUD generator, or framework DI container (brief pp. 12–13).

## Requirement map

| IDs | Required behavior | Brief pages |
|---|---|---|
| AUTH-01/02, USR-01 | Three roles; hashed passwords; session regeneration; inactive login denied; logout blocks protected access; Admin-only user management; unique email; no public registration | 3–6 |
| PRD-01, WH-01 | Unique SKU; category description; separate purchase/selling prices; unit/reorder validation; supplier/customer contact/address; deactivation; total stock and warehouse breakdown | 4, 6 |
| PO-01 | Supplier/destination/items; Draft, Ordered, PartiallyReceived, Received, Cancelled; partial/full receipt; remaining quantities; atomic stock and Receipt ledger | 4, 6 |
| SO-01 | Customer/source/items/creator/approver; Draft → PendingApproval → Approved → Fulfilled; cancellation before Fulfilled; Sales own orders; Sales cannot approve; Approved-only issue; insufficient stock rejected | 3–4, 7 |
| VIEW-01, FIND-01 | Product/PO/SO lists and details with informative empty states; product name/SKU/category/stock filters; server sort from column-header arrows (order number/party/warehouse/status/date; product SKU/name/unit/prices/reorder/stock), no separate Order filter; main lists 10 per page; preserve filters | 7 |
| DASH-01, REPORT-01 | Real aggregates; Admin inventory/low-stock/pending; Sales own orders; Warehouse receipt/issue queues and low stock; date-range stock movement and order CSV reports with role scope | 3, 7–8 |
| API-01 | At least one authenticated JSON endpoint; JSON content type and 200/401/404; no HTML API errors | 8 |
| VAL-01, ERR-01, UI-01 | Frontend/backend validation; retain input where relevant; failed validation does not persist; safe login/403/404/errors; usable at 360px and desktop; labels/focus/contrast | 8–9 |
| DB-01, JOB-01 | Keys/constraints/indexes; prepared statements; explicit multi-table transactions; clean schema/seed; standalone low-stock command runnable through Docker, automatic cron unnecessary | 9 |
| ARCH-01/02 | Services independent of PDO/session/superglobals; interface with MySQL and fake implementations; constructor injection; atomic stock/ledger; no oversell/lost updates; explainable controlled concurrency scenario | 9–10 |
| DESIGN-01/02/03/04 | Initial/as-built diagrams traceable to code and change explanation; 2–3 ADRs; ≥3 refactor entries with smell/technique/before-after; SRP audit; debt register; genuine refactor commit; critique analysis | 10–11 |
| TEST-01/02/03 | ≥6 isolated unit cases across ≥3 logic areas; ≥3 real MySQL Docker integration tests; trivial getters/skips do not count; PHPStan level 5+ or PSR-12 PHPCS; no critical errors; explain warnings; FIRST | 11–13 |

Seed minimum: 1 Admin, 2 Sales, 2 Warehouse Staff, 2 warehouses, 30 products with varied reorder points and low-stock examples, 25 combined PO/SO with varied statuses including PendingApproval and Cancelled (p. 16).

Product images are explicitly optional (pp. 4, 6). If implemented, validate type/size and randomize names; the upload evidence wording does not make images mandatory. Charts, audit additions, and other bonus work cannot replace missing mandatory behavior (p. 14). No obligation to add microservices, queues, cloud, CI/CD, Kubernetes, realtime notifications, mobile, automatic cron, or automated E2E (p. 14).

## Change protocol

Current UI component direction is documented in `docs/quality/design-system.md`: neutral workspace styling from the user-provided reference, implemented in custom CSS/Vanilla JS. Preserve full-viewport layout, shared styling, bottom Profile/logout, adjacent filters/table/pagination, and Export CSV in the header toolbar beside Create/Import when enhancing pages. Do not reintroduce redundant Home buttons or Search table.

Before each enhancement/fix, identify its requirement IDs and mandatory/optional classification. Read the relevant PDF section, SDD, code, schema, tests, and approved decisions. Determine actors/ownership, valid states, transaction boundary, affected invariants, and acceptance behavior. Preserve required role scope and state enums; never infer new requirements from UI ideas.

Stock changes must use the stock service: authorize and validate state; begin transaction; lock stock rows in deterministic order; validate after lock; mutate balance and append ledger; update source operation; commit or fully roll back. Follow AGENTS/ADR-002/003 for the implementation protocol. For fixes involving repeated/concurrent requests, also inspect whether source-order state and receipt remainder are validated against current transactional state. Stock locking alone is not proof that the same order cannot be processed twice.

Run relevant unit tests, real MySQL integration tests when persistence changes, and static analysis. UI changes need proportionate responsive/accessibility checks. Update actual evidence and AI disclosure; report only commands that ran. Docs-only changes do not require application regression tests. Follow the task report format in AGENTS.

Before optional work, verify the mandatory gate: requirements implemented, tests green, acceptable static analysis, clean Docker boot, and substantially complete evidence. The 2026-10-06 runtime check proves startup, login/dashboard/API smoke, unit tests, and static analysis; it does not establish the full optional-feature gate or current integration-suite status.

## Ambiguities and evidence cautions

Consult `docs/planning/DECISIONS_PENDING.md` for PO cancellation after partial receipt, SO rejection mapping, Admin self-approval, duplicate lines, low-stock aggregation, order-number format, and transaction ownership. Defaults there are temporary, not trainer-approved requirements. Record new ambiguities before changing scope; do not invent trainer decisions (brief p. 18).

README/older evidence claims that Git is unavailable are stale: Git metadata exists as of this review. The genuine refactor commit `5cef0dd` exists locally as of 7 October 2026; final release/tag and hosted publication still need separate evidence. Full mandatory coverage, screenshots, integration results, and submission artifacts require their own verification.

AI work must follow DISCLOSE → REVIEW → VERIFY → TEST, recorded in `ai-usage-log.md`; never fabricate evidence, chronology, trainer decisions, or initial diagrams (brief pp. 15–19).

- Loading follow-up: render the complete authenticated workspace in PHP partials; never rebuild/reparent it on DOMContentLoaded. Navigation JS enhances the drawer only. Native CSS cross-document transitions are optional progressive enhancement, disabled with reduced motion. Role presentation is supplied from the authenticated session, and route guards remain authoritative.

- Dialog UI: one header only. Initial fetched form and 422 responses must use the same modal.renderForm normalization. Cancel left and Submit right beneath a full-width footer separator, including imports/confirmation/mobile. Native page form layout remains independent.

- Reports now uses dashboard-style summary cards/charts with reporting semantics: order status or stock movements, date-filtered preview (10 records/page) and full server CSV export in the header. Filters survive pagination. Sales data is own-order scoped and stock preview/export is server-denied. Warehouse defaults to stock; existing permissions retained. Avoid generated client/page-only CSV for tables marked data-export=server. Preview/summary/export must use the same scoped dataset, with ledger end dates inclusive for the full day. Optional Outstanding & Aging report (ADR-012): Admin all open PO/SO plus stock proposals awaiting approval/posting, Warehouse receipts/issues/approved proposals awaiting action, Sales own open SO; document and age filters validated per role; age = whole days since creation in 0–2/3–7/8–30/31+ buckets plus DaysSinceApproval for Approved SO/proposals, never called lateness (D-07 user-approved); closed PO remainder excluded; PO and SO units shown separately. Replenishment (ADR-013) multi-selects within one filtered warehouse and only prefills a multi-item PO for review; PO and SO create accept up to 100 distinct lines (shared OrderItemsInput, unnamed clone template); replenishment picks persist across pages per warehouse in sessionStorage. Form drafts (ADR-014) are browser-local per user/role, lifetime = SESSION_ABSOLUTE_SECONDS (8h, D-08 user-approved), cleared on send/logout, never store CSRF/operation keys/baselines; restore needs consent and `/drafts/check` revalidates permission, master-data status and stock; services stay authoritative.

- Mobile/tablet fit: drawer breakpoint is 960px in both JS and CSS. Render tables in named/focusable table-scroll PHP wrappers; never rely on scrolling the entire main page. Preserve filter/table/pagination adjacency and full server export behavior. Mobile fields use 16px text and 44px control targets, long text wraps, compact Menu/breadcrumb share a row, and dialog footer stays reachable within dynamic viewport height. Keep closed drawer inert with focus return/Tab containment; authorization remains server-side.

- Training curricula alignment: `docs/quality/training-module-alignment.md` maps all five local modules to this stack. Laravel/Eloquent, React/Next.js and CSS-framework curriculum examples remain excluded by the official brief. Use relevant chapter references for future changes, without treating the full syllabus as new requirements.
- AUTH-01/02 session lifecycle: see ADR-005. Native sessions have configurable idle/absolute/rotation limits, strict cookie-only IDs, CSRF rotation on login/logout/privilege change, current-account/credential revocation and persistent dedicated Docker storage. Keep CSRF stable on periodic rotation, preserve the original login time, and release the PHP lock before CSV streaming. Expired Fetch dialogs get 401/sign-in feedback; never replay failed mutations automatically.

- WH-01/PRD-01 opening balances: Product/Warehouse create initializes every product–warehouse pair at zero through StockService, atomically with the master record. Catalog creators serialize using the immutable phase-0 bootstrap row, and nested imports share the same transaction owner. Initialization never changes existing quantities or fabricates zero ledger movements. See ADR-006 and the idempotent initialize-stock-balances CLI for historical missing pairs; never reseed the application DB to repair them.
- VAL-01/ERR-01 E2E fixes: all create/edit forms retain escaped safe attempted values on 422; never restore password/CSRF. Invalid order transitions raise ValidationException (422), and malformed mutation IDs are rejected without invented audit entity IDs. Order product selection uses the full active catalog independently of ten-row list pagination.

- Operational priorities 1–6: see ADR-007 and docs/operations/runbook.md. Keep the standalone TLS/FPM topology separate from local assessment Compose. Backup restores are whole-database recovery into a guarded empty target, not ad-hoc stock edits. Health probes never create sessions; local monitor emits JSON/exit codes. Physical Android/iPhone and trainer decisions remain pending until actual external results arrive. Standard Docker quality build now passes; see current operations evidence.

## Optional Admin audit visibility — 7 October 2026

`/audit-trail` is Admin-only and reads existing activity history with exact action/actor/status/date filters and 10-row pagination. No stock workflow changes; verification in `docs/testing/audit-trail-2026-10-07.md`.

## Optional receipt/issue idempotency — 7 October 2026

HTTP receipt/issue requires an operation key in addition to CSRF. Same actor/key/payload replay is a successful no-op; a different payload returns 409. Marker joins stock/ledger/order/audit transaction. New additive migration must precede deployment. See ADR-008 and `docs/testing/idempotency-2026-10-07.md`; no business policy/state change.

## Business enhancements 1–6 — 7 October 2026

User approved optional scope; see ADR-009 and docs/testing/business-enhancements-2026-10-07.md. PO remainder closure retains PartiallyReceived and received goods; SO rejection retains Cancelled plus mandatory reason. Per-warehouse replenishment subtracts open PO supply and excludes closed remainder. New stock documents require independent Admin approval, then transactional StockService posting with signed Adjustment ledger. Customer goods must be fit for stock; original-movement return allowance is concurrency locked. Reports/CSV/net movement include quantity_delta. Mandatory SO self-approval policy is unchanged; trainer decisions remain pending.

- DASH-01 Warehouse low-stock fix (7 October 2026): derive low_stock_count from existing active product–warehouse low_stock_rows and render independently of Admin Inventory Value. Zero is visible; same product in two warehouses counts as two pairs. Sales remains scoped. This preserves D-05 temporary semantics; trainer approval still pending. Evidence: docs/testing/warehouse-dashboard-2026-10-07.md.

Multi-item Stock Operations (7 October 2026): proposal form supports up to 100 distinct product items with shared kind/warehouse/destination/reason; each row has quantity/baseline/original movement/customer-fit confirmation. Legacy first-item payload retained; supplementary items parsed and validated before service proposal. Source warehouse rules, independent approval and atomic StockService posting remain unchanged. Evidence: docs/testing/multi-item-2026-10-07.md.

Role work queue (7 October 2026): /work-queue, native controller → service → prepared MySQL repository interface with manual injection. Role datasets/age semantics are documented in ADR-010. Sales actor scope is mandatory on server; Admin creator exclusion only for optional stock proposal approval. Closed PO supply is excluded. Existing action routes revalidate permissions/status. No schema, stock mutation, SLA or new status. Evidence: docs/testing/work-queue-2026-10-07.md.

Document timeline (7 October 2026): native read-only timeline linked from PO/SO/stock operation details, repository interface/manual DI and prepared SQL. Admin/Warehouse visibility; Sales own SO with no stock-operation link/events. Original ledger, creation/approval/posting metadata and successful audit events only; reason/decision whitelisted, no private request metadata. Latest 100 events explicitly bounded; names current and missing history not invented. ADR-011/evidence: docs/testing/timeline-2026-10-07.md.
