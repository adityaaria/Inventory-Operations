# SRP Audit

Date: 2026-08-31

| Area | Primary Responsibility | Evidence | Assessment |
|---|---|---|---|
| Controllers | HTTP input/output, auth guard calls, response rendering. | `app/Controller/*Controller.php` | Acceptable. Controllers still prepare simple arrays from request data, but business transitions are delegated to services. |
| Services | Business rules, state transitions, authorization orchestration, stock transaction orchestration. | `PurchaseOrderService`, `SalesOrderService`, `StockService`, `ReportService` | Acceptable. `StockService` owns a larger invariant, but that is intentional because stock balance and ledger must be atomic. |
| Repositories | Persistence and SQL only. | `app/Repository/MySql/*Repository.php` | Acceptable. SQL is contained in repositories; user-input values use prepared statements and allow-listed sort keys. |
| Repository Interfaces | Dependency inversion boundary for services and unit tests. | `app/Repository/Contract/*Interface.php` | Acceptable. Interfaces are narrow enough for current workflows and mirrored by in-memory repositories. |
| Entities | Domain data and simple state helpers. | `app/Entity/*.php` | Acceptable. Entities do not perform persistence or HTTP work. |
| Security | Session, CSRF, authenticated identity, and central user-management authorization. | `app/Security/*` | Acceptable. Some role rules remain in PO/SO services because they are workflow-specific business rules. |
| Support | Cross-cutting value objects/helpers for config, criteria, pagination, CSV, HTML escaping. | `app/Support/*` | Acceptable. Support classes avoid depending on controllers/services. |
| Front Controller | Composition root, route map, top-level CSRF/error handling. | `public/index.php` | Accepted tradeoff. Manual wiring is verbose but required by the no-framework/no-container constraint. |

Follow-up debt:

- `public/index.php` will grow as more optional workflows are added; consider extracting a native `ContainerFactory` only if the composition root becomes hard to navigate.
- `Authorization` currently exposes only user-management policy; broader role policy could move there after mandatory workflows are stable.
- Product image upload is out of mandatory scope; if added later, create a dedicated upload validation/storage service.
