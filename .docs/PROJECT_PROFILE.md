# Project Profile

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Technology Stack

- Runtime: PHP 8.2 requirement, PHP 8.3 CLI Docker image.
- Persistence: MySQL 8.0 with PDO and `pdo_mysql`.
- Dependency management: Composer 2.
- Testing: PHPUnit 10.5 with Unit and Integration suites.
- Static analysis: PHPStan level 5 against `app`, `config`, and `public`.
- Frontend: HTML/PHP views, custom CSS, Vanilla JavaScript.
- Deployment/local runtime: Docker Compose with app and MySQL services.

Evidence:
- `composer.json`
- `Dockerfile`
- `compose.yaml`
- `phpunit.xml`
- `phpstan.neon`
- `public/assets/css/app.css`
- `public/assets/js/app.js`

## Application Purpose

The application manages inventory, purchasing, sales fulfillment, stock movement, reporting, and internal user access for a final assessment inventory/order management system.

Evidence:
- `README.md`
- `public/index.php`
- `database/schema-and-seed.sql`
- `app/Service/PurchaseOrderService.php`
- `app/Service/SalesOrderService.php`
- `app/Service/StockService.php`

## Presentation Summary

Each page is a standalone PHP view that links `/assets/css/app.css` and `/assets/js/app.js`. Most pages expose a `<main class="page">` root. The JavaScript builds an app shell around non-auth pages, enhances forms, adds confirmation dialogs, opens create/edit pages in a modal, enhances `table.data-table`, and renders dashboard chart rows from `data-chart`.

Evidence:
- `views/products/index.php`
- `views/purchase-orders/index.php`
- `views/sales-orders/index.php`
- `views/dashboard/index.php`
- `views/auth/login.php`
- `public/assets/js/app.js`

## Standard Skeleton Summary

- Application source is grouped by `app/Controller`, `app/Service`, `app/Repository`, `app/Entity`, `app/Security`, `app/Http`, `app/Support`, `app/Validation`, and `app/Exception`.
- Repository contracts live in `app/Repository/Contract`.
- Runtime MySQL repository implementations live in `app/Repository/MySql`.
- Test doubles live in `app/Repository/InMemory`.
- Browser views live in `views/<feature>`.
- Public assets live in `public/assets/css` and `public/assets/js`.
- Tests are split into `tests/Unit` and `tests/Integration`.

Evidence:
- `app/`
- `views/`
- `tests/`

## Generation Safety

- `Copy-safe`: `app/Http/Request.php`, `app/Http/Response.php`, `app/Http/Router.php`, `app/Support/PaginatedResult.php`, `app/Support/Html.php`, `app/Support/Config.php` as small infrastructure patterns with limited dependencies.
- `Stub-safe`: feature controllers, services, repository interfaces, MySQL repositories, and views because their shape is reusable but bodies are domain-specific.
- `Business-specific`: order statuses, roles, product/stock/ledger terms, database table names, and seeded demo data.

## Gaps / Unknowns

- No active shared view layout abstraction exists.
- There is no framework routing metadata outside explicit route registration in `public/index.php`.
