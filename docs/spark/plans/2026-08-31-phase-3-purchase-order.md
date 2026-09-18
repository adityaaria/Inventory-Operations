# Phase 3 Purchase Order Receipt Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Purchase Order creation, ordering, partial/full receipt, and atomic Receipt ledger updates.

**Architecture:** `PurchaseOrderService` owns PO state transitions and delegates stock mutation to `StockService`. `StockService` owns transactions, row locks, product stock updates, and ledger writes; controllers remain HTTP-only.

**Tech Stack:** PHP 8.2+, MySQL 8/InnoDB row locks, PDO prepared statements, PHPUnit 10, PHPStan level 5, Docker Compose.

## Global Constraints

- PHP 8.2+ Native OOP.
- MySQL 8 + PDO prepared statements.
- Controller -> Service -> Repository separation.
- Repository interface boundary with manual constructor injection.
- Docker Compose.
- PHPUnit unit + integration tests.
- PHPStan level 5+ preferred.
- No Laravel/CodeIgniter/Symfony/Slim/ORM/framework DI container.
- Never mutate stock outside stock service transaction + ledger.
- Acquire multiple stock locks in deterministic `(warehouse_id, product_id)` order.
- Phase 3 must not implement Sales Orders, goods issue, dashboard, reports, API, low-stock job, audit trail, or idempotency.

---

## File Structure Map

- Modify `database/schema-and-seed.sql`: add purchase_orders, purchase_order_items, stock_ledger if absent.
- Create entities: `PurchaseOrder.php`, `PurchaseOrderItem.php`, `StockLedgerEntry.php`.
- Create contracts: `PurchaseOrderRepositoryInterface.php`, `StockRepositoryInterface.php`, `StockLedgerRepositoryInterface.php`.
- Create repositories: MySQL/InMemory PO, stock, and ledger repositories.
- Create services: `PurchaseOrderService.php`, `StockService.php`.
- Create controller/views: `PurchaseOrderController.php`, `views/purchase-orders/*`.
- Modify `public/index.php`: wire PO routes.
- Add tests: `PurchaseOrderServiceTest.php`, `StockServiceReceiptTest.php`, `PurchaseOrderReceiptIntegrationTest.php`.
- Update docs/evidence.

---

### Task 1: PO and Ledger Schema

**Files:**
- Modify: `database/schema-and-seed.sql`
- Test: `tests/Integration/PurchaseOrderSchemaIntegrationTest.php`

**Interfaces:**
- Produces: PO statuses `Draft`, `Ordered`, `PartiallyReceived`, `Received`, `Cancelled`; ledger movement `Receipt`.

- [ ] Write integration test that asserts PO tables, item constraints, and ledger table exist.
- [ ] Add SQL tables with FK constraints, unique order number, positive quantities, non-negative prices, and `received_quantity <= quantity`.
- [ ] Add indexes for order number, status/date, and ledger reference.
- [ ] Run `docker compose run --rm app composer test -- --filter PurchaseOrderSchemaIntegrationTest`.
- [ ] Commit with `feat: add purchase order schema`.

### Task 2: PO Entities and Repositories

**Files:**
- Create: entity, contract, MySQL, and InMemory files listed above
- Test: `tests/Unit/PurchaseOrderRepositoryTest.php`

**Interfaces:**
- Produces: `PurchaseOrderRepositoryInterface::findById(int $id): ?PurchaseOrder`, `createDraft(...)`, `markOrdered(...)`, `recordReceipt(...)`, `cancel(...)`.

- [ ] Add fake repository tests for Draft creation, Ordered transition, and received quantity persistence.
- [ ] Implement PO entities with status constants and item remaining quantity helper.
- [ ] Implement InMemory repository for unit tests.
- [ ] Implement MySQL repository with prepared statements.
- [ ] Run `composer test -- --filter PurchaseOrderRepositoryTest`.
- [ ] Commit with `feat: add purchase order repositories`.

### Task 3: Stock Service Receipt Transaction

**Files:**
- Create/modify: `app/Service/StockService.php`, stock and ledger repositories
- Test: `tests/Unit/StockServiceReceiptTest.php`

**Interfaces:**
- Produces: `StockService::receive(int $warehouseId, list<StockMovement> $movements, int $performedBy, string $referenceType, int $referenceId): void`.

- [ ] Add tests for positive movement validation and deterministic movement ordering.
- [ ] Implement stock lock/update repository methods: `lockByProductWarehouse(int $productId, int $warehouseId)`, `increment(...)`.
- [ ] Implement ledger append method with movement type `Receipt`.
- [ ] Keep transaction begin/commit/rollback inside service orchestration.
- [ ] Run `composer test -- --filter StockServiceReceiptTest`.
- [ ] Commit with `feat: add receipt stock service`.

### Task 4: Purchase Order Service

**Files:**
- Create: `app/Service/PurchaseOrderService.php`
- Test: `tests/Unit/PurchaseOrderServiceTest.php`

**Interfaces:**
- Produces: `createDraft(...)`, `markOrdered(...)`, `receive(...)`, `cancel(...)`.

- [ ] Add tests for partial receipt, full receipt, receipt above remaining, invalid status, and Sales forbidden.
- [ ] Implement Admin/Warehouse Staff receipt authorization.
- [ ] Validate supplier, warehouse, product, quantity, and price rules through service inputs/repositories.
- [ ] Recalculate PO status to `PartiallyReceived` or `Received`.
- [ ] Delegate stock increment and ledger insert to `StockService`.
- [ ] Run `composer test -- --filter PurchaseOrderServiceTest`.
- [ ] Commit with `feat: add purchase order service`.

### Task 5: PO Controllers and Views

**Files:**
- Create: `app/Controller/PurchaseOrderController.php`, `views/purchase-orders/index.php`, `show.php`, `create.php`, `receive.php`
- Modify: `public/index.php`, CSS as needed

**Interfaces:**
- Produces routes: `GET /purchase-orders`, `GET /purchase-orders/{id}`, `GET /purchase-orders/create`, `POST /purchase-orders`, `POST /purchase-orders/{id}/order`, `POST /purchase-orders/{id}/receive`, `POST /purchase-orders/{id}/cancel`.

- [ ] Add route/controller smoke tests for list, create, order, and receive actions.
- [ ] Wire controller methods to service calls with server-side authorization.
- [ ] Render escaped PO list/detail/receipt forms.
- [ ] Show remaining quantities and safe validation messages.
- [ ] Run `composer test -- --filter PurchaseOrder`.
- [ ] Commit with `feat: add purchase order screens`.

### Task 6: Integration, Rollback, and Evidence

**Files:**
- Create: `tests/Integration/PurchaseOrderReceiptIntegrationTest.php`
- Modify: `docs/testing/test-scenarios.md`, `docs/quality/phpstan-report.txt`, `ai-usage-log.md`

**Interfaces:**
- Produces: evidence for full receipt, partial receipt, and rollback safety.

- [ ] Add MySQL integration test for full receipt increments stock and writes `Receipt` ledger.
- [ ] Add MySQL integration test for partial receipt updates `received_quantity` and PO status.
- [ ] Add rollback test that forces ledger failure and asserts stock/item state unchanged.
- [ ] Run `composer test -- --filter PurchaseOrderReceiptIntegrationTest`.
- [ ] Run `composer test` and `composer analyse > docs/quality/phpstan-report.txt`.
- [ ] Update testing evidence with real command results.
- [ ] Commit with `test: verify purchase order receipt`.

## Self-Review

This plan covers PO schema, state machine, receipt transaction, stock ledger, controller/view routes, authorization, validation, integration rollback evidence, and excludes SO/optional scope.

## Execution Handoff

Plan complete and saved to `docs/spark/plans/2026-08-31-phase-3-purchase-order.md`. Use Subagent-Driven or Inline Execution before implementation.
