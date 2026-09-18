# Project Scan

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Project Root

This repository root is a single native PHP inventory and order management application.

Evidence:
- `composer.json`
- `public/index.php`
- `config/bootstrap.php`
- `database/schema-and-seed.sql`
- `phpunit.xml`
- `phpstan.neon`
- `compose.yaml`
- `Dockerfile`

## Durable Summary

- PHP 8.2+ native OOP application using PSR-4 autoloading under `App\\`.
- MySQL 8 persistence through PDO repositories and prepared statements.
- Manual dependency construction happens in `public/index.php`.
- HTTP requests enter through PHP built-in server routing to `public/router.php` and `public/index.php`.
- The application uses explicit Controller -> Service -> Repository boundaries.
- UI is server-rendered PHP views with shared custom CSS and progressive Vanilla JS.
- Domain centers on users, master data, purchase orders, sales orders, stock, stock ledger, dashboards, reports, CSV import/export, and low-stock checks.

## Generated Memory Contract

The scanner memory for this root is contained in exactly:

- `.docs/PROJECT_SCAN.md`
- `.docs/PROJECT_PROFILE.md`
- `.docs/ARCHITECTURE_GRAPH.md`
- `.docs/WORKSPACE_MAP.md`
- `.docs/FEATURE_MAP.md`
- `.docs/API_CONTRACT.md`
- `.docs/DATABASE_CATALOG.md`
- `.docs/BUSINESS_FLOW.md`
- `.docs/DOMAIN_MAP.md`
- `.docs/TESTING_STRATEGY.md`

## Gaps / Unknowns

- No generated OpenAPI or machine-readable API schema was found.
- No migrations directory was found; schema and seed are consolidated in `database/schema-and-seed.sql`.
- Views repeat full document shell markup; no active shared PHP layout file was found beyond `views/layouts/.gitkeep`.
