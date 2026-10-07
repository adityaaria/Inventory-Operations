# Pending business decisions

Updated 7 October 2026. This register distinguishes implemented temporary defaults from trainer-approved rules. No trainer approval has been supplied for the open rows below. Do not change statuses or stock semantics solely from these recommendations.

| ID | Question | Current implemented default | Recommendation / tradeoff | Status |
|---|---|---|---|---|
| D-01 | Cancel PO after partial receipt? | Admin can cancel Draft/Ordered only; user-approved optional close-remainder retains PartiallyReceived and received goods (ADR-009). | Preserve goods already received and their ledger. If remaining supply is abandoned, define a separate close-remainder operation after approval; cancellation must not silently reverse receipts. Returns require an explicit separate stock workflow. Adding a Closed status requires a documented trainer decision. | Pending trainer |
| D-02 | SO rejection representation? | Reject/cancel maps to Cancelled; user-approved rejection requires separately stored reason (ADR-009); Fulfilled cannot be cancelled. | Keep the brief's status set; capture rejection reason/action separately if approved, rather than inventing Rejected. | Pending trainer |
| D-03 | May Admin approve their own SO? | Admin-created SO may be approved by an Admin, including its creator; approver/time recorded. | For stronger segregation prohibit creator approval and require a second Admin. This may block teams with one Admin, so agree staffing/exception ownership first. No self-approval restriction has been enabled yet. | Pending trainer |
| D-04 | Duplicate item lines? | Duplicate product lines rejected in PO/SO. | Keep rejection until price/merge semantics are agreed; silent merge can hide different prices or quantities. | Pending trainer |
| D-05 | Meaning of low-stock across warehouses? | Active product + active warehouse pair with quantity < product reorder_point; dashboard counts qualifying pairs. | Prefer per-warehouse alerts because availability in another warehouse is not locally usable without an approved transfer. Label pair count separately from unique product count. Product-level reorder points apply equally to warehouses today; per-warehouse thresholds are a future scope decision. | Pending trainer |
| D-06 | Order-number format? | Nonempty unique provided order number; existing seed formats are examples. | Agree prefix, date/time zone and uniqueness before automatic numbering; a server sequence must handle concurrency. | Pending trainer |
| D-07 | Aging definition for outstanding report? | User-approved 2026-10-07 (optional scope, ADR-012): age = whole days since creation in operational buckets 0–2/3–7/8–30/31+; `DaysSinceApproval` shown only for Approved SO and stock proposals (from `approved_at`; POs record no ordered-at time); Warehouse sees only receipts/issues/approved proposals awaiting action; no overdue flag. | Overdue/SLA needs trainer-defined targets per stage (e.g. approval, issue after approval, receipt vs supplier lead time) and, for PO, an ordered-at timestamp. Bucket bounds change without schema impact. | User-approved default; trainer confirmation pending |
| D-08 | May form drafts be kept on the user's device? | User-approved 2026-10-07 (optional scope, ADR-014): browser localStorage per user/role; lifetime equals `SESSION_ABSOLUTE_SECONDS` (8 h default) via `data-draft-ttl`; cleared on send and logout, not on the login page (so recovery after session timeout works); no secrets or count baselines stored. | Server-side drafts only if cross-device access or auditable drafts become a requirement (needs schema, retention and access rules). Residual risk: draft contents remain on a shared computer for up to the session limit if the user skips logout. | User-approved default; trainer confirmation pending |

## Resolved engineering decisions (not trainer policy)

- Local assessment runtime stays on port 8080 with native PHP built-in server; the independent production topology uses Nginx/PHP-FPM/TLS (ADR-007).
- Transaction ownership is implemented with a dedicated shared TransactionManagerInterface, MySqlTransactionManager and explicit constructor injection (ADR-004/006). The former note that PDO ownership awaited trainer confirmation is obsolete.
- `composer test`, `test:unit`, `test:integration`, `analyse` remain the development commands; isolated Docker quality tooling is authoritative for current verification.

## Confirmation process

Record the trainer's exact answer, date and decision owner; update the applicable SDD/ADR; implement only the approved scope with authorization, transition, rollback and ledger tests. Recommendations above are engineering insight, not inferred approval from the user's request to enhance operations.

User approved ADR-009 optional policies on 2026-10-07. D-01/D-02/D-05 remain trainer-pending; this scope approval is not an official brief amendment.
