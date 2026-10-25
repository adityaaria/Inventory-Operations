# SO detail: stock available at source warehouse (9 October 2026)

Optional UI information requested by the project owner. No status, schema, stock rule or authorization rule changed.

## Behaviour

- For Draft, PendingApproval and Approved sales orders, the detail page adds an **Available at Source** column: the quantity currently on hand for each product at the order's source warehouse (missing stock row = 0). Stock in other warehouses is not counted.
- A line whose quantity exceeds that stock shows a `Short N` badge, and the page shows one warning: approval is still possible, but Issue Goods is refused until enough stock is available.
- Fulfilled and Cancelled orders show neither the column nor the warning.

The value is a read without locks, for information only. `SalesOrderService::issue()` still locks the stock rows and re-checks quantity inside its transaction, so the warning neither reserves stock nor replaces the oversell guard (see ADR-002/ADR-004).

## Implementation

- `SalesOrderService::sourceAvailability(AuthContext, SalesOrder)`: Sales may read only their own orders (403 otherwise); uses `ProductRepositoryInterface::stocksForProducts()`.
- `SalesOrderController::renderShow()` passes the result to `views/sales-orders/show.php`.

## Verification

- `tests/Unit/SalesOrderSourceAvailabilityTest.php` (6 tests): source-only quantities, missing row = 0, closed orders return nothing, foreign Sales 403, warning and `Short 17` for 100 vs 83, no warning when sufficient, column hidden when cancelled.
- PHPUnit full suite: OK, 598 tests, 4,571 assertions. PHPStan level 5: no errors.
- Running app on port 8080 (rebuilt): SO id 601 (KES-0001 qty 100 from Gudang Distribusi Surabaya, stock 83) shows the warning and `83 Short 17`.
