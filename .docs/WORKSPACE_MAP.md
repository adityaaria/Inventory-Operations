# Workspace Map

Last Scanned: 2026-09-09

Confidence: Confirmed from Code

## Root Files

- `composer.json`: PHP requirements, autoloading, PHPUnit/PHPStan scripts.
- `compose.yaml`: app/db services and schema mount.
- `Dockerfile`: PHP CLI runtime build.
- `phpunit.xml`: Unit and Integration test suites.
- `phpstan.neon`: PHPStan level 5 paths and cache directory.
- `README.md`: setup, credentials, checks, evidence references.
- `AGENTS.md`: repository operating contract.
- `SDD.md`: design/documentation context.

Evidence:
- `composer.json`
- `compose.yaml`
- `Dockerfile`
- `phpunit.xml`
- `phpstan.neon`
- `README.md`
- `AGENTS.md`
- `SDD.md`

## Application Source

- `app/Controller`: browser and API controllers.
- `app/Controller/Api`: JSON API controller(s).
- `app/Service`: business rules, authorization orchestration, stock transaction orchestration.
- `app/Repository/Contract`: repository interfaces.
- `app/Repository/MySql`: PDO MySQL implementations.
- `app/Repository/InMemory`: test doubles.
- `app/Entity`: immutable domain entities and constants.
- `app/Security`: auth context, session, guard, authorization, CSRF.
- `app/Http`: request, response, router, error response infrastructure.
- `app/Support`: configuration, database factory, pagination, search criteria, CSV, logging, audit helpers.
- `app/Validation`: input validation.
- `app/Exception`: HTTP/auth/validation exception types.

Evidence:
- `app/`

## Presentation

- `views/auth`: login page.
- `views/dashboard`: dashboard page.
- `views/products`, `views/categories`, `views/warehouses`, `views/suppliers`, `views/customers`, `views/users`: master data and user pages.
- `views/purchase-orders`, `views/sales-orders`: order list/create/show workflows.
- `views/reports`: report download page.
- `views/errors`: browser error pages.
- `public/assets/css/app.css`: shared styling.
- `public/assets/js/app.js`: progressive enhancements.

Evidence:
- `views/`
- `public/assets/css/app.css`
- `public/assets/js/app.js`

## Persistence And Runtime

- `database/schema-and-seed.sql`: schema, constraints, indexes, demo data, and phase markers.
- `config/config.php`: environment-backed config defaults.
- `config/bootstrap.php`: autoload, error reporting, global exception handler, logger.
- `scripts/check-low-stock.php`: CLI low-stock command.

Evidence:
- `database/schema-and-seed.sql`
- `config/config.php`
- `config/bootstrap.php`
- `scripts/check-low-stock.php`

## Tests And Evidence

- `tests/Unit`: domain, service, controller, infrastructure, and security unit tests.
- `tests/Integration`: MySQL-backed repository, schema, seed, stock transaction, and audit tests.
- `docs/architecture`: ADRs and class diagrams.
- `docs/testing`: test scenarios and results.
- `docs/quality`: quality reports and audits.
- `docs/planning`: planning, scope, release readiness, and pending decisions.

Evidence:
- `tests/`
- `docs/architecture/`
- `docs/testing/`
- `docs/quality/`
- `docs/planning/`

## Copy-Safety Boundaries

- `vendor`, `var/phpstan`, and `.phpunit.cache` are generated/dependency/cache areas.
- `database/schema-and-seed.sql` is business-specific and should not be mirrored blindly.
- `views/*` combine presentation shape with domain labels and workflow-specific actions.

## Gaps / Unknowns

- No `.env` is expected in source; `.env.example` exists for local configuration.
