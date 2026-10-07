# Business enhancements 1–6

Date: 2026-10-07. Status: optional scope approved by the user on 2026-10-07 (“ya setuju”). User authorized the six enhancements; the detailed policies below are not trainer-approved requirements. Detailed policies in this document are approved as optional user scope; trainer decisions remain separately pending.

Requirement IDs: PO-01, SO-01, DASH-01, REPORT-01, AUTH-01/02, DB-01, ARCH-01/02, UI-01, TEST-01/02/03. Optional operational flows supporting mandatory requirements.

## Verified starting point

The official brief, page 4, lists StockLedger movement types Receipt/Issue/Adjustment (read directly from the local PDF). Current schema and repository support only Receipt/Issue; SDD section 23 anticipates Adjustment. Adjustment support and all ledger aggregate/reconciliation/report queries must be updated together before introducing actual adjustment movements. Positive quantity constraints must not be weakened to hide invalid quantities. Existing receipts/issues and their historical balances must be preserved.

Prior completed verification: 347 PHP tests /1496 assertions, PHPStan level 5, 50 JS tests, 35 operations tests and 207 HTTP checks. These are baseline evidence, not verification of these six new features.

## Proposed decisions and vertical slices

1. **Close PO remainder.** Admin records a mandatory reason, closure actor/time and remainder quantities for a PartiallyReceived PO. Retain original ordered/received quantities and Receipt ledger. Use separate closure metadata, not an invented official PO status; label the remainder closed and reject further receipts/new key mutations. Historical successful receipt keys still replay safely. Exclude closed remainder from inbound supply calculations. Trainer decision D-01 remains pending; record any user-approved optional policy distinctly.
2. **SO rejection reason.** Admin rejects PendingApproval using existing Cancelled status, with required reason and audit. Show Sales the reason on accessible own orders. No automatic revival or resubmit until that policy is explicitly approved. Preserve existing cancellation behavior separately. D-02/D-03 remain distinct decisions; no inferred self-approval restriction on mandatory SO approval.
3. **Replenishment recommendations.** Use current temporary per-active-product/active-warehouse semantics (D-05). Suggested quantity = max(0, reorder point − available stock − outstanding Ordered/PartiallyReceived PO supply), excluding cancelled, received or closed remainder. Clearly show each component and recommend only; shortcut prefills reviewed PO creation, never automatically creates an order. Supplier, quantities and prices require review. Native repository aggregation with scoped pagination; handle subsequent inventory changes without promising a reservation.
4. **Approved stock adjustment.** Admin/Warehouse proposes a stock count with warehouse, products, observed baseline/count and reason. Proposed policy: another Admin approves; authorized Warehouse/Admin posts. Record provenance and immutable items. At posting, lock all stock pairs in deterministic order and reject stale baselines rather than overwriting receipts/issues since count capture. Apply signed deltas through StockService, append Adjustment ledger plus audit and mark posted atomically. Reject negative resulting stock, duplicate posting and unauthorized access. No direct stock edits.
5. **Warehouse transfer.** Proposed scope: direct atomic transfer, no in-transit stage. Different active source/destination, positive items, proposal/independent approval/posting. Lock all source/destination pairs in deterministic order; reject insufficient source; paired movements and total-product conservation, source state and audit commit together. Use Adjustment with documented direction/reference rather than inventing mandatory TransferIn/TransferOut types. Rollback both warehouses on any failure; duplicate operation cannot transfer twice.
6. **Purchase/sales returns.** Proposal/approval/posting with reason and immutable references to original receipt/issue ledger entries. Supplier return subtracts available stock; customer return adds goods certified fit for stock. Returnable quantity limited to original movement less already posted returns. Lock original references to serialize concurrent returns, then stock pairs in deterministic order. Reject over-return or insufficient outbound stock; retain original PO/SO status and original ledger. Return posting, source linkage, ledger and audit atomic/idempotent. Refunds, tax/accounting, damaged-stock quarantine and cross-warehouse returns are outside the proposed scope.

## Required implementation protocol

For each approved slice: document authorization/state transitions, repository interface and fake, manual injection, prepared SQL, additive migration and readiness, service-owned transactions, native responsive UI and audit evidence. Add meaningful unit, real MySQL rollback and process-concurrency tests; run full mandatory gate, then isolated HTTP acceptance. Update dashboard/report/reconciliation/export so every new movement contributes with the correct sign. Extend backup/restore evidence to all new tables. Apply migrations to localhost only after backup and data-preservation checks. No external publication/deployment is implied.

## Acceptance scenarios

- Close remainder retains actual received stock, blocks future receipt, removes inbound recommendation quantity; reason/role validation fails without writes.
- Rejection reason visible only within existing SO visibility; invalid transition/unauthorized rejection does not persist.
- Pending PO supply reduces purchase recommendation, closed/cancelled supply does not; no cross-warehouse supply offset and no duplicate automatic order.
- Adjustment stale count rejected, one approved proposal posts once, ledger/audit failure fully rolls back, nonnegative balances.
- Transfer conserves total quantity, insufficient source or ledger/audit failure changes neither warehouse; concurrent requests cannot oversell or double post.
- Returns cannot exceed original movement after concurrent requests; supplier return needs stock, customer return increases only approved goods; original transactions preserved.
- All new pages fit 360px with table-local scrolling, server filters/pagination, badges, adjacent filter/table/pagination and consistent dialogs.

## Scope approval

User explicitly agreed to both proposed policy bundles on 2026-10-07. Preserve mandatory PO/SO status sets and distinguish optional operation statuses. Independent approval applies to the new adjustment/transfer/return documents, not mandatory SO self-approval.

## Implementation completion

All six vertical slices are implemented with repository interfaces, manual injection, native UI, migration and signed reporting. PHP/static/JS/HTTP, rollback/process-concurrency and populated recovery gates passed; localhost migration preserved all old data and readiness passed. Detailed evidence and remaining physical/trainer/deployment boundaries: [acceptance report](../testing/business-enhancements-2026-10-07.md). Browser validation is reported by its actual final result, not inferred from HTTP checks.

Final browser gate: 31 checks passed; latest JS suite: 54 passed. All six slices and localhost activation complete. Physical-device/trainer/external deployment boundaries remain explicit.
