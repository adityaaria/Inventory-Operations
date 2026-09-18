# Refactor Log

Date: 2026-08-31  
Scope: Phases 0-7 implementation evidence

| Entry | Smell / Pressure | Technique | Before | After | Verification |
|---|---|---|---|---|---|
| R-001 | Authentication checks risked becoming ad hoc in controllers. | Extracted authorization/session boundary. | Controllers would need to inspect raw session state and roles directly. | `AuthGuard`, `AuthContext`, `Authorization`, and `SessionManager` centralize server-side auth decisions. | `AuthorizationTest`, `SessionManagerTest`, `AuthServiceTest`, and full `composer test`. |
| R-002 | MySQL persistence would make service tests slow and brittle. | Introduced repository interfaces and in-memory repositories. | Services would have been coupled directly to PDO repositories. | Services depend on `*RepositoryInterface`; `MySql*` repositories serve runtime and `InMemory*` repositories serve unit tests. | Repository/service unit tests plus MySQL integration tests. |
| R-003 | Receipt and issue workflows duplicated stock mutation rules and rollback risk. | Extracted transaction orchestration to `StockService`. | PO receipt and SO issue could each update stock and ledger independently. | `PurchaseOrderService` and `SalesOrderService` call `StockService::receive()` / `issue()` with source-state callbacks. | `StockServiceReceiptTest`, `StockServiceIssueTest`, `PurchaseOrderReceiptIntegrationTest`, `SalesOrderIssueIntegrationTest`. |
| R-004 | CSV response and formula escaping could spread across report controllers. | Extracted response/formatting helper. | Report endpoints would build headers and rows manually. | `CsvResponse` and `ReportService` own CSV generation and formula neutralization. | `ReportServiceTest` and HTTP report smoke test. |
| R-005 | Error responses and validation failures were inconsistent across browser/API paths. | Added explicit error responder and shared validation helpers. | Front controller returned mixed raw exception output and services used repeated small checks. | `ErrorResponder`, `ValidationException`, and `InputValidator` provide consistent status/message behavior while preserving existing service rules. | `ErrorResponseTest`, `ValidationTest`, `SecurityAuditTest`, full `composer test`, `composer analyse`. |

Git note: the workspace is not initialized as a Git repository, so commit-based refactor evidence cannot be produced here. The refactor entries above are tied to current files and tests instead.
