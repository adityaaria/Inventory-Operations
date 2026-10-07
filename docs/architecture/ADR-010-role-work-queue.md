# ADR-010: role-scoped read-only work queue

Date: 7 October 2026. Optional scope authorized by user continuation after recommending role-specific queue. Requirement support: DASH-01, UI-01, AUTH-01/02, ARCH-01/02, DB-01, TEST-01/02/03. No new trainer policy or order/operation status.

WorkQueueController handles HTTP/authentication; WorkQueueService validates role-specific filters, pagination and detail links; WorkQueueRepositoryInterface supplies counts/pages, implemented with native prepared MySQL UNION queries and manual injection. `/work-queue` is available to the three existing authenticated roles.

| Role | Dataset |
|---|---|
| Admin | PendingApproval SOs; PendingApproval stock proposals made by another user; open PO receipts; Approved SO issues; Approved stock operation posting |
| WarehouseStaff | Ordered/PartiallyReceived PO receipts excluding closed remainder; Approved SO issues; Approved stock operation posting |
| Sales | Own Draft SOs for review/submit; own PendingApproval/Approved SOs for tracking |

Admin creator exclusion applies only to optional stock proposal review; mandatory SO self-approval remains unchanged. Sales follows progress rather than receiving approval/stock actions. No Warehouse-specific assignment is invented: current authorization spans accessible warehouses. Counts and pages use identical role/search/type predicates, ten rows per page and oldest-created-first ordering with type/id tie-breakers. Search `%`, `_` and `!` are literal via escaped prepared LIKE.

Age is whole elapsed days since document creation, computed with MySQL's clock; it is not time in current state, a due date, SLA or lateness assertion. Matching-task cards reflect current filters. Status/source mutations are never performed here; links go to existing authorized detail routes, where actions revalidate current state, permissions and stock. Queue membership may change while an operator views it; it does not reserve stock or authorize an action by itself.

No schema/index/transaction changes; no background notifications, batch approvals or new workflow transitions. Approval staffing and trainer-pending decisions stay in DECISIONS_PENDING.md. Physical-device evidence is separate from Chrome emulation. Verification: docs/testing/work-queue-2026-10-07.md.
