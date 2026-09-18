# Business Flow

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Authentication Flow

Users log in with email and password. Login rejects missing, inactive, or invalid users; failures can be recorded for rate limiting and audit. Successful login stores an `AuthContext` in the session. Logout clears the session and may audit the event.

Evidence:
- `app/Service/AuthService.php`
- `app/Security/NativeSessionManager.php`
- `app/Service/LoginRateLimiter.php`
- `tests/Unit/AuthServiceTest.php`

## Master Data Flow

Authenticated users can read master data. Admin users can create, update, import, activate, and deactivate master data. Products are linked to categories and include purchase price, selling price, unit, reorder point, and active state. Product stock is displayed by warehouse but stock mutation belongs to order workflows.

Evidence:
- `app/Service/MasterDataAuthorizationService.php`
- `app/Service/ProductService.php`
- `app/Controller/ProductController.php`
- `views/products/index.php`
- `tests/Unit/ProductServiceTest.php`

## Purchase Order Flow

Admin and WarehouseStaff users can create draft purchase orders against an active supplier, active destination warehouse, and active products. Admin marks Draft orders as Ordered. Admin and WarehouseStaff users receive Ordered or PartiallyReceived orders. Receipt validates quantity against remaining item quantity, increments stock, appends receipt ledger rows, updates received quantities, and updates order status to PartiallyReceived or Received. Admin can cancel Draft or Ordered purchase orders.

Evidence:
- `app/Service/PurchaseOrderService.php`
- `app/Entity/PurchaseOrder.php`
- `app/Controller/PurchaseOrderController.php`
- `views/purchase-orders/show.php`
- `tests/Unit/PurchaseOrderServiceTest.php`
- `tests/Integration/PurchaseOrderReceiptIntegrationTest.php`

## Sales Order Flow

Admin and Sales users can create draft sales orders against an active customer, source warehouse, and active products. The creator Sales user or Admin can submit a Draft order to PendingApproval. Admin approves PendingApproval orders. Admin can cancel non-Fulfilled orders. Admin and WarehouseStaff users can issue Approved orders. Issue locks stock, checks quantity after locking, decrements stock, appends issue ledger rows, and marks the order Fulfilled.

Evidence:
- `app/Service/SalesOrderService.php`
- `app/Entity/SalesOrder.php`
- `app/Controller/SalesOrderController.php`
- `views/sales-orders/show.php`
- `tests/Unit/SalesOrderServiceTest.php`
- `tests/Integration/SalesOrderIssueIntegrationTest.php`

## Reporting Flow

Authenticated users access dashboard and reports. Dashboard data differs by role. CSV reports export stock ledger and order rows; Sales role order reports are scoped to that sales user's orders.

Evidence:
- `app/Service/DashboardService.php`
- `app/Service/ReportService.php`
- `app/Controller/ReportController.php`
- `views/dashboard/index.php`
- `views/reports/index.php`
- `tests/Unit/DashboardServiceTest.php`
- `tests/Unit/ReportServiceTest.php`

## Low-Stock Flow

Low stock is determined by warehouse-level quantity below the product reorder point. The CLI command prints matching rows or `No low-stock rows.`.

Evidence:
- `app/Service/LowStockService.php`
- `scripts/check-low-stock.php`
- `tests/Unit/LowStockPolicyTest.php`

## Business-Specific Workflows

- Purchase replenishment through suppliers and destination warehouses.
- Sales fulfillment through customers and source warehouses.
- Stock ledger as source-of-truth evidence for receipt and issue movements.
- Role-specific operational dashboards.

## Gaps / Unknowns

- Sales order rejection is represented through cancellation in `SalesOrderService::rejectOrCancel`; there is no distinct `Rejected` status in code or schema.
- Purchase orders can only be cancelled in Draft or Ordered status based on service rules.
