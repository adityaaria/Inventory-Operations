# Phase 2 Master Data and Multi-Warehouse Stock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement category, product, warehouse, supplier, customer, and read-only multi-warehouse stock management.

**Architecture:** Controllers handle HTTP only; services enforce validation, authorization, and deactivation policy; repositories own PDO SQL behind interfaces. Product stock is readable in this phase, but operational stock mutation remains reserved for later `StockService` transaction workflows.

**Tech Stack:** PHP 8.2+, Composer PSR-4, MySQL 8, PDO prepared statements, PHPUnit 10, PHPStan level 5, HTML, custom CSS, Vanilla JavaScript.

## Global Constraints

- PHP 8.2+ Native OOP.
- MySQL 8 + PDO prepared statements.
- Controller -> Service -> Repository separation.
- Repository interface boundary with manual constructor injection.
- HTML + custom CSS + Vanilla JS + Fetch API.
- Docker Compose.
- PHPUnit unit + integration tests.
- PHPStan level 5+ preferred.
- No Laravel/CodeIgniter/Symfony/Slim/ORM/framework DI container.
- No React/Vue/Angular/jQuery/CSS framework/admin template.
- Never mutate stock outside stock service transaction + ledger.
- Never place authorization only in UI.
- Phase 2 must not implement Purchase Orders, Sales Orders, dashboard, reports, API, low-stock job, CSV import, MinIO, or operational stock mutation.

---

## File Structure Map

- Modify `database/schema-and-seed.sql`: add categories, warehouses, products, product_stocks, suppliers, customers, constraints, indexes, and demo rows.
- Create entities in `app/Entity`: `Category.php`, `Warehouse.php`, `Product.php`, `ProductStock.php`, `Supplier.php`, `Customer.php`.
- Create repository contracts in `app/Repository/Contract`: `CategoryRepositoryInterface.php`, `WarehouseRepositoryInterface.php`, `ProductRepositoryInterface.php`, `SupplierRepositoryInterface.php`, `CustomerRepositoryInterface.php`.
- Create MySQL repositories in `app/Repository/MySql` and fake repositories in `app/Repository/InMemory`.
- Create services in `app/Service`: `MasterDataAuthorizationService.php`, `CategoryService.php`, `WarehouseService.php`, `ProductService.php`, `SupplierService.php`, `CustomerService.php`.
- Create controllers in `app/Controller`: `CategoryController.php`, `WarehouseController.php`, `ProductController.php`, `SupplierController.php`, `CustomerController.php`.
- Create views under `views/categories`, `views/warehouses`, `views/products`, `views/suppliers`, `views/customers`.
- Modify `public/index.php`: wire repositories, services, controllers, and routes.
- Add tests: `tests/Unit/ProductServiceTest.php`, `tests/Unit/MasterDataAuthorizationTest.php`, `tests/Unit/ProductSearchTest.php`, `tests/Integration/MasterDataRepositoryIntegrationTest.php`.
- Update `docs/testing/test-scenarios.md`, `docs/quality/tech-debt.md`, `ai-usage-log.md`.

---

### Task 1: Schema and Demo Data

**Files:**
- Modify: `database/schema-and-seed.sql`
- Test: `tests/Integration/MasterDataRepositoryIntegrationTest.php`

**Interfaces:**
- Consumes: Phase 1 users and auth roles.
- Produces: tables `categories`, `warehouses`, `products`, `product_stocks`, `suppliers`, `customers`.

- [ ] Add failing integration assertions that unique SKU, active warehouses, and product stock rows can be queried from MySQL.
- [ ] Extend SQL with FK constraints, unique SKU, non-negative checks, and unique `(product_id, warehouse_id)`.
- [ ] Seed at least two warehouses and enough starter rows to show one product with different quantities in two warehouses.
- [ ] Run `docker compose run --rm app composer test -- --filter MasterDataRepositoryIntegrationTest`.
- [ ] Commit with `feat: add master data schema`.

### Task 2: Entities and Repository Contracts

**Files:**
- Create: entity and repository interface files listed in the file map
- Test: `tests/Unit/ProductSearchTest.php`

**Interfaces:**
- Produces: `ProductRepositoryInterface::search(ProductSearchCriteria $criteria): PaginatedResult`, `findById(int $id): ?Product`, `create(...)`, `update(...)`, `setActive(...)`.

- [ ] Add unit tests for product entity fields, low-stock calculation, and search criteria defaults.
- [ ] Create entities with typed getters and role-neutral domain state only.
- [ ] Create small search/pagination value objects if needed under `app/Support`.
- [ ] Create repository contracts with return types documented as `list<T>` or pagination result objects.
- [ ] Run `composer test -- --filter ProductSearchTest`.
- [ ] Commit with `feat: add master data contracts`.

### Task 3: Repositories

**Files:**
- Create: MySQL and InMemory repositories for master data
- Test: `tests/Unit/ProductRepositoryTest.php`

**Interfaces:**
- Consumes: contracts from Task 2.
- Produces: prepared-statement persistence for CRUD/read operations and fake repositories for unit tests.

- [ ] Add fake repository tests for duplicate SKU rejection and product pagination.
- [ ] Implement InMemory repositories for service tests.
- [ ] Implement MySQL repositories using `prepare()` for every user-controlled parameter.
- [ ] Keep sort columns allow-listed inside repository query construction.
- [ ] Run `composer test -- --filter ProductRepositoryTest`.
- [ ] Commit with `feat: add master data repositories`.

### Task 4: Services and Authorization

**Files:**
- Create: master-data services listed in file map
- Test: `tests/Unit/ProductServiceTest.php`, `tests/Unit/MasterDataAuthorizationTest.php`

**Interfaces:**
- Consumes: `AuthContext`, `Authorization`, master-data repositories.
- Produces: Admin write operations; Sales catalog read; Warehouse Staff stock read.

- [ ] Add tests proving Sales and Warehouse Staff cannot create/update/deactivate master data.
- [ ] Add tests for duplicate SKU, negative price, negative reorder point, required name/unit, and negative stock rejection.
- [ ] Implement service validation and role checks.
- [ ] Ensure service methods do not read superglobals or instantiate PDO.
- [ ] Run `composer test -- --filter 'ProductServiceTest|MasterDataAuthorizationTest'`.
- [ ] Commit with `feat: add master data services`.

### Task 5: Controllers, Views, and Routes

**Files:**
- Create: controllers/views listed in file map
- Modify: `public/index.php`, `public/assets/css/app.css`

**Interfaces:**
- Consumes: services and Phase 1 `AuthGuard`.
- Produces: routes for product/category/warehouse/supplier/customer list, create, edit, update, and status actions.

- [ ] Add controller tests or route smoke tests for product list and Admin-only create route.
- [ ] Wire list/detail/create/edit/status routes.
- [ ] Render escaped table cells and form values with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- [ ] Add product search by name/SKU, category filter, low-stock/normal filter, and 10-row pagination.
- [ ] Keep UI minimal and custom CSS only.
- [ ] Run `composer test -- --filter Product`.
- [ ] Commit with `feat: add master data screens`.

### Task 6: Verification and Evidence

**Files:**
- Modify: `docs/testing/test-scenarios.md`, `docs/quality/phpstan-report.txt`, `docs/quality/tech-debt.md`, `ai-usage-log.md`

**Interfaces:**
- Consumes: all Phase 2 implementation.
- Produces: verified evidence for `PRD-01`, `WH-01`, `VIEW-01`, partial `FIND-01`, `VAL-01`, `DB-01`.

- [ ] Run `composer test -- --filter Product`.
- [ ] Run `composer test -- --filter Warehouse`.
- [ ] Run `composer test -- --filter MasterData`.
- [ ] Run `composer test`.
- [ ] Run `composer analyse > docs/quality/phpstan-report.txt`.
- [ ] Run `docker compose up --build` and verify one product shows different stock in two warehouses.
- [ ] Update test scenarios with pass/fail evidence, never fabricated output.
- [ ] Commit with `test: verify phase 2 master data`.

## Self-Review

This plan covers schema, repositories, services, controllers/views, authorization, validation, search/filter/pagination, seed data, tests, and evidence for Phase 2. It excludes operational stock mutation and later workflows.

## Execution Handoff

Plan complete and saved to `docs/spark/plans/2026-08-31-phase-2-master-data-stock.md`. Use Subagent-Driven or Inline Execution before implementation.
