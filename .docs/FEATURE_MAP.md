# Feature Map

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Authentication

Routes:
- `GET /login`
- `POST /login`
- `POST /logout`

Owned by:
- `app/Controller/AuthController.php`
- `app/Service/AuthService.php`
- `app/Security/SessionManager.php`
- `app/Security/NativeSessionManager.php`
- `app/Service/LoginRateLimiter.php`
- `app/Repository/Contract/LoginAttemptRepositoryInterface.php`

Evidence:
- `public/index.php`
- `app/Service/AuthService.php`
- `views/auth/login.php`
- `tests/Unit/AuthServiceTest.php`
- `tests/Unit/LoginRateLimiterTest.php`

## User Management

Routes:
- `/users`, `/users/create`, `/users`, `/users/import`, `/users/edit`, `/users/update`, `/users/activate`, `/users/deactivate`

Admin-only writes are enforced through `AuthGuard::requireUserManagement`.

Evidence:
- `public/index.php`
- `app/Controller/UserController.php`
- `app/Service/UserService.php`
- `app/Security/Authorization.php`
- `views/users/`
- `tests/Unit/UserServiceTest.php`
- `tests/Unit/UserControllerTest.php`

## Master Data

Features:
- Categories
- Warehouses
- Products
- Suppliers
- Customers

Pattern:
- authenticated list/read
- admin-only create/update/import/activate/deactivate
- create/edit forms use `views/<feature>/create.php` and `views/<feature>/edit.php`
- list pages use `table.data-table`; products add server-side search/filter/sort/pagination

Evidence:
- `app/Controller/CategoryController.php`
- `app/Controller/WarehouseController.php`
- `app/Controller/ProductController.php`
- `app/Controller/SupplierController.php`
- `app/Controller/CustomerController.php`
- `app/Service/MasterDataAuthorizationService.php`
- `views/categories/`
- `views/warehouses/`
- `views/products/`
- `views/suppliers/`
- `views/customers/`
- `tests/Unit/MasterDataAuthorizationTest.php`
- `tests/Integration/MasterDataRepositoryIntegrationTest.php`

## Purchase Orders

Routes:
- `GET /purchase-orders`
- `GET /purchase-orders/show`
- `GET /purchase-orders/create`
- `POST /purchase-orders`
- `POST /purchase-orders/order`
- `POST /purchase-orders/receive`
- `POST /purchase-orders/cancel`

Workflow:
- create draft
- Admin marks Draft as Ordered
- Admin or WarehouseStaff receives Ordered or PartiallyReceived orders
- receipt increments stock and writes `Receipt` ledger entries
- receipt status becomes PartiallyReceived or Received
- Admin cancels Draft or Ordered orders

Evidence:
- `app/Controller/PurchaseOrderController.php`
- `app/Service/PurchaseOrderService.php`
- `app/Entity/PurchaseOrder.php`
- `views/purchase-orders/`
- `tests/Unit/PurchaseOrderServiceTest.php`
- `tests/Integration/PurchaseOrderReceiptIntegrationTest.php`

## Sales Orders

Routes:
- `GET /sales-orders`
- `GET /sales-orders/show`
- `GET /sales-orders/create`
- `POST /sales-orders`
- `POST /sales-orders/submit`
- `POST /sales-orders/approve`
- `POST /sales-orders/cancel`
- `POST /sales-orders/issue`

Workflow:
- Admin or Sales creates draft
- creator Sales user or Admin submits Draft to PendingApproval
- Admin approves PendingApproval
- Admin or WarehouseStaff issues Approved order
- issue decrements stock, prevents insufficient stock, writes `Issue` ledger entries, and marks order Fulfilled
- Admin cancels non-Fulfilled orders

Evidence:
- `app/Controller/SalesOrderController.php`
- `app/Service/SalesOrderService.php`
- `app/Entity/SalesOrder.php`
- `views/sales-orders/`
- `tests/Unit/SalesOrderServiceTest.php`
- `tests/Integration/SalesOrderIssueIntegrationTest.php`

## Dashboard And Reports

Dashboard is role-aware and backed by `OperationalQueryRepositoryInterface`.

Reports provide CSV exports for orders and stock ledger with optional date ranges. CSV output neutralizes formula-leading cell values.

Evidence:
- `app/Controller/DashboardController.php`
- `app/Service/DashboardService.php`
- `app/Controller/ReportController.php`
- `app/Service/ReportService.php`
- `app/Repository/Contract/OperationalQueryRepositoryInterface.php`
- `views/dashboard/index.php`
- `views/reports/index.php`
- `tests/Unit/DashboardServiceTest.php`
- `tests/Unit/ReportServiceTest.php`

## JSON API

Product availability is exposed at `GET /api/products/{sku}/availability` for authenticated users.

Evidence:
- `public/index.php`
- `app/Http/Router.php`
- `app/Controller/Api/ProductAvailabilityController.php`
- `app/Service/ProductAvailabilityService.php`
- `tests/Unit/ProductAvailabilityApiTest.php`

## Low-Stock Command

`scripts/check-low-stock.php` prints low-stock rows from `LowStockService` and exits with success when no low-stock rows exist.

Evidence:
- `scripts/check-low-stock.php`
- `app/Service/LowStockService.php`
- `tests/Unit/LowStockPolicyTest.php`

## Reusable Behavior Patterns

- Form submissions show loading states.
- State-changing forms require confirmation.
- Create/edit links can load into modal dialogs.
- Data tables get client-side search, sorting, CSV export, and empty-state enhancement.
- Server-side list pagination appears for products, purchase orders, and sales orders.

Evidence:
- `public/assets/js/app.js`
- `views/products/index.php`
- `views/purchase-orders/index.php`
- `views/sales-orders/index.php`

## Gaps / Unknowns

- JSON API surface currently appears limited to product availability.
