# API Contract

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Browser Routes

Routes are registered manually in `public/index.php`.

Authentication:
- `GET /login`
- `POST /login`
- `POST /logout`

Dashboard and reports:
- `GET /`
- `GET /dashboard`
- `GET /reports`
- `GET /reports/stock-ledger.csv`
- `GET /reports/orders.csv`

Users:
- `GET /users`
- `GET /users/create`
- `POST /users`
- `POST /users/import`
- `GET /users/edit`
- `POST /users/update`
- `POST /users/activate`
- `POST /users/deactivate`

Master data:
- Same list/create/store/import/edit/update/activate/deactivate route shape for categories, warehouses, products, suppliers, and customers.

Purchase orders:
- `GET /purchase-orders`
- `GET /purchase-orders/show`
- `GET /purchase-orders/create`
- `POST /purchase-orders`
- `POST /purchase-orders/order`
- `POST /purchase-orders/receive`
- `POST /purchase-orders/cancel`

Sales orders:
- `GET /sales-orders`
- `GET /sales-orders/show`
- `GET /sales-orders/create`
- `POST /sales-orders`
- `POST /sales-orders/submit`
- `POST /sales-orders/approve`
- `POST /sales-orders/cancel`
- `POST /sales-orders/issue`

Evidence:
- `public/index.php`

## JSON API

`GET /api/products/{sku}/availability`

Behavior:
- requires authenticated session
- returns HTTP 401 JSON for unauthenticated users
- returns HTTP 404 JSON when SKU is not found
- returns product availability payload for existing SKU

Evidence:
- `public/index.php`
- `app/Http/Router.php`
- `app/Controller/Api/ProductAvailabilityController.php`
- `app/Service/ProductAvailabilityService.php`
- `app/Repository/Contract/OperationalQueryRepositoryInterface.php`
- `tests/Unit/ProductAvailabilityApiTest.php`

## Request And Response Conventions

- Non-API browser failures render HTML through `ErrorResponder::browser`.
- API failures render JSON through `ErrorResponder::api` or controller JSON helpers.
- POST requests require a valid CSRF token in `csrf_token`.
- Controllers redirect successful POST operations with HTTP 302.
- Validation failures commonly re-render forms with HTTP 422.

Evidence:
- `public/index.php`
- `app/Http/ErrorResponder.php`
- `app/Controller/ProductController.php`
- `app/Controller/PurchaseOrderController.php`
- `app/Controller/SalesOrderController.php`
- `tests/Unit/ErrorResponseTest.php`
- `tests/Unit/SecurityAuditTest.php`

## CSV Exports

- `GET /reports/stock-ledger.csv`
- `GET /reports/orders.csv`

Both accept optional `from` and `to` date parameters in `YYYY-MM-DD` format. `orders.csv` is scoped to the sales actor for Sales role users.

Evidence:
- `app/Controller/ReportController.php`
- `app/Service/ReportService.php`
- `tests/Unit/ReportServiceTest.php`

## Gaps / Unknowns

- No OpenAPI document, route manifest, or generated schema was found.
- Exact JSON payload fields for availability should be read from `ProductAvailabilityService` and `MySqlOperationalQueryRepository` before changing clients.
