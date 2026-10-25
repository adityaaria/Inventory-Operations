# ADR-015: order line prices come from the product master

> **Partially superseded on 10 October 2026 by [ADR-019](ADR-019-negotiated-line-prices.md) (user decision D-13).** The master price is still the default and the line still stores a snapshot, but a line may now deviate from the master with a mandatory reason (Sales discount cap, no below-cost sale); the price input is editable and submitted. The Rupiah display section below remains in force. The "server ignores any submitted price" and "read-only, unnamed price input" statements no longer apply.

Date: 9 October 2026. Decision: project owner (user), D-09. Not a trainer decision. Requirement support: PO-01, SO-01, VAL-01, SEC. No schema change.

**Context.** The brief defines PO items as (product, qty, purchase price) and SO items as (product, qty, selling price). The create forms asked the user to type that price on every line, although the product master already holds both prices. The user decided that lines must use only the master price.

**Decision.**
- **Server is authoritative.** `PurchaseOrderService::createDraft()` and `SalesOrderService::createDraft()` price every line from the active product (`Product::purchasePrice()` / `sellingPrice()`). `OrderItemsInput` no longer reads a price, and any price in the request is ignored, so a tampered form cannot change it.
- **Display only in the form.** Each product option carries `data-price`. The line shows that price in a read-only input without a `name` (not submitted, not saved in drafts), filled by the server on render and kept in sync by `order-items.js` when the product changes or a row is added.
- **Snapshot kept.** `purchase_order_items.purchase_price` and `sales_order_items.selling_price` still store the price at creation, so a later master price change does not alter existing orders, reports or dashboard history.

**Rupiah display.** Money is shown as Indonesian Rupiah, for example `Rp 20.000,00` (dot thousands, comma decimals). The server uses `App\Support\Money::rupiah()` for order lines, product list and detail, PO/SO detail and the dashboard inventory value. `order-items.js` `formatRupiah()` produces the identical string when the product changes, and client-side table sorting reads Rupiah cells. Stored values, `data-price` and CSV exports stay plain decimals.

**Consequences.** Negotiated or discounted prices can no longer be entered per order; the master price must be updated first. Existing orders are unchanged. HTTP clients that still send a price keep working; the value is ignored.

**Tests.** Service tests prove a tampered (negative) price is replaced by the master price. Controller tests cover submission without any price field, master prices on stored lines, and a read-only, unnamed price input with `data-price` options. A JavaScript test covers `masterPrice()`. The browser drafts suite checks that restored lines show the master price, read-only and unsubmitted.
