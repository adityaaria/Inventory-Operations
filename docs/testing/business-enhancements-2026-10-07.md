# Business enhancements 1–6 — 7 October 2026

Requirement IDs: PO-01, SO-01, DASH-01, REPORT-01, AUTH-01/02, DB-01, ARCH-01/02, UI-01, TEST-01/02/03.

Mandatory/Optional: optional user-approved business workflows; Adjustment is supported by the official ledger definition. User approval does not resolve trainer-pending decisions.

Files changed: new BusinessOperationController/Service/Input, StockDelta, OrderExceptionService, repository contracts/MySQL/fakes, stock/order/report services and controllers, public/index.php, native stock-operation views/JS, PO/SO views, schema and additive migration, unit/integration/concurrency/HTTP/browser tests. [ADR-009](../architecture/ADR-009-business-operations.md) documents the implemented policy; unrelated pending operational changes remain separate.

Behavior implemented: close PO remainder without reversal; SO reasoned rejection using Cancelled; reviewed per-warehouse replenishment; independently approved count adjustments, direct transfers and original-movement supplier/customer returns. Reports/CSV account for signed adjustments. Modal forms preserve the clicked decision and enforce customer goods-fit confirmation.

Security/authorization: Admin closes/rejects orders; Admin/WarehouseStaff proposes/posts stock documents; a different Admin approves/rejects. Sales cannot access stock operations or recommendations. CSRF and server authorization remain mandatory. Original order visibility retained. No mandatory SO self-approval restriction was added.

Transaction/invariants: StockService locks deterministic stock pairs, checks baseline/available stock and UInt32 limits, updates stock/ledger/source/audit atomically. Return source locks and current cumulative allowance prevent concurrent over-return. Posted document replay does not duplicate stock/audit. Historical receipt keys replay after PO closure. Failed audit or insufficient stock rolls everything back.

Tests added/updated: business unit/integration scenarios, real-process transfer/return concurrency, adjustment reconciliation, migration repeat, audit deduplication, selected form submitter, required checkbox and native transition cancellation. Existing tests were retained.

Commands executed + result:

- Docker quality gate: **365 PHP tests, 1609 assertions**, PHPStan level 5 without errors, **54 JavaScript tests** passed; [Docker log](business-enhancements-2026-10-07/quality.txt), [final frontend log](business-enhancements-2026-10-07/javascript-final.txt).
- Python operational tests: **35 passed**; [log](business-enhancements-2026-10-07/operations-tests.txt).
- `scripts/verify-business.py`: **76 HTTP checks passed** on an isolated database; [results](business-enhancements-2026-10-07/http.json).
- `scripts/verify-operations.py --recovery-only --business-flows`: **13 checks passed**, including mandatory **207 HTTP checks** and business flows before backup, encrypted replica/decryption and exact populated 21-table recovery; [summary](business-enhancements-2026-10-07/populated-recovery/recovery-summary.json). This recovery run preceded final UI transition/audit deduplication fixes; the latest HTTP gate above covers those final changes.
- Pre-migration backup completed; migration ran twice on localhost. All old-column row projections for **16 existing tables remained identical**; five new tables empty; no stock mutations or seed replay. [Preservation evidence](business-enhancements-2026-10-07/migration.json).
- Latest local runtime activated; `scripts/health-check.php` returned `ready`. Production target image built as `inventory-operations-production:local`; no external deployment.

Docs/evidence updated: ADR-009, SDD, KNOWLEDGE, README, pending decision register, operational runbook, debt register and AI usage log.

Known gaps/risks: trainer policies, physical Android/iPhone acceptance and external CI/deployment/off-host activation remain pending. Proposal UI captures one product while service supports 100. No in-transit, accounting/refund, quarantine or cross-warehouse return scope. MySQL DDL commits implicitly; pre-upgrade backup retained. Chrome emulation acceptance passed as recorded below; this is not physical-device evidence.

Recommended next task: trainer review and physical-device acceptance using the implemented optional workflows, then deployment configuration with actual host/backup/alert destinations.

Browser acceptance: `node tests/Browser/business-enhancements.cjs` passed **31 checks**: 28 layout checks across 360/390/768/1440 px and three mobile dialog/business checks. Independent approval, transfer posting and customer-return confirmation succeeded with no active exception. [Results](business-enhancements-2026-10-07/browser.json). Failed iterations revealed a POST/redirect native transition problem; disabling the outgoing opt-in before POST fixed it. Native GET transitions and reduced-motion behavior remain supported. Final JS regression suite: 54 passed.
