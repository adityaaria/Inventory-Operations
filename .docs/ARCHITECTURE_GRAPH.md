# Architecture Graph

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Runtime Graph

`Dockerfile` creates a PHP CLI app image, installs Composer dependencies, enables `pdo_mysql`, and starts `php -S` with `public/router.php`.

`compose.yaml` runs:
- `app`, exposing `${APP_PORT:-8080}:8080`
- `db`, using `mysql:8.0`, a healthcheck, and schema initialization from `database/schema-and-seed.sql`

Evidence:
- `Dockerfile`
- `compose.yaml`
- `public/router.php`
- `public/index.php`

## HTTP Graph

`Request::fromGlobals()` creates a request object. `Router` maps exact `GET`/`POST` paths plus a special `/api/products/{sku}/availability` match. Controllers return `Response` instances. `public/index.php` handles CSRF, dispatch, browser/API error rendering, request audit recording, and final response sending.

Evidence:
- `public/index.php`
- `app/Http/Request.php`
- `app/Http/Router.php`
- `app/Http/Response.php`
- `app/Http/ErrorResponder.php`

## Dependency Graph

`public/index.php` manually constructs PDO, repositories, services, guards, audit logging, controllers, and routes. There is no DI container.

Dependency direction:
- Controller depends on Service, Repository read helpers, and `AuthGuard`.
- Service depends on Repository interfaces and domain entities.
- Repository implementations depend on PDO and map SQL rows to entities.
- Views receive data from controllers and use escaped output.

Evidence:
- `public/index.php`
- `app/Controller/ProductController.php`
- `app/Service/ProductService.php`
- `app/Repository/Contract/ProductRepositoryInterface.php`
- `app/Repository/MySql/MySqlProductRepository.php`

## Stock Transaction Graph

`PurchaseOrderService::receive` and `SalesOrderService::issue` create `StockMovement` objects and call `StockService`. `StockService` validates common input, sorts movements by product id, begins a stock repository transaction, locks each `(product_id, warehouse_id)` row, mutates quantity, appends stock ledger rows, executes an order-state callback, then commits or rolls back.

Evidence:
- `app/Service/StockService.php`
- `app/Service/PurchaseOrderService.php`
- `app/Service/SalesOrderService.php`
- `app/Repository/Contract/StockRepositoryInterface.php`
- `app/Repository/MySql/MySqlStockRepository.php`
- `app/Repository/MySql/MySqlStockLedgerRepository.php`
- `tests/Unit/StockServiceReceiptTest.php`
- `tests/Unit/StockServiceIssueTest.php`
- `tests/Integration/PurchaseOrderReceiptIntegrationTest.php`
- `tests/Integration/SalesOrderIssueIntegrationTest.php`

## Security Graph

`NativeSessionManager` owns session auth state and CSRF token lifecycle. `AuthGuard` requires authentication or user-management permission. `Authorization` centralizes user-management permission. Feature services enforce domain-specific role authorization.

Evidence:
- `app/Security/NativeSessionManager.php`
- `app/Security/AuthGuard.php`
- `app/Security/Authorization.php`
- `app/Service/MasterDataAuthorizationService.php`
- `app/Service/PurchaseOrderService.php`
- `app/Service/SalesOrderService.php`
- `public/index.php`

## Boundary Anti-Patterns

- Boundary Anti-Pattern: stock changes outside `StockService` would bypass lock, ledger, and rollback coordination.
- Boundary Anti-Pattern: route authorization only in views would bypass service/controller checks.
- Boundary Anti-Pattern: SQL in controllers or services would bypass repository contract boundaries.

Evidence:
- `AGENTS.md`
- `app/Service/StockService.php`
- `app/Repository/Contract/*Interface.php`
- `app/Controller/*Controller.php`

## Gaps / Unknowns

- Request audit records all handled responses after dispatch, but exact audited action mapping should be checked in `app/Support/RequestAuditRecorder.php` before changing audit behavior.
