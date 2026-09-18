# Domain Map

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Actors And Roles

- `Admin`: manages users/master data, orders/cancels purchase orders, approves/cancels sales orders, can create purchase and sales orders, can receive/issue stock.
- `Sales`: creates sales order drafts and can submit own draft sales orders.
- `WarehouseStaff`: creates and receives purchase orders, issues approved sales orders.

Evidence:
- `app/Entity/User.php`
- `app/Service/PurchaseOrderService.php`
- `app/Service/SalesOrderService.php`
- `app/Service/MasterDataAuthorizationService.php`

## Core Entities

- User
- Category
- Warehouse
- Product
- ProductStock
- Supplier
- Customer
- PurchaseOrder
- PurchaseOrderItem
- SalesOrder
- SalesOrderItem
- StockLedgerEntry

Evidence:
- `app/Entity/`
- `database/schema-and-seed.sql`

## Domain Terms

- SKU
- reorder point
- active/inactive master data
- source warehouse
- destination warehouse
- purchase price
- selling price
- receipt
- issue
- stock ledger
- low stock
- order number
- created by
- approved by

Evidence:
- `app/Entity/Product.php`
- `app/Entity/ProductStock.php`
- `app/Entity/StockLedgerEntry.php`
- `app/Entity/PurchaseOrder.php`
- `app/Entity/SalesOrder.php`
- `database/schema-and-seed.sql`

## Status Models

Purchase order:
- Draft
- Ordered
- PartiallyReceived
- Received
- Cancelled

Sales order:
- Draft
- PendingApproval
- Approved
- Fulfilled
- Cancelled

Stock ledger:
- Receipt
- Issue

Evidence:
- `app/Entity/PurchaseOrder.php`
- `app/Entity/SalesOrder.php`
- `database/schema-and-seed.sql`

## Domain Invariants

- Product SKUs are unique.
- Product stock quantity cannot be negative.
- Purchase and sales order lines cannot duplicate products within one order.
- Purchase item received quantity cannot exceed ordered quantity.
- Stock issue checks available quantity after locking the stock row.
- Stock receipt and issue append ledger entries inside the stock transaction.
- Multiple product stock locks are acquired in product-id order.

Evidence:
- `database/schema-and-seed.sql`
- `app/Service/PurchaseOrderService.php`
- `app/Service/SalesOrderService.php`
- `app/Service/StockService.php`
- `tests/Unit/StockServiceReceiptTest.php`
- `tests/Unit/StockServiceIssueTest.php`
- `tests/Integration/SalesOrderIssueIntegrationTest.php`

## Business-Specific Terminology

These names should be replaced or omitted when mirroring this project into another domain:
- Inventory Ops
- purchase orders
- sales orders
- suppliers
- customers
- warehouses
- products
- stock ledger
- receipt
- issue
- reorder point

Evidence:
- `public/assets/js/app.js`
- `views/`
- `database/schema-and-seed.sql`

## Gaps / Unknowns

- Exact trainer-approved order-number format is not encoded beyond view defaults such as `PO-<?= date('YmdHis') ?>` and `SO-<?= date('YmdHis') ?>`.
