# ADR-004 — Atomic workflow state, critical audit and batch imports

Date: 2026-10-06. Status: implemented engineering decision; assessment review pending. This does not represent a trainer ruling on pending business ambiguities.

Requirements: PO-01, SO-01, ARCH-01/02, AUTH-01, USR-01, VAL-01; repository stock invariants.

Source-order state must participate in the transaction that changes inventory. Locking stock alone prevents lost stock updates, but allows two requests that read Approved before starting their transactions to issue the same SO twice. Receipt status can similarly be computed from stale remaining quantities. Critical audit written after commit cannot guarantee evidence for each committed movement.

StockService owns the stock transaction and exposes a scoped transaction operation. Workflow services acquire the source-order lock inside it, validate current state, then call receipt/issue. Nested stock operations participate in the existing owned transaction; they do not independently commit. All submit/approve/cancel/order operations acquire the same source lock, so cancellation and fulfillment cannot overwrite each other. Lock order is source order first, then product stock in product-ID order. Stock quantity queries in an active transaction use locking reads to avoid a Repeatable Read snapshot predating a waited stock lock. Repository state updates also constrain allowed prior statuses.

StockService receives an optional AuditLogRepositoryInterface at construction. The running application supplies it. Movement audit append is strict and occurs after source state update but before commit; any failure rolls back stock, ledger, order and audit. Successful request telemetry for receipt/issue is omitted to avoid duplicate success rows. Auth/request telemetry remains best-effort, separate from critical domain evidence.

Non-stock CSV batches use CsvImportService with TransactionManagerInterface and a PDO-backed implementation supplied manually at the composition root. A bad row or persistence failure rolls back all earlier rows; validation errors identify the CSV row and state that nothing was imported. Expected database constraints are mapped at the repository boundary; unexpected infrastructure errors remain errors, not raw CSV validation messages.

AuthGuard re-reads the current active user/role via UserRepository for authenticated requests. Session credentials identify the user but do not preserve revoked privileges. This avoids requiring a session-version schema migration. Cost: one small user query per guard resolution; anonymous sessions do not trigger it. Optional guard/import dependencies support legacy unit fixtures; production wiring explicitly supplies both.

Tradeoffs: stock and source repositories must share one PDO connection, and workflow locks must always precede stock locks. No framework/container or SQL in services is introduced. Stock transaction ownership remains behind a repository boundary; a future dedicated stock transaction manager can refactor it with the current regression tests. Concurrent real-process stress and hardware browser verification remain separate checks; controlled interleavings prove the reproduced state races.

Evidence: AuditRemediationIntegrationTest covers duplicate issue, final partial receipts, cancellation before issue, strict audit rollback, six atomic import modules and safe duplicate handling. AuditRemediationTest covers revoked sessions, malformed raw input, product detail authorization and refusal of the application DB. ReportQueriesIntegrationTest covers bounded preview/full scoped streaming CSV.
