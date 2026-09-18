# Phase 4 Specification: Sales Order and Concurrency-Safe Goods Issue

Status: Approved for planning  
Date: 2026-08-31  
References: `AGENTS.md`, `SDD.md`, `PLAN.md`, `docs/planning/CONSTITUTION.md`, `docs/architecture/adr-002-stock-concurrency.md`

## 1. Purpose

Phase 4 adds Sales Order lifecycle, Admin approval, and concurrency-safe goods issue. This slice proves that stock cannot be oversold and that segregation of duties is enforced server-side.

Exit gate: a second issue attempt cannot oversell stock and Sales users cannot approve orders.

## 2. Requirement Trace

- `SO-01`: Sales Order workflow from Draft to Fulfilled with cancellation path.
- `ARCH-02`: row-locking transaction for goods issue.
- Segregation of duties: Sales cannot approve/reject.
- `DB-01`: SO, SO item, stock, and ledger persistence.
- `VAL-01`: item quantity/price/state validation.
- `ERR-01`: safe insufficient-stock and invalid-state responses.
- `TEST-01`: SO service unit tests.
- `TEST-02`: MySQL integration test for oversell prevention.

## 3. In Scope

- `sales_orders` and `sales_order_items` schema.
- Sales Order statuses: `Draft`, `PendingApproval`, `Approved`, `Fulfilled`, `Cancelled`.
- Admin/Sales create Draft Sales Orders according to ownership rules.
- Sales may manage only own Sales Orders.
- Submit Draft to PendingApproval.
- Admin approve/reject/cancel mapping according to documented decision.
- Goods issue only from Approved.
- Goods issue decrements stock through `StockService`.
- Issue ledger rows with `movement_type='Issue'`, `reference_type='SO'`, and SO reference ID.
- Insufficient stock rollback with no negative quantity and no extra ledger row.
- Controlled sequential concurrency scenario in integration tests.

## 4. Out of Scope

- Stock reservation.
- Shipment carrier, invoice, payment, or customer credit workflow.
- Audit trail/idempotency unless mandatory scope is already green and separately approved.
- Adding persistent status values outside the fixed brief list without a documented decision.

## 5. Architecture Contract

`SalesOrderController` handles HTTP only. `SalesOrderService` owns ownership checks, state transitions, approval/rejection/cancellation rules, and issue orchestration. `StockService` owns stock decrement and ledger writes inside the transaction.

Repository code must use prepared statements and must not enforce business workflow in SQL-only shortcuts.

## 6. Data Contract

Add or complete:

- `sales_orders`: id, order_number unique, customer_id, source_warehouse_id, status, order_date, created_by, approved_by nullable, approved_at nullable, timestamps.
- `sales_order_items`: id, sales_order_id, product_id, quantity > 0, selling_price >= 0.
- `stock_ledger`: issue-capable rows with product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, created_at.

Stock invariant: `product_stocks.quantity >= 0` must hold in service validation and DB constraints.

## 7. Authorization Rules

- Admin may create, submit, approve, reject/cancel, and issue according to state.
- Sales may create and submit only their own Sales Orders.
- Sales may not approve or reject any Sales Order, including their own.
- Warehouse Staff may perform goods issue for Approved orders.
- Unauthorized manual endpoint calls must return 403.

## 8. Transaction and Invariants

Goods issue must:

1. verify actor and SO state;
2. validate SO is `Approved`;
3. begin transaction;
4. lock `product_stocks` rows by `(warehouse_id, product_id)` deterministic order;
5. validate available stock after locks are acquired;
6. decrement stock;
7. insert one `Issue` ledger row per stock movement;
8. update SO status to `Fulfilled`;
9. commit;
10. roll back completely on failure.

The second competing issue must read post-commit stock and fail if insufficient.

## 9. Validation and Errors

- Customer and source warehouse must exist and be active.
- SO must contain at least one item.
- Product must exist and be active.
- Quantity must be `> 0`.
- Selling price must be `>= 0`.
- Duplicate item lines must be rejected or deterministically merged and documented.
- Insufficient stock returns safe validation/conflict feedback.
- Invalid state transitions return safe 409 or form feedback.

## 10. Tests

Required tests:

- Sales cannot approve order.
- Sales can manage only own Sales Orders.
- Cannot issue when status is not Approved.
- Cannot transition Fulfilled back to Draft.
- Insufficient stock is rejected.
- Successful issue decrements stock and writes Issue ledger.
- Sequential oversell scenario: first issue succeeds; second issue fails; stock never negative; no extra ledger row.

## 11. Acceptance Criteria

1. Sales Order Draft/create/edit/submit flow works.
2. Admin approval works and Sales approval is blocked server-side.
3. Goods issue only works from Approved.
4. Fulfilled status is set only after successful issue.
5. Insufficient stock rolls back completely.
6. Oversell scenario is covered by integration test.
7. Ledger contains `Issue` rows tied to SO references.
8. Unit/integration tests and PHPStan remain acceptable.

## 12. Verification Commands

```bash
composer test -- --filter SalesOrder
composer test -- --filter StockPolicy
composer test -- --filter Issue
composer test
composer analyse
docker compose up --build
```

## 13. Risks and Decisions

- SO rejection representation is ambiguous because the fixed status list includes `Cancelled` while wording mentions reject; document the chosen mapping.
- Admin-created SO self-approval policy must be documented.
- Duplicate item handling must be chosen before implementation.

## 14. Handoff to Implementation Plan

Plan tasks should implement SO schema/repositories, service state machine, authorization tests, issue transaction, oversell integration test, controllers/views, and evidence updates.
