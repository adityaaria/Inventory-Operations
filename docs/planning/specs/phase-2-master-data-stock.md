# Phase 2 Specification: Master Data and Multi-Warehouse Stock

Status: Approved for planning  
Date: 2026-08-31  
References: `AGENTS.md`, `SDD.md`, `PLAN.md`, `docs/planning/CONSTITUTION.md`

## 1. Purpose

Phase 2 adds master data and a read-only multi-warehouse stock view model. It creates the catalog foundation required before Purchase Orders and Sales Orders can move inventory.

Exit gate: at least one product shows different stock quantities across two warehouses.

## 2. Requirement Trace

- `PRD-01`: product/category management, SKU uniqueness, optional safe image upload.
- `WH-01`: warehouse data and `ProductStock` per product/warehouse.
- `VIEW-01`: data and empty states for master-data pages.
- `FIND-01`: product search/filter/pagination foundation.
- `VAL-01`: backend validation for master data and uploads.
- `DB-01`: constraints, indexes, and seed expansion.
- `ARCH-01`: repository contracts and fake repositories for unit tests.

## 3. In Scope

- Category, product, warehouse, supplier, and customer schema.
- `product_stocks` table with unique `(product_id, warehouse_id)` and non-negative quantity.
- Repository interfaces and MySQL/InMemory implementations for master data.
- Service layer for create/update/deactivate and read operations.
- Admin master-data management.
- Sales read-only catalog access.
- Warehouse Staff read-only product and stock access.
- Product list with search by name/SKU, category filter, low-stock/normal filter, and 10-row pagination.
- Product detail with total stock and warehouse breakdown.
- Optional local product image upload with MIME/size validation and randomized stored filename.
- Seed expansion toward the required demo dataset.

## 4. Out of Scope

- Stock mutation from receipt/issue/adjustment/transfer.
- Purchase Order and Sales Order workflows.
- Dashboard aggregation, CSV reports, JSON API, low-stock command.
- CSV import and MinIO adapter.

## 5. Architecture Contract

Controllers handle route input/output. Services enforce permissions, validation, deactivation policy, and read-model composition. Repositories execute prepared SQL and map rows.

Product stock quantities must not be mutated by master-data screens except through explicit seed/setup routines. Operational stock changes are reserved for later `StockService` transaction workflows.

## 6. Data Contract

Add or complete:

- `categories`: id, name, description nullable, timestamps.
- `warehouses`: id, name, location, is_active, timestamps.
- `products`: id, sku unique, name, category_id, unit, purchase_price, selling_price, reorder_point, image_path nullable, is_active, timestamps.
- `product_stocks`: id, product_id, warehouse_id, quantity >= 0, updated_at, unique `(product_id, warehouse_id)`.
- `suppliers`: id, name, contact, address, is_active, timestamps.
- `customers`: id, name, contact, address, is_active, timestamps.

Referenced products, suppliers, and customers should be deactivated rather than physically deleted once transactions exist.

## 7. Authorization Rules

- Admin may create, update, and deactivate master data.
- Sales may read catalog data needed for Sales Orders.
- Warehouse Staff may read product and warehouse stock data.
- Server must reject unauthorized create/update/deactivate attempts.

## 8. Validation and Errors

- SKU required and unique.
- Product name and unit required.
- Category must exist and be active.
- Prices and reorder point must be numeric and `>= 0`.
- Stock quantity must never be negative.
- Uploaded product image must pass MIME/type and size checks.
- Stored image filename must be random/non-guessable and must not trust original filename.
- Sort columns must use server-side allow-lists.
- Missing records return 404; validation failures return 422/form feedback.

## 9. Tests

Required tests:

- Duplicate SKU is rejected.
- Product prices and reorder point cannot be negative.
- Product stock cannot be negative.
- Product list search finds SKU/name.
- Category and low-stock filters apply correctly.
- Pagination limits results to 10 per page.
- Sales cannot write master data.
- Warehouse Staff cannot write master data.

Integration tests should verify unique SKU, FK constraints, product stock unique pair, and seeded product/warehouse stock reads.

## 10. Acceptance Criteria

1. Admin can manage categories, products, warehouses, suppliers, and customers.
2. Sales and Warehouse Staff read access matches the authorization matrix.
3. Product list supports search/filter/pagination.
4. Product detail displays total stock and per-warehouse stock.
5. At least one product demonstrates different stock in two warehouses.
6. Master-data validation is enforced server-side.
7. No operational stock mutation bypasses `StockService`.
8. Tests and PHPStan remain acceptable.

## 11. Verification Commands

```bash
composer test -- --filter Product
composer test -- --filter Warehouse
composer test -- --filter MasterData
composer test
composer analyse
docker compose up --build
```

## 12. Risks and Decisions

- Duplicate product lines are a later PO/SO decision; Phase 2 must not decide transaction item behavior.
- Product image upload is optional in the brief; implement only if it does not destabilize mandatory CRUD and validation.
- Low-stock semantics for dashboard remain pending; Phase 2 may use per-product/warehouse `quantity < reorder_point` for product filters.

## 13. Handoff to Implementation Plan

Plan tasks should implement schema, repositories, services, controllers/views, validation, seed data, tests, and evidence updates in that order.
