# ADR-006 — Atomic opening zero balances for the catalog

Date: 2026-10-07. Implemented engineering decision under the user's end-to-end verification/fix request; not a trainer-prescribed timeout/status/policy decision.

Requirements: PRD-01, WH-01, DASH-01, JOB-01, DB-01, ARCH-01/02, VAL-01. The official brief p. 6 requires a product stock row per warehouse. Previously, creating a product/warehouse did not initialize all pairs; receipt created the target row lazily. Products with no rows were omitted from warehouse breakdown and per-warehouse low-stock queries.

ProductService and WarehouseService now create their master records and initialize missing zero balances atomically through StockService. Production composition supplies the same PDO-backed TransactionManager and StockCatalogRepository; services never see PDO/SQL. MySQL and pure in-memory implementations of the catalog interface are provided. Existing unit fixtures may omit the optional stock collaborator; production explicitly injects it.

StockCatalogRepository locks the immutable unique `schema_versions.version=phase-0` bootstrap row before catalog creation or repair. This row acts as a database mutex for product/warehouse matrix initialization; it is never updated. Product and warehouse creators serialize so their new intersection cannot be missed under concurrent transactions. A missing bootstrap row or a failed stock insert fails closed. Product IDs and warehouse IDs are enumerated in ascending order; StockService uses the existing `lockByProductWarehouse` method to retain existing balances or create missing rows at zero.

An opening zero row is not Receipt, Issue, Adjustment or Transfer: it changes no inventory quantity. Therefore it creates no synthetic movement or zero-quantity StockLedger row (ledger quantities must remain positive). Actual positive/negative movements retain source-order/stock locks, ledger, atomic audit, commit/rollback. Existing balances are never overwritten by catalog initialization. Request-level master-data audit remains its existing best-effort telemetry.

MySqlTransactionManager joins an already-open transaction on the shared connection. Nested operations propagate failures to the owner; callers must not swallow failures and commit partial work. CSV imports remain one atomic transaction, including newly created master rows and zero balances. Normal standalone operations own their begin/commit/rollback. Four real worker-process races cover duplicate issue, competing issue, partial receipt and simultaneous product/warehouse creation.

The schema/seed completes missing opening pairs at zero while preserving populated seed balances. This is initial fixture construction, not an operational goods movement. Existing databases use the idempotent native CLI `php scripts/initialize-stock-balances.php`, one product transaction at a time, with the same catalog mutex and StockService. Never replay the full seed against application data for repair. Verification compares positive-balance hashes, total quantities and ledger counts before/after.

Tradeoffs: the catalog mutex serializes master creation/import initialization, not ordinary receipt/issue workflows. Initializing a warehouse requires one row per existing product, so very large catalogs need measured migration/batch planning. No load benchmark or distributed-storage capability is claimed. The bootstrap metadata row is an explicit coupling; replacing it with a dedicated application-lock table would require a justified migration.

Evidence: `docs/testing/end-to-end-verification-2026-10-07.md`.
