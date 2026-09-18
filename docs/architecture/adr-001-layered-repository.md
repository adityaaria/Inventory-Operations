# ADR-001: Layered Controller-Service-Repository Boundary

Status: Accepted  
Date: 2026-08-31

## Context

The assessment requires native PHP OOP, repository interface boundaries, manual constructor injection, unit tests, and no framework DI container or ORM.

## Decision

Use Controller -> Service -> Repository separation. Services depend on repository interfaces. MySQL repositories use PDO prepared statements. InMemory repositories support unit tests. Dependencies are wired manually at the composition root.

## Consequences

Business logic stays testable without MySQL. More files are required, but ownership boundaries are explicit and defensible.

## Implementation Evidence

- Manual wiring is in `public/index.php`.
- Repository contracts live in `app/Repository/Contract`.
- Runtime persistence implementations live in `app/Repository/MySql` and use PDO.
- Unit-test fakes live in `app/Repository/InMemory`.
- Services such as `PurchaseOrderService`, `SalesOrderService`, `ReportService`, and `DashboardService` depend on contracts instead of concrete MySQL repositories.
- Verified by `composer test` and `composer analyse` on 2026-08-31.
