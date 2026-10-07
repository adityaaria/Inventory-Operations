# ADR-008 — Transactional receipt/issue replay protection

Date: 2026-10-07. Status: accepted implementation direction under user authorization to continue receipt/issue idempotency; not a trainer decision or new order status.

Requirement mapping: PO-01, SO-01, AUTH-01/02, DB-01, ARCH-01/02, TEST-01/02/03. Idempotency is optional hardening supporting those invariants.

## Decision

Each receipt/issue form contains a random 128-bit lowercase hexadecimal operation key. The HTTP controller requires it, independently of CSRF. The service checks authorization, locks its order, then claims `(actor_id, request_key)` through a prepared repository query inside the same stock transaction. A SHA256 fingerprint includes operation, order ID and numerically sorted nonzero item quantities. Same-key/same-payload completed requests return success before current order-state validation; different payload/order/operation returns HTTP 409. Fresh keys follow all existing state and quantity validation.

Stock changes, ledger, source order, audit and completion marker commit together. Unique key plus row locking serialize simultaneous duplicates; a failed transaction leaves no committed marker, enabling retry. Replay adds no stock ledger or success audit entry. Current authorization is always rechecked. Keys are scoped to the actor, so another authorized user's distinct request is not automatically treated as a replay.

The existing StockService and connection-owned transaction remain authoritative; the new service/repository boundary has no framework, PDO in services or session dependency. Direct trusted service callers may omit keys for compatibility with explicit business commands and fixtures; HTTP receipt/issue cannot omit them. Omitting a key provides the existing state/stock safety but no partial-receipt replay guarantee. Preserve the same key when retrying an intent; generating another key represents a new intent and may execute another partial receipt.

## Deployment and recovery

Apply additive `database/migrations/20261007-operation-requests.sql` using `php scripts/migrate-operation-requests.php` before activating the new image. It creates only the operation_requests table, preserves business rows and does not replay seeds. MySQL DDL is not transactional rollback. Fresh installations include the table in schema-and-seed.sql. Readiness verifies the required columns. Rollback to the old application may leave the table intact; do not drop/truncate committed markers.

Full logical backups must include operation_requests along with stock/ledger/orders/audit. Restoring an older pre-migration backup requires applying the additive migration; historical operations without keys cannot be reconstructed as idempotent receipts. No automatic expiry/pruning of successful markers is introduced, avoiding unannounced loss of replay protection. Capacity/retention policy for very long histories remains operator work.

## Verification

Use actual MySQL rollback tests and independently connected process races, not only in-memory mocks. Tests cover canonical payloads, changed payload conflicts, role rejection even on replay, full/partial receipt replay, completed issue replay, failed audit rollback and reuse, and simultaneous duplicate receipt/issue with both callers successful and exactly one movement. HTTP E2E also verifies missing keys, form generation, replay and conflict on a disposable application.
