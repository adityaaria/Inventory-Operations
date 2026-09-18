# Database Catalog

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Database Engine

MySQL 8.0 is used through Docker Compose. PDO uses `charset=utf8mb4`, exception error mode, associative default fetch mode, and native prepares.

Evidence:
- `compose.yaml`
- `app/Support/DatabaseFactory.php`

## Schema Source

Schema and seed data are consolidated in `database/schema-and-seed.sql` and mounted into MySQL initialization as `001-schema-and-seed.sql`.

Evidence:
- `database/schema-and-seed.sql`
- `compose.yaml`

## Tables

- `schema_versions`: applied schema phase markers.
- `users`: user identity, password hash, role, active state.
- `categories`: product category master data.
- `warehouses`: warehouse master data.
- `products`: product catalog with SKU, unit, purchase/selling/price, reorder point, category, active state.
- `product_stocks`: stock quantity by product and warehouse.
- `suppliers`: supplier master data.
- `customers`: customer master data.
- `purchase_orders`: purchase order header.
- `purchase_order_items`: purchase order lines and received quantities.
- `stock_ledger`: receipt/issue stock movement records.
- `audit_logs`: request/auth/audit event records with JSON metadata.
- `login_attempts`: login rate-limiting events.
- `sales_orders`: sales order header with approval fields.
- `sales_order_items`: sales order lines.

Evidence:
- `database/schema-and-seed.sql`

## Enumerations

- User roles: `Admin`, `Sales`, `WarehouseStaff`.
- Purchase order statuses: `Draft`, `Ordered`, `PartiallyReceived`, `Received`, `Cancelled`.
- Sales order statuses: `Draft`, `PendingApproval`, `Approved`, `Fulfilled`, `Cancelled`.
- Stock ledger movement types: `Receipt`, `Issue`.
- Audit log statuses: `success`, `failure`, `blocked`.

Evidence:
- `database/schema-and-seed.sql`
- `app/Entity/User.php`
- `app/Entity/PurchaseOrder.php`
- `app/Entity/SalesOrder.php`

## Integrity Constraints

- Unique keys exist for user email, category name, warehouse name, product SKU, supplier name, customer name, order numbers, purchase-order product lines, and sales-order product lines.
- Foreign keys link order headers/items, stock rows, ledger rows, products, warehouses, users, suppliers, and customers.
- Quantity and money fields have non-negative or positive checks.
- `purchase_order_items.received_quantity <= quantity` is enforced.
- `product_stocks` has a composite primary key on `(product_id, warehouse_id)`.

Evidence:
- `database/schema-and-seed.sql`
- `tests/Integration/PurchaseOrderSchemaIntegrationTest.php`
- `tests/Integration/MasterDataRepositoryIntegrationTest.php`

## Stock Persistence

`MySqlStockRepository::lockByProductWarehouse` selects the stock row `FOR UPDATE`, inserts a zero row if missing, then locks again. Stock increments/decrements update `product_stocks`; ledger rows are appended by `MySqlStockLedgerRepository`.

Evidence:
- `app/Repository/MySql/MySqlStockRepository.php`
- `app/Repository/MySql/MySqlStockLedgerRepository.php`
- `app/Service/StockService.php`

## Operational Queries

`OperationalQueryRepositoryInterface` owns dashboard metrics, low-stock rows, stock-ledger report rows, order report rows, and product availability query data.

Evidence:
- `app/Repository/Contract/OperationalQueryRepositoryInterface.php`
- `app/Repository/MySql/MySqlOperationalQueryRepository.php`

## Gaps / Unknowns

- No migration runner exists; changes to schema currently mean editing the consolidated SQL file and coordinating integration database state.
