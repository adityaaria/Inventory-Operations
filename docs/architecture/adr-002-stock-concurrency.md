# ADR-002: Stock Concurrency Through MySQL Row Locks

Status: Accepted  
Date: 2026-08-31

## Context

Concurrent goods receipt or goods issue can corrupt inventory if stock rows are read and updated without serialization.

## Decision

Every stock mutation will run in a database transaction, lock relevant `product_stocks` rows with `SELECT ... FOR UPDATE`, validate quantity after locking, update stock, append `stock_ledger`, update the source operation state, and commit atomically.

Multiple stock rows will be locked in deterministic `(warehouse_id, product_id)` order.

## Consequences

Competing stock operations serialize per stock row. Oversell is prevented by MySQL/InnoDB row locking. All stock-changing workflows must use the stock service and ledger path.

## Implementation Evidence

- `StockService::receive()` and `StockService::issue()` own stock transaction orchestration.
- `MySqlStockRepository::lockByProductWarehouse()` uses `SELECT ... FOR UPDATE`.
- `PurchaseOrderService::receive()` passes accepted receipt movements into `StockService::receive()`.
- `SalesOrderService::issue()` passes approved order movements into `StockService::issue()`.
- `PurchaseOrderReceiptIntegrationTest` verifies rollback when ledger append fails.
- `SalesOrderIssueIntegrationTest` verifies oversell prevention and no failed ledger row.
- Verified by `composer test` and `composer analyse` on 2026-08-31.

Note 12 October 2026 (D-17, ADR-023): stock locks now continue below the pair: after every pair of an operation is locked, each pair's lots are locked by point lookups on (pair, lot_seq); the pair stays the lock root.
