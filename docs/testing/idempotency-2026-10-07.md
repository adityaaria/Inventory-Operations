# Receipt/issue idempotency — 7 October 2026

Requirement IDs: PO-01, SO-01, AUTH-01/02, DB-01, ARCH-01/02, TEST-01/02/03.

Mandatory/Optional: optional replay hardening; existing mandatory quantity, state, role and transactional rules preserved.

Files changed: OperationRequestRepositoryInterface and its MySQL/in-memory implementations; OperationIdempotency; PurchaseOrderService/SalesOrderService and controllers; receipt/issue forms, public/index.php; database schema/additive migration; CLI migration, readiness, Docker packaging; unit/integration/concurrency worker/HTTP E2E tests; ADR-008 and documentation.

Behavior implemented: each receipt/issue form generates a random operation key. HTTP requires it. Same actor/key/operation/order/canonical quantities replay successfully without additional stock/ledger/success audit. Different payload with an existing key returns 409. A new key represents another intent and follows existing state validation. Partial receipts require a new intent/key for subsequent deliveries. Completed receipts/issues can replay their original successful key.

Security/authorization: current role checked before replay; CSRF remains required separately. Key format is strict 32 lowercase hex characters. Keys are scoped to actor ID; payload fingerprints include operation and order. Prepared statements, unique actor/key constraint and locked records. No raw request payload or operation key added to audit metadata.

Transaction/invariants: order lock precedes request claim; all stock locks retain existing deterministic order. Request marker, stock, ledger, source order and success audit share the connection-owned stock transaction. Failure rolls back all. MySQL request repository refuses autocommit use. No new business status or stock mutation route introduced.

Tests added/updated: 9 new unit/integration/process cases for canonical replay, payload conflict, malformed key, full/partial receipt, issue replay, role rejection on replay, audit failure rollback/retry, transaction guard, and two independent processes concurrently repeating receipt or issue (both return success, exactly one movement). HTTP adds generated-form-key, missing-key, partial replay, conflict and completed issue replay checks.

Commands executed + result:

- `docker compose -p inventory-quality-idempotency --profile quality run --build --rm test`: final exit 0, 347 PHP tests / 1496 assertions, PHPStan level 5 no errors, 50 JavaScript tests passed. Initial static analysis found two unnecessary nullsafe calls; corrected and complete gate rerun.
- `python3 scripts/verify-operations.py --recovery-only ...`: 207 HTTP checks passed; 9 encrypted recovery checks passed; all 16 tables including operation_requests preserve counts/digests; restore 1.010 seconds; two disposable projects cleaned, private test workspace removed.
- `python3 -m unittest discover -s tests/Operations -v`: 35 passed.
- Backup created before additive local migration. All 15 pre-existing table counts/digests identical afterward, new table empty. No seed replay or stock writes.
- Runtime and production images built successfully. CLI migration repeated successfully in the running runtime, PHP lint passed, readiness ready; production migration file presence verified with network disabled. Packaging issue discovered during CLI verification was corrected before final verification.
- Local app updated with `docker compose up -d --no-build --no-deps app`; main database container preserved. Disposable quality containers cleaned.
- `git diff --check`: passed.

Docs/evidence updated: [quality](idempotency-2026-10-07/quality.txt), [HTTP and recovery](idempotency-2026-10-07/recovery/), [migration preservation](idempotency-2026-10-07/migration.json), [runtime packaging/readiness](idempotency-2026-10-07/runtime.json), [ADR-008](../architecture/ADR-008-operation-idempotency.md).

Known gaps/risks: operator deployment must apply the additive migration before enabling the new image. Pre-migration backups need this migration after restoration; old unkeyed operations cannot be reconstructed. Marker expiry is intentionally absent; a long-term retention/capacity policy remains operator work. Trusted direct service callers may omit a key for backwards compatibility; those calls retain existing safety but lack partial-receipt replay protection. Different actors or new keys represent distinct intents. Retry transient deadlock/timeouts using the same key; no automatic HTTP retry was added. Physical-device/hosted CI/off-host deployment checks remain pending as previously documented.

Recommended next task: operator deployment/backup and Android/iPhone acceptance, then benchmark audit/request-table retention at expected production volume.
