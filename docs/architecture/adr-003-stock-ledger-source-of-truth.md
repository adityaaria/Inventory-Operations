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

Note 12 October 2026 (D-17, ADR-023): every new ledger row names its lot (`lot_id`); per lot, quantity = opening + Σ its ledger rows, and Σ lots = product_stocks.quantity.

Note 12 October 2026 (D-20, ADR-026): every ledger row with a lot also carries the lot's `unit_cost` and `cost_amount` = signed quantity × unit cost (whole cents, CHECK `chk_stock_ledger_cost`); per lot, value = opening value + Σ cost amounts. Rows before the lot watermark keep no cost.
