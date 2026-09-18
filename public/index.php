<?php

declare(strict_types=1);

use App\Controller\Api\ProductAvailabilityController;
use App\Controller\AuthController;
use App\Controller\CategoryController;
use App\Controller\CustomerController;
use App\Controller\DashboardController;
use App\Controller\ProductController;
use App\Controller\PurchaseOrderController;
use App\Controller\ReportController;
use App\Controller\SalesOrderController;
use App\Controller\SupplierController;
use App\Controller\UserController;
use App\Controller\WarehouseController;
use App\Exception\HttpException;
use App\Http\ErrorResponder;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repository\MySql\MySqlCategoryRepository;
use App\Repository\MySql\MySqlCustomerRepository;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlLoginAttemptRepository;
use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlPurchaseOrderRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlSupplierRepository;
use App\Repository\MySql\MySqlUserRepository;
use App\Repository\MySql\MySqlWarehouseRepository;
use App\Security\AuthGuard;
use App\Security\NativeSessionManager;
use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\CategoryService;
use App\Service\CustomerService;
use App\Service\DashboardService;
use App\Service\LoginRateLimiter;
use App\Service\MasterDataAuthorizationService;
use App\Service\ProductService;
use App\Service\ProductAvailabilityService;
use App\Service\PurchaseOrderService;
use App\Service\ReportService;
use App\Service\SalesOrderService;
use App\Service\SupplierService;
use App\Service\StockService;
use App\Service\UserService;
use App\Service\WarehouseService;
use App\Support\Config;
use App\Support\DatabaseFactory;
use App\Support\RequestAuditRecorder;

/** @var Config $config */
$config = require dirname(__DIR__) . '/config/bootstrap.php';

$router = new Router();
$session = new NativeSessionManager();
$GLOBALS['csrf_token'] = $session->csrfToken();
$pdo = (new DatabaseFactory($config))->create();
$userRepository = new MySqlUserRepository($pdo);
$categoryRepository = new MySqlCategoryRepository($pdo);
$warehouseRepository = new MySqlWarehouseRepository($pdo);
$productRepository = new MySqlProductRepository($pdo);
$supplierRepository = new MySqlSupplierRepository($pdo);
$customerRepository = new MySqlCustomerRepository($pdo);
$purchaseOrderRepository = new MySqlPurchaseOrderRepository($pdo);
$salesOrderRepository = new MySqlSalesOrderRepository($pdo);
$stockRepository = new MySqlStockRepository($pdo);
$stockLedgerRepository = new MySqlStockLedgerRepository($pdo);
$auditLogRepository = new MySqlAuditLogRepository($pdo);
$loginAttemptRepository = new MySqlLoginAttemptRepository($pdo);
$operationalQueries = new MySqlOperationalQueryRepository($pdo);
$authGuard = new AuthGuard($session);
$auditLogger = new AuditLogger($auditLogRepository);
$requestAuditRecorder = new RequestAuditRecorder($auditLogger);
$authController = new AuthController(new AuthService($userRepository, $session, $auditLogger, new LoginRateLimiter($loginAttemptRepository)));
$userController = new UserController(new UserService($userRepository), $userRepository, $authGuard);
$masterDataAuthorization = new MasterDataAuthorizationService();
$categoryController = new CategoryController(
    new CategoryService($categoryRepository, $masterDataAuthorization),
    $categoryRepository,
    $authGuard,
);
$warehouseController = new WarehouseController(
    new WarehouseService($warehouseRepository, $masterDataAuthorization),
    $warehouseRepository,
    $authGuard,
);
$productController = new ProductController(
    new ProductService($productRepository, $masterDataAuthorization),
    $productRepository,
    $categoryRepository,
    $authGuard,
);
$supplierController = new SupplierController(
    new SupplierService($supplierRepository, $masterDataAuthorization),
    $supplierRepository,
    $authGuard,
);
$customerController = new CustomerController(
    new CustomerService($customerRepository, $masterDataAuthorization),
    $customerRepository,
    $authGuard,
);
$suppliersById = [];
foreach ($supplierRepository->all() as $supplier) {
    $suppliersById[$supplier->id()] = $supplier;
}
$warehousesById = [];
foreach ($warehouseRepository->all() as $warehouse) {
    $warehousesById[$warehouse->id()] = $warehouse;
}
$customersById = [];
foreach ($customerRepository->all() as $customer) {
    $customersById[$customer->id()] = $customer;
}
$purchaseOrderController = new PurchaseOrderController(
    new PurchaseOrderService(
        $purchaseOrderRepository,
        $productRepository,
        $suppliersById,
        $warehousesById,
        new StockService($stockRepository, $stockLedgerRepository),
    ),
    $purchaseOrderRepository,
    $productRepository,
    $suppliersById,
    $warehousesById,
    $authGuard,
);
$salesOrderController = new SalesOrderController(
    new SalesOrderService(
        $salesOrderRepository,
        $productRepository,
        $customersById,
        $warehousesById,
        new StockService($stockRepository, $stockLedgerRepository),
    ),
    $salesOrderRepository,
    $productRepository,
    $customersById,
    $warehousesById,
    $authGuard,
);
$dashboardController = new DashboardController(new DashboardService($operationalQueries), $authGuard);
$reportController = new ReportController(new ReportService($operationalQueries), $authGuard);
$availabilityController = new ProductAvailabilityController(new ProductAvailabilityService($operationalQueries), $authGuard);

$router->get('/', [$dashboardController, 'index']);
$router->get('/dashboard', [$dashboardController, 'index']);
$router->get('/reports', [$reportController, 'index']);
$router->get('/reports/stock-ledger.csv', [$reportController, 'stockLedger']);
$router->get('/reports/orders.csv', [$reportController, 'orders']);
$router->get('/api/products/{sku}/availability', [$availabilityController, 'show']);
$router->get('/login', [$authController, 'showLogin']);
$router->post('/login', [$authController, 'login']);
$router->post('/logout', [$authController, 'logout']);
$router->get('/users', [$userController, 'index']);
$router->get('/users/create', [$userController, 'create']);
$router->post('/users', [$userController, 'store']);
$router->post('/users/import', [$userController, 'import']);
$router->get('/users/edit', [$userController, 'edit']);
$router->post('/users/update', [$userController, 'update']);
$router->post('/users/activate', [$userController, 'activate']);
$router->post('/users/deactivate', [$userController, 'deactivate']);
$router->get('/categories', [$categoryController, 'index']);
$router->get('/categories/create', [$categoryController, 'create']);
$router->post('/categories', [$categoryController, 'store']);
$router->post('/categories/import', [$categoryController, 'import']);
$router->get('/categories/edit', [$categoryController, 'edit']);
$router->post('/categories/update', [$categoryController, 'update']);
$router->post('/categories/activate', [$categoryController, 'activate']);
$router->post('/categories/deactivate', [$categoryController, 'deactivate']);
$router->get('/warehouses', [$warehouseController, 'index']);
$router->get('/warehouses/create', [$warehouseController, 'create']);
$router->post('/warehouses', [$warehouseController, 'store']);
$router->post('/warehouses/import', [$warehouseController, 'import']);
$router->get('/warehouses/edit', [$warehouseController, 'edit']);
$router->post('/warehouses/update', [$warehouseController, 'update']);
$router->post('/warehouses/activate', [$warehouseController, 'activate']);
$router->post('/warehouses/deactivate', [$warehouseController, 'deactivate']);
$router->get('/products', [$productController, 'index']);
$router->get('/products/create', [$productController, 'create']);
$router->post('/products', [$productController, 'store']);
$router->post('/products/import', [$productController, 'import']);
$router->get('/products/edit', [$productController, 'edit']);
$router->post('/products/update', [$productController, 'update']);
$router->post('/products/activate', [$productController, 'activate']);
$router->post('/products/deactivate', [$productController, 'deactivate']);
$router->get('/purchase-orders', [$purchaseOrderController, 'index']);
$router->get('/purchase-orders/show', [$purchaseOrderController, 'show']);
$router->get('/purchase-orders/create', [$purchaseOrderController, 'create']);
$router->post('/purchase-orders', [$purchaseOrderController, 'store']);
$router->post('/purchase-orders/order', [$purchaseOrderController, 'order']);
$router->post('/purchase-orders/receive', [$purchaseOrderController, 'receive']);
$router->post('/purchase-orders/cancel', [$purchaseOrderController, 'cancel']);
$router->get('/sales-orders', [$salesOrderController, 'index']);
$router->get('/sales-orders/show', [$salesOrderController, 'show']);
$router->get('/sales-orders/create', [$salesOrderController, 'create']);
$router->post('/sales-orders', [$salesOrderController, 'store']);
$router->post('/sales-orders/submit', [$salesOrderController, 'submit']);
$router->post('/sales-orders/approve', [$salesOrderController, 'approve']);
$router->post('/sales-orders/cancel', [$salesOrderController, 'cancel']);
$router->post('/sales-orders/issue', [$salesOrderController, 'issue']);
$router->get('/suppliers', [$supplierController, 'index']);
$router->get('/suppliers/create', [$supplierController, 'create']);
$router->post('/suppliers', [$supplierController, 'store']);
$router->post('/suppliers/import', [$supplierController, 'import']);
$router->get('/suppliers/edit', [$supplierController, 'edit']);
$router->post('/suppliers/update', [$supplierController, 'update']);
$router->post('/suppliers/activate', [$supplierController, 'activate']);
$router->post('/suppliers/deactivate', [$supplierController, 'deactivate']);
$router->get('/customers', [$customerController, 'index']);
$router->get('/customers/create', [$customerController, 'create']);
$router->post('/customers', [$customerController, 'store']);
$router->post('/customers/import', [$customerController, 'import']);
$router->get('/customers/edit', [$customerController, 'edit']);
$router->post('/customers/update', [$customerController, 'update']);
$router->post('/customers/activate', [$customerController, 'activate']);
$router->post('/customers/deactivate', [$customerController, 'deactivate']);

$request = Request::fromGlobals();

try {
    if ($request->method() === 'POST' && !$session->isValidCsrfToken((string) ($request->post()['csrf_token'] ?? ''))) {
        throw new HttpException(403, 'Invalid CSRF token.');
    }
    $response = $router->dispatch($request);
} catch (HttpException $exception) {
    $response = str_starts_with($request->path(), '/api/')
        ? ErrorResponder::api($exception->statusCode() === 401 ? 'Authentication required' : $exception->getMessage(), $exception->statusCode())
        : ($exception->statusCode() === 401 ? new Response('', 302, ['Location' => '/login']) : ErrorResponder::browser($exception, $config->bool('APP_DEBUG')));
} catch (Throwable $throwable) {
    $status = method_exists($throwable, 'statusCode') && is_int($throwable->statusCode()) ? $throwable->statusCode() : 500;
    $response = str_starts_with($request->path(), '/api/')
        ? ErrorResponder::api($status === 500 ? 'Unexpected server error.' : $throwable->getMessage(), $status)
        : ErrorResponder::browser($throwable, $config->bool('APP_DEBUG'));
}

$requestAuditRecorder->record($request, $response, $session->auth());
$response->send();
