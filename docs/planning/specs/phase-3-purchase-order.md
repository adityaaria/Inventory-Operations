# Phase 3 Specification: Purchase Order Receipt Workflow

Status: Approved for planning  
Date: 2026-08-31  
References: `AGENTS.md`, `SDD.md`, `PLAN.md`, `docs/planning/CONSTITUTION.md`, `docs/architecture/adr-002-stock-concurrency.md`

## 1. Purpose

Phase 3 adds Purchase Order creation, ordering, and transactional goods receipt. This is the first stock-mutating slice and must prove that receipt increments inventory and writes ledger records atomically.

Exit gate: full and partial goods receipts are demoable and ledger-backed.

## 2. Requirement Trace

- `PO-01`: Purchase Order lifecycle and partial/full receipt.
- `ARCH-02`: transaction and row-locking foundation for stock mutation.
- `DB-01`: PO, PO item, stock, and ledger persistence.
- `VAL-01`: item quantity/price/remaining validations.
- `ERR-01`: safe invalid-state and validation errors.
- `TEST-01`: service unit tests for receipt rules.
- `TEST-02`: MySQL integration tests for receipt + ledger atomicity.

## 3. In Scope

- `purchase_orders` and `purchase_order_items` schema.
- Purchase Order statuses: `Draft`, `Ordered`, `PartiallyReceived`, `Received`, `Cancelled`.
- PO create/edit while Draft.
- Ordered transition.
- Full and partial receipt from eligible statuses.
- Received quantity tracking per item.
- Remaining quantity validation.
- Stock increment through `StockService` only.
- Receipt ledger rows with `movement_type='Receipt'`, `reference_type='PO'`, and PO reference ID.
- Transaction rollback on any stock/order/ledger failure.
- Admin and Warehouse Staff receipt authorization.
- Integration tests proving stock and ledger update atomically.

## 4. Out of Scope

- Sales Order workflows and goods issue.
- Supplier advanced workflow beyond required supplier reference.
- Audit trail/idempotency unless mandatory scope is already green and separately approved.
- PO cancellation after partial receipt unless documented as a narrow temporary default in pending decisions.

## 5. Architecture Contract

`PurchaseOrderController` handles HTTP only. `PurchaseOrderService` owns PO state transitions, receipt rules, authorization orchestration, and transaction orchestration. `StockService` is the only service allowed to mutate `product_stocks` and append `stock_ledger`.

Repositories must use prepared statements. Services depend on interfaces where practical and remain unit-testable with fake repositories.

## 6. Data Contract

Add or complete:

- `purchase_orders`: id, order_number unique, supplier_id, destination_warehouse_id, status, order_date, created_by, timestamps.
- `purchase_order_items`: id, purchase_order_id, product_id, quantity > 0, received_quantity >= 0, purchase_price >= 0.
- `stock_ledger`: receipt-capable rows with product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, created_at.

Invariant: `purchase_order_items.received_quantity <= purchase_order_items.quantity`.

## 7. Authorization Rules

- Admin may create/order/receive/cancel where business state permits.
- Warehouse Staff may create/propose Purchase Orders and receive goods according to the authorization matrix.
- Sales may not create or receive Purchase Orders.
- Server-side authorization is mandatory for every transition.

## 8. Transaction and Invariants

Receipt must:

1. verify actor and PO state;
2. validate each received quantity is `> 0` and `<= remaining`;
3. begin transaction;
4. lock target `product_stocks` rows by `(warehouse_id, product_id)` deterministic order;
5. increment stock;
6. insert one `Receipt` ledger row per stock movement;
7. update item `received_quantity`;
8. recalculate PO status as `PartiallyReceived` or `Received`;
9. commit;
10. roll back completely on failure.

No controller, view, JavaScript, or ad hoc repository path may mutate stock.

## 9. Validation and Errors

- Supplier and destination warehouse must exist and be active.
- PO must contain at least one item.
- Item product must exist and be active.
- Quantity must be `> 0`.
- Purchase price must be `>= 0`.
- Duplicate item lines must be rejected or deterministically merged; record the chosen rule in `docs/planning/DECISIONS_PENDING.md` or trainer decisions.
- Invalid transitions return safe 409 or form feedback.

## 10. Tests

Required tests:

- Partial receipt keeps remaining quantity and sets `PartiallyReceived`.
- Full receipt sets `Received`.
- Receipt quantity above remaining is rejected.
- Receipt from invalid status is rejected.
- Sales cannot receive PO.
- Stock increments and ledger row inserts in one transaction.
- Forced ledger failure rolls back stock and item received quantity.

Integration tests must run against real MySQL Docker for stock/ledger behavior.

## 11. Acceptance Criteria

1. PO Draft create/edit and Ordered transition work.
2. Partial receipt updates item received quantities and PO status.
3. Full receipt completes the PO.
4. Stock increments only through transaction + ledger path.
5. Ledger contains `Receipt` rows tied to PO references.
6. Invalid receipt cannot persist partial data.
7. Required unit/integration tests pass.
8. PHPStan remains acceptable.

## 12. Verification Commands

```bash
composer test -- --filter PurchaseOrder
composer test -- --filter Receipt
composer test
composer analyse
docker compose up --build
```

## 13. Risks and Decisions

- PO cancellation after partial receipt is ambiguous and must remain documented until trainer-confirmed.
- Duplicate item handling must be explicitly chosen before implementation.
- Transaction boundary must be implemented in service/stock orchestration, not in controller.

## 14. Handoff to Implementation Plan

Plan tasks should implement PO schema/repositories, service state machine, stock receipt operation, controllers/views, receipt tests, rollback tests, and evidence updates.
