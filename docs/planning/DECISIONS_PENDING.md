# Pending Decisions

## Phase 0

- Local app port default: 8080.
- PHP runtime baseline: PHP 8.2 CLI with built-in server for assessment bootstrap.
- Composer scripts: `test`, `test:unit`, `test:integration`, `analyse`.

## Later Phases

- PO cancellation rules after partial receipt.
- SO reject action maps to `Cancelled` until trainer confirms a separate rejection representation.
- Admin may approve Admin-created SO; `approved_by` and `approved_at` are retained for traceability until trainer confirms stricter segregation.
- Duplicate SO item lines are rejected until trainer confirms whether deterministic merge is preferred.
- Admin-created Sales Order self-approval policy.
- Multi-warehouse low-stock dashboard semantics.
- Exact order-number format.
- Duplicate item line behavior: temporary implementation rejects duplicate product lines in a PO until trainer confirms whether merge is preferred.
- Sales Order rejection representation.
- Transaction ownership: temporary implementation keeps PDO hidden behind stock repository transaction methods until trainer confirms a dedicated transaction manager.
