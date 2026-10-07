# Enhancement targets 4–6 acceptance — 7 October 2026

Requirement IDs: REPORT-01, PO-01, SO-01, UI-01, AUTH-01/02, ARCH-01/02, DB-01, TEST-01/02/03. Mandatory/Optional: optional, from the user's six-item target list. Targets 1 (multi-item stock operations), 2 (role work queue) and 3 (document timeline) were already implemented; see multi-item, work-queue and timeline reports of the same date.

| Target | Status | Design record |
|---|---|---|
| 4 Outstanding/aging report | Completed: stock proposals awaiting approval/posting, document and age filters | ADR-012, [report](outstanding-aging-2026-10-07.md) |
| 5 Multi-product replenishment | Completed | ADR-013 |
| 6 Form draft recovery | Completed | ADR-014 |

Files changed (5): BusinessOperationRepositoryInterface/MySql/InMemory (optional `warehouseId`), BusinessOperationService filter, BusinessOperationController, views/inventory-operations/recommendations.php, PurchaseOrderController (prefill, multi-item store), views/purchase-orders/create.php and new item.php, public/assets/js/order-items.js.
Files changed (6): DraftCheckService, DraftCheckController, public/index.php (route, draft owner), views for PO/SO/stock-proposal create (`data-draft`), views/partials/workspace-start.php (scripts), public/assets/js/form-drafts.js.

Security/authorization: recommendation warehouse filter validated; PO prefill and store remain Admin/Warehouse; draft check uses the create-route role matrix (403 otherwise) and requires login. Drafts are scoped to user and role, exclude secrets and baselines, and are cleared on send and logout. Services remain authoritative on submit.

Transaction/invariants: no schema, stock, ledger or status change. Multi-item PO uses the existing single transactional draft insert and duplicate rejection. Draft check reads stock without a locking transaction.

Tests added/updated:
- Unit: PO prefill (lines, no persistence), six invalid selections, multi-item store, five invalid item payloads; draft check (status/stock, inactive warehouse, role matrix, unknown form, 101 items, controller JSON/422).
- MySQL integration: recommendation warehouse filter for count/page/service; draft check sees stock changes and deactivated products without a transaction.
- JavaScript: duplicate detection and item naming; draft key scoping, excluded fields, expiry, item remapping, check messages, logout/housekeeping clearing.
- HTTP (fresh disposable stack): 21 outstanding, 15 replenishment, 7 draft-check checks.
- Browser (headless Chrome 154 via DevTools Protocol): 15 checks covering a three-line PO draft restored after reload with server check, cancelled confirmation keeping the draft, confirmed submit clearing it, the modal create form, discard, stock proposal type/items, logout clearing, another user's draft never offered, SO stock-shortfall warning, no horizontal overflow at 360/390/768/1440 on outstanding report, replenishment and the three create forms, and no uncaught exceptions.

Commands executed + result:
- `docker compose --profile quality run --build --rm test`: **415 PHP tests / 1890 assertions OK**, PHPStan level 5 **no errors**, **62 JavaScript tests** passed. [Quality log](enhancements-4-6-2026-10-07/quality.txt).
- On a fresh `inventory-e2e-outstanding` stack (localhost:18091): `tests/HTTP/outstanding-report.py` 21 passed, `tests/HTTP/replenishment-selection.py` 15 passed, `node tests/Browser/form-drafts.cjs` 15 passed, `tests/HTTP/form-drafts.py` 7 passed (rerun after correcting its `Cache-Control` assertion to accept `no-store, private`). [Outstanding](enhancements-4-6-2026-10-07/outstanding-http.json), [replenishment](enhancements-4-6-2026-10-07/replenishment-http.json), [drafts](enhancements-4-6-2026-10-07/drafts-http.json), [browser](enhancements-4-6-2026-10-07/browser.txt).
- Database spot checks on the disposable stack: replenishment drafts `PO-MULTI-*` each held 2 lines in the selected warehouse; browser-restored `PO-DRAFT-BROWSER` held exactly 3 lines with the restored quantities and prices.
- `git diff --check` passed; new files checked separately for trailing whitespace.
- Stack, volumes and Chrome profile removed afterwards.

Not run: physical Android/iPhone devices; the existing business HTTP/browser suites (they require their own evidence context); localhost:8080 was **not** rebuilt and still serves the previous image.

Follow-up: see [gap follow-up and regression](gap-regression-2026-10-07.md); a regression in the PO prefill caught by the existing business suite was fixed, SO create became multi-item and replenishment selection now spans pages.

Known gaps/risks: D-07 (aging bucket/Warehouse focus) and D-08 (device-local drafts on shared computers) are temporary defaults. Replenishment selection is per page (10 rows); a larger selection needs several POs or manual lines. SO create remains single-item. Suggested quantities and the draft stock check are snapshots, not reservations.
