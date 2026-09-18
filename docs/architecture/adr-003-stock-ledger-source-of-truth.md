# ADR-003: Stock Ledger Source of Truth

Status: Accepted  
Date: 2026-08-31

## Context

The system must show current stock and also prove how stock changed through receipts and issues. Current balance alone is insufficient for audit, reports, rollback verification, and technical defense. At the same time, every stock-changing workflow must stay simple enough for native PHP, PDO, and MySQL without queues or event sourcing infrastructure.

## Decision

Use `product_stocks` as the current stock balance and `stock_ledger` as the append-only movement history.

All stock mutations must go through `StockService`. The service starts a transaction through `StockRepositoryInterface`, locks the affected `product_stocks` rows, validates quantities after lock, updates balances, appends `stock_ledger` rows, updates the source order state through a callback, then commits. Any exception rolls the transaction back.

`Receipt` movements reference Purchase Orders with `reference_type='PO'`. `Issue` movements reference Sales Orders with `reference_type='SO'`.

## Consequences

- Dashboard and report queries can read current stock from `product_stocks` and movement history from `stock_ledger`.
- Failed receipt/issue operations leave neither balance changes nor ledger rows.
- Future audit trail features can build on this ledger without changing the mandatory stock mutation path.
- Manual stock adjustment and warehouse transfer require new ledger movement types and ADR/test updates before implementation.
