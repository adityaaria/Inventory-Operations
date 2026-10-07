# Gap follow-up and regression — 7 October 2026

Requirement IDs: PO-01, SO-01, UI-01, AUTH-01/02, ARCH-01/02, TEST-01/02/03. Mandatory/Optional: optional follow-up to the known gaps in [enhancements 4–6](enhancements-4-6-2026-10-07.md).

| Gap | Outcome |
|---|---|
| Existing business HTTP/browser suites not rerun | Rerun. First run found a **regression from target 5**: 75/76, failing "PO shortcut prefills product warehouse quantity" ([first run](gap-regression-2026-10-07/http.json)). Fixed and rerun 76/76 |
| SO create single-item | SO create is multi-item (up to 100 lines) |
| Replenishment selection per page only | Selection persists across pages per warehouse in the tab's sessionStorage and is sent as one prefill |
| D-07, D-08 trainer/deployment decisions; physical devices; localhost:8080 activation | Not changed: need a decision, devices or approval |

Regression cause: the clone `<template>` for PO lines carried real `name` attributes. Browsers never submit template content, but HTML parsers and non-browser clients saw a second, empty `product_id` that overrode the prefilled one. Template controls are now unnamed (`$prefix = null`); JavaScript names cloned rows. Unit tests assert exactly one `name="product_id"` on PO and SO create pages.

Files changed: app/Validation/OrderItemsInput.php (new shared `purchase()`/`sales()` line parser); PurchaseOrderController and SalesOrderController use it; views/partials/order-item.php (moved from purchase-orders/item.php, price field parameterised, unnamed template); views/purchase-orders/create.php, views/sales-orders/create.php; public/assets/js/order-items.js (cross-page selection, capture-phase submit guard); tests: PurchaseOrderControllerTest, SalesOrderControllerTest, order-items.test.js, tests/Browser/form-drafts.cjs.

Behavior implemented:
- SO create: primary line plus `items[n][product_id|quantity|selling_price]`; SalesOrderService still rejects duplicates and inactive products; Sales owns the draft. Draft restore warns per line when quantity exceeds current stock.
- Replenishment: checked recommendations are remembered per warehouse while paging; a status line shows the count across pages; the 100-product limit is enforced before submit; on submit, off-page picks are added as hidden `pick[]` and the stored selection is cleared. An empty submit is blocked in the capture phase, before the shared form handler, so the page loader is not left showing (this also affected the target-5 version).

Security/authorization and invariants: unchanged role rules (SO create Admin/Sales; PO prefill Admin/Warehouse); server revalidates every line; no schema, stock, ledger or transaction change. sessionStorage holds only product IDs and suggested quantities for the current tab.

Commands executed + result:
- `docker compose --profile quality run --build --rm test`: **417 PHP tests / 1899 assertions OK**, PHPStan level 5 **no errors**, **63 JavaScript tests** passed. [Log](gap-regression-2026-10-07/final/quality.txt).
- `docker compose build app` (image tag only; the localhost:8080 container was not recreated), then `scripts/verify-business.py --keep-running`: **76/76** business HTTP checks; `tests/HTTP/work-queue.py` **13/13**; `tests/HTTP/document-timeline.py` **21/21**; `tests/Browser/business-enhancements.cjs` **50/50** (headless Chrome 154). Evidence in [final](gap-regression-2026-10-07/final/). Business stack removed.
- Fresh disposable stack (localhost:18091): outstanding **21/21**, replenishment **15/15**, draft check **7/7**, `tests/Browser/form-drafts.cjs` **18/18** including multi-item SO restore with two stock warnings, cross-page selection prefilling one PO and an empty selection blocked without a stuck loader. Stack and Chrome stopped.
- `git diff --check` passed; new files checked for trailing whitespace.

Not run: physical Android/iPhone devices; `tests/HTTP/end-to-end.py`, `load.py`, `audit-trail.py` and `dashboard-low-stock.py` (the last two target the live localhost:8080 runtime, which still serves the previous image).

Known gaps/risks: D-07 and D-08 remain temporary defaults; the browser chrome profiles were left in the session scratchpad directory.

## Local activation (user approved, 2026-10-07)

`docker compose up -d --build --wait app`: app rebuilt; Compose also recreated the `db` container (compose.yaml differs from the running config), reusing the `inventory-operations_mysql-data` volume. Data verified persisted afterwards (31 PO, 24 SO, 18 ledger, 131 audit rows; earliest PO 2026-10-06), no migration or seed replay. Readiness `{"status":"ready"}`. `tests/HTTP/audit-trail.py` passed (4 role checks) and `tests/HTTP/dashboard-low-stock.py` passed 11 checks (7 low-stock pairs). Admin smoke: outstanding report, replenishment warehouse filter, PO/SO multi-item create with drafts and `/drafts/check` all 200 with expected markup. Tagging the previous image for rollback failed because it was no longer addressable; rollback is a rebuild from the previous code.
