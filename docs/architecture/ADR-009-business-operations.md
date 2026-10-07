# ADR-009: approved optional business operations

Date: 2026-10-07. Accepted for user-authorized optional scope (user: “ya setuju”); not trainer approval. Requirement support: PO-01, SO-01, DASH-01, REPORT-01, AUTH-01/02, DB-01, ARCH-01/02, UI-01, TEST-01/02/03.

## Decisions

Preserve official PO/SO status sets. Close a PartiallyReceived PO's remaining supply using closure metadata and a required Admin reason; never reverse historical receipts. Successful historical receipt keys can replay, while new receipts are blocked. Reject PendingApproval SOs using Cancelled plus separately stored reason, visible under existing order access rules.

Recommend per active product/warehouse: max(0, reorder point − current stock − remaining Ordered/PartiallyReceived PO supply). Closed remainder contributes no supply. Recommendation only prefills reviewed PO creation and reserves nothing. Per-warehouse thresholds and mandatory low-stock interpretation still await trainer confirmation.

Adjustment, direct transfer and supplier/customer return share immutable operation documents: PendingApproval → Approved/Rejected; Approved → Posted; PendingApproval/Approved → Cancelled. Admin/WarehouseStaff proposes and posts; another Admin approves/rejects. Creator cannot approve their own document. This restriction does not change mandatory SO approval. Reasons are required; customer returns require confirmation that goods are fit for stock. Cancellation preserves approval provenance. Posted documents cannot be modified or cancelled; correct through another approved operation.

The brief already permits Adjustment ledger entries. Keep positive unsigned quantity, add signed quantity_delta for Adjustment only, and retain NULL deltas for historical Receipt/Issue. Transfers append paired signed Adjustment entries; returns reference original Receipt/Issue. Reports, CSV, net movement and ledger reconciliation include signed adjustments.

StockService owns posting transactions, deterministically locks all affected product/warehouse pairs and validates after locking. Count proposals retain the observed baseline; a changed balance returns 409 and requires recount/reproposal. Counts may be zero; resulting balances cannot be negative or overflow UInt32. Source operation state, stock, ledger and audit commit or roll back together. Posting an already Posted document is a successful no-op.

Returns lock original source ledger entries in sorted order before the operation. A locked cumulative return counter supplies a current read under MySQL REPEATABLE READ and bounds concurrent returns to original quantity. Counter updates join the posting transaction; cancelled/pending proposals do not consume allowance. Supplier returns also require available stock. Source PO/SO status and original movements remain intact.

## Deployment and limits

Additive CLI migration is repeatable and must run after backup and before new image activation. Never replay seed SQL over existing data. Readiness verifies new columns/tables. Recovery includes all 21 tables, including return allowances and original references.

UI and service now support up to 100 distinct products per proposal (multi-item follow-up, 7 October 2026); the first item retains the legacy scalar HTTP fields and supplementary items use indexed items fields. No in-transit inventory, damaged-goods quarantine, refunds, taxes/accounting, cross-warehouse returns or editable approved items. Independent approval requires a second Admin when the proposer is Admin. Scope changes require a new decision.

Actual verification and preservation evidence: [business acceptance](../testing/business-enhancements-2026-10-07.md). Trainer-pending questions remain in [decision register](../planning/DECISIONS_PENDING.md).

## Native navigation verification

A parser-blocking head script registers pageswap/pagereveal handlers and catches only expected native transition cancellation names on ready, finished and updateCallbackDone; unrelated failures remain visible. Chrome describes ready rejection for skipped transitions in its [cross-document documentation](https://developer.chrome.com/docs/web-platform/view-transitions/cross-document). Browser acceptance tracks active CDP exceptions by exceptionId and removes them only after [Runtime.exceptionRevoked](https://chromedevtools.github.io/devtools-protocol/tot/Runtime/#event-exceptionRevoked), rather than treating a subsequently handled rejection as permanently unhandled.

The browser harness discards previously collected console exceptions before enabling Runtime for a new acceptance run. This prevents an earlier test's stored error from being reported as a new failure; it does not suppress new exceptions. CSS declares the default navigation opt-in at the stylesheet root and overrides it for reduced motion.

Before POST form submission, disable the outgoing document's cross-document transition opt-in; POST/redirect navigation remains normal native navigation. GET retains the default opt-in, and reduced motion remains respected. The final frontend regression check covers this distinction.

Multi-item UI verification: docs/testing/multi-item-2026-10-07.md. Shared operation header, immutable item semantics and posting transaction are unchanged.
