# Phase 4 Sales Order and Concurrency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Sales Order lifecycle, Admin approval, Warehouse/Admin goods issue, and oversell-safe stock decrement.

**Architecture:** `SalesOrderService` owns SO ownership, status transitions, and approval rules. `StockService` performs issue transactions with deterministic row locks and ledger writes.

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
- Sales must never approve/reject Sales Orders.
- Phase 4 must not implement stock reservation, invoices, payments, carriers, audit trail, or idempotency.

---

## File Structure Map

- Modify `database/schema-and-seed.sql`: add sales_orders and sales_order_items.
- Create entities: `SalesOrder.php`, `SalesOrderItem.php`.
- Create contract/repositories: `SalesOrderRepositoryInterface.php`, MySQL/InMemory implementations.
- Modify `StockService.php`: add issue operation.
- Create service/controller/views: `SalesOrderService.php`, `SalesOrderController.php`, `views/sales-orders/*`.
- Modify `public/index.php`: wire SO routes.
- Add tests: `SalesOrderServiceTest.php`, `StockServiceIssueTest.php`, `SalesOrderIssueIntegrationTest.php`.
- Update decisions/evidence docs.

---

### Task 1: Record SO Ambiguity Decisions

**Files:**
- Modify: `docs/planning/DECISIONS_PENDING.md`

**Interfaces:**
- Produces explicit temporary defaults for rejection mapping, Admin self-approval, and duplicate item handling.

- [ ] Record temporary default: reject action maps to `Cancelled` until trainer confirms another representation.
- [ ] Record temporary default: Admin may approve Admin-created SO, with `approved_by` and `approved_at` retained.
- [ ] Record temporary default: duplicate item lines are rejected for PO/SO forms.
- [ ] Commit with `docs: record sales order decisions`.

### Task 2: SO Schema and Entities

**Files:**
- Modify: `database/schema-and-seed.sql`
- Create: `app/Entity/SalesOrder.php`, `app/Entity/SalesOrderItem.php`
- Test: `tests/Unit/SalesOrderEntityTest.php`

**Interfaces:**
- Produces statuses `Draft`, `PendingApproval`, `Approved`, `Fulfilled`, `Cancelled`.

- [ ] Add tests for status constants, ownership user id, and item quantity/price fields.
- [ ] Add tables with unique order number, FK customer/source warehouse/creator/approver, status enum, positive item quantity, non-negative selling price.
- [ ] Implement immutable entities with typed getters.
- [ ] Run `composer test -- --filter SalesOrderEntityTest`.
- [ ] Commit with `feat: add sales order schema entities`.

### Task 3: SO Repository Boundary

**Files:**
- Create: `app/Repository/Contract/SalesOrderRepositoryInterface.php`, MySQL/InMemory implementations
- Test: `tests/Unit/SalesOrderRepositoryTest.php`

**Interfaces:**
- Produces: `createDraft(...)`, `findById(...)`, `submit(...)`, `approve(...)`, `cancel(...)`, `markFulfilled(...)`.

- [ ] Add fake repository tests for create, submit, approve, cancel, and mark fulfilled.
- [ ] Implement InMemory repository for service tests.
- [ ] Implement MySQL repository with prepared statements and transaction-friendly update methods.
- [ ] Run `composer test -- --filter SalesOrderRepositoryTest`.
- [ ] Commit with `feat: add sales order repositories`.

### Task 4: Sales Order Service

**Files:**
- Create: `app/Service/SalesOrderService.php`
- Test: `tests/Unit/SalesOrderServiceTest.php`

**Interfaces:**
- Produces: `createDraft(...)`, `submit(...)`, `approve(...)`, `rejectOrCancel(...)`, `issue(...)`.

- [ ] Add tests: Sales cannot approve, Sales manages only own orders, cannot issue unless Approved, cannot transition Fulfilled to Draft.
- [ ] Enforce Admin-only approval/rejection server-side.
- [ ] Enforce Sales ownership for create/edit/submit.
- [ ] Validate customer, warehouse, product, quantity, price, and duplicate lines.
- [ ] Run `composer test -- --filter SalesOrderServiceTest`.
- [ ] Commit with `feat: add sales order service`.

### Task 5: Issue Transaction and Ledger

**Files:**
- Modify: `app/Service/StockService.php`, stock/ledger repositories
- Test: `tests/Unit/StockServiceIssueTest.php`

**Interfaces:**
- Produces: `StockService::issue(int $warehouseId, list<StockMovement> $movements, int $performedBy, string $referenceType, int $referenceId): void`.

- [ ] Add tests for insufficient stock rejection and deterministic lock order.
- [ ] Implement row locks with `SELECT ... FOR UPDATE`.
- [ ] Validate stock after locks.
- [ ] Decrement product stock and append `Issue` ledger rows in one transaction.
- [ ] Roll back on any failure.
- [ ] Run `composer test -- --filter StockServiceIssueTest`.
- [ ] Commit with `feat: add issue stock transaction`.

### Task 6: SO Controllers and Views

**Files:**
- Create: `app/Controller/SalesOrderController.php`, `views/sales-orders/index.php`, `show.php`, `create.php`
- Modify: `public/index.php`

**Interfaces:**
- Produces routes from SDD: list, show, create, submit, approve, reject-or-cancel, issue.

- [ ] Add controller/route smoke tests for Sales own list and Admin approval route.
- [ ] Wire routes through `AuthGuard` and `SalesOrderService`.
- [ ] Render escaped list/detail/form output.
- [ ] Return safe 403/409/422 responses for forbidden, invalid-state, and validation failures.
- [ ] Run `composer test -- --filter SalesOrder`.
- [ ] Commit with `feat: add sales order screens`.

### Task 7: Oversell Integration and Evidence

**Files:**
- Create: `tests/Integration/SalesOrderIssueIntegrationTest.php`
- Modify: `docs/testing/test-scenarios.md`, `docs/quality/phpstan-report.txt`, `ai-usage-log.md`

**Interfaces:**
- Produces evidence that second issue cannot oversell.

- [ ] Add MySQL integration test where first issue consumes stock and second issue fails.
- [ ] Assert final stock is never negative.
- [ ] Assert no extra ledger row is created for failed issue.
- [ ] Run `composer test -- --filter SalesOrderIssueIntegrationTest`.
- [ ] Run `composer test` and `composer analyse > docs/quality/phpstan-report.txt`.
- [ ] Update evidence docs with real results.
- [ ] Commit with `test: verify sales order concurrency`.

## Self-Review

This plan covers SO lifecycle, authorization, segregation of duties, issue transaction, oversell integration, decisions, views, and evidence without optional scope.

## Execution Handoff

Plan complete and saved to `docs/spark/plans/2026-08-31-phase-4-sales-order-concurrency.md`. Use Subagent-Driven or Inline Execution before implementation.
