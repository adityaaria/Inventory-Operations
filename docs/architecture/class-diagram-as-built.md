# As-Built Class Diagram

Date: 2026-08-31  
Source checked: `app/**/*.php`, `public/index.php`, `composer.json`

Management pagination update: 2026-10-06. Existing grouped layer structure retained; `Pagination` now joins the Support value objects used by Management controllers/services/repositories.

This diagram reflects the implemented native PHP architecture after Phase 6. It intentionally groups repeated CRUD classes where the dependency shape is the same.

```mermaid
classDiagram
    class FrontController {
        public/index.php
        manual constructor wiring
        route registration
        CSRF gate
    }

    class CsvImportService
    class TransactionManagerInterface {
        <<interface>>
    }
    class MySqlTransactionManager
    class Router
    class Request
    class Response
    class ErrorResponder

    class AuthController
    class UserController
    class MasterDataControllers {
        CategoryController
        WarehouseController
        ProductController
        SupplierController
        CustomerController
    }
    class PurchaseOrderController
    class SalesOrderController
    class DashboardController
    class ReportController
    class ProductAvailabilityController

    class AuthGuard
    class SessionManager
    class NativeSessionManager
    class Authorization
    class Csrf

    class AuthService
    class UserService
    class MasterDataServices {
        CategoryService
        WarehouseService
        ProductService
        SupplierService
        CustomerService
        MasterDataAuthorizationService
    }
    class PurchaseOrderService
    class SalesOrderService
    class StockService
    class DashboardService
    class ReportService
    class ProductAvailabilityService
    class LowStockService

    class RepositoryInterfaces {
        UserRepositoryInterface
        ProductRepositoryInterface
        CategoryRepositoryInterface
        WarehouseRepositoryInterface
        SupplierRepositoryInterface
        CustomerRepositoryInterface
        PurchaseOrderRepositoryInterface
        SalesOrderRepositoryInterface
        StockRepositoryInterface
        StockLedgerRepositoryInterface
        OperationalQueryRepositoryInterface
    }
    class MySqlRepositories {
        PDO prepared statements
        MySQL persistence
    }
    class InMemoryRepositories {
        fake repositories
        unit test support
    }

    class Entities {
        User
        Product
        ProductStock
        Warehouse
        Category
        Supplier
        Customer
        PurchaseOrder
        PurchaseOrderItem
        SalesOrder
        SalesOrderItem
        StockLedgerEntry
    }

    class Support {
        Config
        DatabaseFactory
        PaginatedResult
        Pagination
        ProductSearchCriteria
        OrderSearchCriteria
        CsvResponse
        Html
    }

    class InputValidator

    FrontController --> Router
    FrontController --> NativeSessionManager
    FrontController --> MySqlRepositories
    FrontController --> ErrorResponder
    FrontController --> AuthController
    FrontController --> UserController
    FrontController --> MasterDataControllers
    FrontController --> PurchaseOrderController
    FrontController --> SalesOrderController
    FrontController --> DashboardController
    FrontController --> ReportController
    FrontController --> ProductAvailabilityController

    Router --> Request
    Router --> Response
    ErrorResponder --> Response

    AuthController --> AuthService
    UserController --> UserService
    MasterDataControllers --> MasterDataServices
    PurchaseOrderController --> PurchaseOrderService
    SalesOrderController --> SalesOrderService
    DashboardController --> DashboardService
    ReportController --> ReportService
    ProductAvailabilityController --> ProductAvailabilityService

    UserController --> AuthGuard
    MasterDataControllers --> AuthGuard
    PurchaseOrderController --> AuthGuard
    SalesOrderController --> AuthGuard
    DashboardController --> AuthGuard
    ReportController --> AuthGuard
    ProductAvailabilityController --> AuthGuard

    AuthGuard --> SessionManager
    AuthGuard --> Authorization
    AuthGuard --> RepositoryInterfaces : current user state
    NativeSessionManager --|> SessionManager
    NativeSessionManager --> Csrf

    AuthService --> RepositoryInterfaces
    UserService --> RepositoryInterfaces
    MasterDataServices --> RepositoryInterfaces
    PurchaseOrderService --> RepositoryInterfaces
    PurchaseOrderService --> StockService
    SalesOrderService --> RepositoryInterfaces
    SalesOrderService --> StockService
    DashboardService --> RepositoryInterfaces
    ReportService --> RepositoryInterfaces
    ProductAvailabilityService --> RepositoryInterfaces
    LowStockService --> RepositoryInterfaces

    StockService --> RepositoryInterfaces : source transaction, ledger and strict audit
    MasterDataControllers --> CsvImportService
    UserController --> CsvImportService
    CsvImportService --> TransactionManagerInterface
    TransactionManagerInterface <|.. MySqlTransactionManager
    RepositoryInterfaces <|.. MySqlRepositories
    RepositoryInterfaces <|.. InMemoryRepositories
    MySqlRepositories --> Entities
    InMemoryRepositories --> Entities
    MasterDataServices --> InputValidator
    MasterDataServices --> Entities
    PurchaseOrderService --> Entities
    SalesOrderService --> Entities
    ReportService --> Support
```

Key evidence:

- Dependency direction is controller to service to repository interface. MySQL and in-memory repositories implement the same contracts.
- `public/index.php` is the composition root and uses manual constructor injection.
- `StockService` is the only service that begins stock transactions and writes stock ledger entries.
- `NativeSessionManager` owns PHP session persistence; controllers and services consume `AuthContext` through `AuthGuard`/`SessionManager`.
- Search and pagination criteria are value objects under `app/Support`, not SQL fragments from controllers.

Audit remediation update (6 October 2026): source-order locks precede deterministic stock locks; order, stock, ledger and critical audit share one transaction. AuthGuard resolves current users through the repository boundary. CsvImportService uses TransactionManagerInterface for atomic batches, while report preview uses repository aggregates/LIMIT and HTTP CSV streams rows. Supplier/customer/warehouse lookups are loaded only for their order routes; list stock/items use batch queries.

## Catalog balance initialization — 7 October 2026

```mermaid
classDiagram
    ProductService --> StockService : atomic create + zero pairs
    WarehouseService --> StockService : atomic create + zero pairs
    StockService --> StockCatalogRepositoryInterface : catalog mutex + sorted IDs
    StockService --> TransactionManagerInterface : shared transaction owner
    StockService --> StockRepositoryInterface : lock existing or insert zero
    MySqlStockCatalogRepository ..|> StockCatalogRepositoryInterface
    InMemoryStockCatalogRepository ..|> StockCatalogRepositoryInterface
    MySqlTransactionManager ..|> TransactionManagerInterface
    CsvImportService --> TransactionManagerInterface : enclosing atomic batch
```

The runtime manually injects one shared StockService/TransactionManager. Opening zero pairs generate no goods movement; existing stock/ledger deltas remain exclusively in receipt/issue transactions. FormState is presentation input recovery; it does not mutate repositories/entities. ADR-006 records the initialization mutex and import nesting behavior.
