# ADR-012: role-scoped outstanding and aging report

Date: 7 October 2026. Optional scope authorized by user continuation ("lanjutkan enhance") after ADR-010 recommended outstanding/aging reports. Requirement support: REPORT-01, DASH-01, AUTH-01/02, ARCH-01/02, DB-01, TEST-01/02/03. No new trainer policy, order status or schema change.

`/reports?type=outstanding` adds a third report beside Order Status and Stock Movements, with the same summary cards, charts, ten-row preview and streamed CSV (`/reports/outstanding.csv`). ReportController handles HTTP only; ReportService derives scope from the authenticated role and validates type/date range; OperationalQueryRepositoryInterface gains `outstandingSummary`, `outstandingPage` and `iterateOutstandingRows`, implemented with prepared native MySQL and an in-memory fake. Preview, summary and CSV share one source query per scope.

| Role | Open documents included |
|---|---|
| Admin | PO Draft/Ordered/PartiallyReceived without closed remainder; SO Draft/PendingApproval/Approved; stock proposals (OP) PendingApproval/Approved |
| WarehouseStaff | PO Ordered/PartiallyReceived without closed remainder (awaiting receipt); SO Approved (awaiting issue); OP Approved (awaiting posting) |
| Sales | Own SO Draft/PendingApproval/Approved only (`created_by` bound server-side) |

Stock proposals (ADR-009 adjustment/transfer/return) are listed as `<Kind> #<id>` with warehouse (or source → destination) as party and no unit total, because counts, transfers and returns do not share one direction; Posted/Rejected/Cancelled proposals are excluded. Received, Fulfilled and Cancelled documents and PO remainders closed under ADR-009 are excluded. Warehouse scope is a relevance focus matching the ADR-010 work queue, not a new restriction: Warehouse can still view all orders in the Order Status report.

**Age** is whole elapsed days since document creation (`created_at`), computed with the MySQL clock as in ADR-010. It is not time in current status, a due date, SLA or lateness assertion; the page states this explicitly. Buckets are 0–2, 3–7, 8–30 and 31+ days (D-07, user-approved 2026-10-07; operational documents move in days, so receivable-style month buckets hid stalled work). `DaysSinceApproval` shows whole days since `approved_at` for Approved SO and stock proposals only; it is empty elsewhere because POs record no ordered-at time and pending documents have no approval. Summary metric "Open Over 7 Days" counts the 8–30 and 31+ buckets. The date filter applies to creation date. Document (PO/SO/OP) and age-bucket filters are validated by ReportService against the actor's scope (Sales may only choose SO) before any query; an `OutstandingCriteria` value object carries them to the repository. Filters persist across pagination and CSV export.

**Outstanding quantity**: PO = Σ(ordered − received) per document; SO = Σ ordered quantity (issue is all-or-nothing). Inbound (PO) and outbound (SO) units are reported separately and never summed. Ordering is oldest first, with type/id tie-breakers.

Read-only GET; no stock, ledger, transaction or state change. Existing CSV formula-injection escaping applies. Verification: docs/testing/outstanding-aging-2026-10-07.md.
