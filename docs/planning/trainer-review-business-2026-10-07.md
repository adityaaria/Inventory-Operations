# Business policy review for trainer — 7 October 2026

Prepared for review only. No trainer approval recorded. User approved six optional workflows; see ADR-009 and DECISIONS_PENDING.md. The native PHP/PDO/Vanilla JS stack and official PO/SO status sets remain intact.

| Decision | Current implementation to demonstrate | Trainer question | Answer / owner / date |
|---|---|---|---|
| D-01 | Admin closes remaining supply on PartiallyReceived PO with reason; status/received goods/original ledger remain. New receipt denied; prior successful receipt key replays. | Accept separate remainder closure metadata without adding an official PO status? | Pending |
| D-02 | PendingApproval SO rejected to Cancelled, with mandatory separately retained reason; Sales retains own-order visibility. | Accept Cancelled plus rejection reason, without introducing Rejected or automatic resubmit? | Pending |
| D-03 | Mandatory SO still allows Admin creator approval. New stock documents require a different Admin reviewer. | Retain SO policy or separately authorize a self-approval restriction with adequate reviewer staffing? | Pending |
| D-05 | Low-stock counts active product–warehouse pairs below product reorder point. Recommendations additionally subtract outstanding local PO supply, excluding closed remainder. | Confirm per-warehouse low-stock semantics and a shared product-level threshold? | Pending |
| Optional stock flows | Independent approval for counted adjustment/direct atomic transfer/original-movement returns; signed Adjustment ledger, stale-count rejection, bounded cumulative returns. Customer goods must be fit for stock. | Confirm these optional flows fit assessment scope without in-transit/refund/accounting/quarantine? | Pending |

Demonstrate on isolated acceptance data: propose as WarehouseStaff, approve as another Admin, post, verify signed ledger/report/audit and repeated-post no-op. Include invalid-state/insufficient-stock/stale-count/over-return cases. Preserve stock from received PO goods when closing remainder. Do not convert this prepared document into an approved ADR or official requirement until the trainer's actual answer is recorded.

Engineering verification is in docs/testing/business-enhancements-2026-10-07.md. Android/iPhone physical results remain NOT RUN in docs/operations/device-acceptance.md.
