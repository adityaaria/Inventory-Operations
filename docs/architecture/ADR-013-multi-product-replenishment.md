# ADR-013: multi-product replenishment into one reviewed PO

Date: 7 October 2026. Optional scope: user target list item 5 ("Replenishment beberapa produk sekaligus"). Requirement support: PO-01, AUTH-01/02, ARCH-01/02, UI-01, TEST-01/02/03. No schema, status or stock change.

**Selection stays inside one warehouse by construction.** `/replenishment` gains a warehouse filter (validated `warehouse_id`, applied in the same prepared recommendation query for count and page). Checkboxes appear only when one warehouse is chosen; the selection form submits that single `warehouse_id` plus `pick[]=productId:suggestedQuantity` with GET to `/purchase-orders/create`.

**Prefill only, never an automatic order.** PurchaseOrderController turns picks into reviewable lines (1–100, positive integers, distinct products; otherwise 422 with the error shown). Supplier is not preselected and purchase prices are empty, so the user reviews supplier, quantities and prices before saving. Nothing is persisted on GET.

**PO create is multi-item.** The form keeps the primary `product_id/quantity/purchase_price` fields (single-item clients stay compatible) and adds up to 99 `items[n][...]` lines, matching the Stock Operations pattern. The controller validates every line with InputValidator; PurchaseOrderService still enforces product activity, duplicate rejection (D-04) and one transactional draft insert. `order-items.js` adds/removes lines, renumbers legends and flags duplicates; it is loaded from the workspace layout because create forms also open in the list-page modal.

**Follow-up (same day).** Checked recommendations persist per warehouse across pagination in the tab's sessionStorage and are submitted as hidden `pick[]` (limit 100, cleared on submit); the submit guard runs in the capture phase so a blocked empty submit never leaves the page loader on. SO create uses the same component and the shared `OrderItemsInput` parser (`views/partials/order-item.php`). The clone template's controls are unnamed so parsers and non-browser clients see only real fields (this fixed a regression caught by the business HTTP suite).

Suggested quantities are a snapshot; stock and open supply may change before the draft is saved or ordered, and no reservation is implied (as in ADR-009).
