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
use App\Support\ApplicationBootstrap;
use App\Support\DatabaseFactory;
use App\Support\RequestAuditRecorder;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$config = ApplicationBootstrap::boot(dirname(__DIR__));

$router = new Router();
$request = Request::fromGlobals();
// 'auto' forces secure cookies in production and lets PHP decide elsewhere (null).
$secureCookie = $config->string('APP_ENV') === 'production' ? true : null;
if ($config->string('SESSION_COOKIE_SECURE') !== 'auto') {
    $secureCookie = $config->bool('SESSION_COOKIE_SECURE');
}
if ($config->string('APP_ENV') === 'production' && $secureCookie !== true) {
    throw new \App\Exception\InfrastructureException('Production sessions require secure cookies.');
}
$session = new NativeSessionManager(
    new \App\Security\SessionPolicy($config->int('SESSION_IDLE_SECONDS'), $config->int('SESSION_ABSOLUTE_SECONDS'), $config->int('SESSION_ROTATION_SECONDS')),
    $secureCookie,
    null,
    $config->string('SESSION_SAVE_PATH'),
);

$pdo = (new DatabaseFactory($config))->create();
$userRepository = new MySqlUserRepository($pdo);
$categoryRepository = new MySqlCategoryRepository($pdo);
$warehouseRepository = new MySqlWarehouseRepository($pdo);
$productRepository = new MySqlProductRepository($pdo);
// Units of measure (D-16, ADR-022): stock stays in base units; conversion only at input and display.
$productUnitRepository = new \App\Repository\MySql\MySqlProductUnitRepository($pdo);
$productUnits = new \App\Service\ProductUnitCatalog($productRepository, $productUnitRepository);
$supplierRepository = new MySqlSupplierRepository($pdo);
$customerRepository = new MySqlCustomerRepository($pdo);
$purchaseOrderRepository = new MySqlPurchaseOrderRepository($pdo);
$salesOrderRepository = new MySqlSalesOrderRepository($pdo);
$stockRepository = new MySqlStockRepository($pdo);
$stockLedgerRepository = new MySqlStockLedgerRepository($pdo);
$auditLogRepository = new MySqlAuditLogRepository($pdo);
$loginAttemptRepository = new MySqlLoginAttemptRepository($pdo);
$operationalQueries = new MySqlOperationalQueryRepository($pdo, $config->int('NEAR_EXPIRY_DAYS'));
// Batch/lot expiry read models (D-17, ADR-023).
$lotService = new \App\Service\LotService(new \App\Repository\MySql\MySqlLotQueryRepository($pdo), $config->int('NEAR_EXPIRY_DAYS'));
// Inventory valuation FIFO per batch, COGS and gross margin (D-20, ADR-026): Admin-only read models.
$valuationService = new \App\Service\InventoryValuationService(new \App\Repository\MySql\MySqlInventoryValuationRepository($pdo));
$authGuard = new AuthGuard($session, $userRepository);
// Refresh presentation role from the same authoritative guard used by controllers.
try {
    $workspaceActor = $authGuard->requireAuth();
    $GLOBALS['workspace_role'] = $workspaceActor->role();
    // Scopes browser-local form drafts to one user and role; never used for authorization.
    $GLOBALS['workspace_draft_owner'] = $workspaceActor->userId() . ':' . $workspaceActor->role();
    $GLOBALS['workspace_draft_ttl'] = $config->int('SESSION_ABSOLUTE_SECONDS');
} catch (HttpException $exception) {
    if ($exception->statusCode() !== 401) { throw $exception; }
    $GLOBALS['workspace_role'] = '';
    $GLOBALS['workspace_draft_owner'] = '';
}
$GLOBALS['csrf_token'] = $session->csrfToken();
$GLOBALS['app_name'] = $config->string('APP_NAME');
$auditLogger = new AuditLogger($auditLogRepository);
$requestAuditRecorder = new RequestAuditRecorder($auditLogger);
$authController = new AuthController(new AuthService($userRepository, $session, $auditLogger, new LoginRateLimiter($loginAttemptRepository)));
$transactions = new \App\Repository\MySql\MySqlTransactionManager($pdo);
$orderExceptionsRepository = new \App\Repository\MySql\MySqlOrderExceptionRepository($pdo);
$operationIdempotency = new \App\Service\OperationIdempotency(new \App\Repository\MySql\MySqlOperationRequestRepository($pdo));
$stockService = new StockService($stockRepository, $stockLedgerRepository, $auditLogRepository, new \App\Repository\MySql\MySqlStockCatalogRepository($pdo), $transactions);
$orderExceptions = new \App\Service\OrderExceptionService($orderExceptionsRepository, $purchaseOrderRepository, $salesOrderRepository, $stockService, $auditLogRepository);
$businessOperations = new \App\Service\BusinessOperationService(new \App\Repository\MySql\MySqlBusinessOperationRepository($pdo), $stockService, $auditLogRepository, $productUnits, $productRepository);
$businessController = new \App\Controller\BusinessOperationController($businessOperations, $authGuard, $productRepository, $warehouseRepository, $productUnits);
$csvImports = new \App\Service\CsvImportService($transactions);
$userController = new UserController(new UserService($userRepository), $userRepository, $authGuard, $csvImports);
$masterDataAuthorization = new MasterDataAuthorizationService();
$categoryController = new CategoryController(
    new CategoryService($categoryRepository, $masterDataAuthorization),
    $categoryRepository,
    $authGuard,
    $csvImports,
);
$warehouseController = new WarehouseController(
    new WarehouseService($warehouseRepository, $masterDataAuthorization, $stockService),
    $warehouseRepository,
    $authGuard,
    $csvImports,
);
$productController = new ProductController(
    new ProductService($productRepository, $masterDataAuthorization, $stockService),
    $productRepository,
    $categoryRepository,
    $authGuard,
    $csvImports,
    new \App\Service\ReorderPointService($productRepository, $masterDataAuthorization, $auditLogRepository, $transactions),
    new \App\Service\ProductUnitService($productRepository, $productUnitRepository, $masterDataAuthorization, $auditLogRepository, $transactions),
    $productUnits,
    $lotService,
);
$supplierController = new SupplierController(
    new SupplierService($supplierRepository, $masterDataAuthorization),
    $supplierRepository,
    $authGuard,
    $csvImports,
);
$customerController = new CustomerController(
    new CustomerService($customerRepository, $masterDataAuthorization),
    $customerRepository,
    $authGuard,
    $csvImports,
);
$loginPath = '/login';
$purchaseOrdersPath = '/purchase-orders';
$salesOrdersPath = '/sales-orders';
// Party and warehouse names are only loaded for the order pages that display them. Quotation conversion creates an SO
// draft (D-19, ADR-025), so the quotation routes need the customer and warehouse entities too.
$onPurchaseOrders = str_starts_with($request->path(), $purchaseOrdersPath);
$onSalesOrders = str_starts_with($request->path(), $salesOrdersPath) || str_starts_with($request->path(), '/quotations');
$suppliersById = [];
foreach ($onPurchaseOrders ? $supplierRepository->all() : [] as $supplier) {
    $suppliersById[$supplier->id()] = $supplier;
}
$warehousesById = [];
foreach ($onPurchaseOrders || $onSalesOrders ? $warehouseRepository->all() : [] as $warehouse) {
    $warehousesById[$warehouse->id()] = $warehouse;
}
$customersById = [];
foreach ($onSalesOrders ? $customerRepository->all() : [] as $customer) {
    $customersById[$customer->id()] = $customer;
}
// Server-assigned PO/SO numbers (D-11, ADR-017).
$documentSequences = new \App\Repository\MySql\MySqlDocumentSequenceRepository($pdo);
// Negotiated line prices (D-13, ADR-019): master price by default, deviations need a reason; Sales discount cap.
$orderLinePricing = new \App\Service\OrderLinePricing($config->int('SALES_MAX_DISCOUNT_PERCENT'));
$purchaseOrderController = new PurchaseOrderController(
    new PurchaseOrderService(
        $purchaseOrderRepository,
        $documentSequences,
        $productRepository,
        $suppliersById,
        $warehousesById,
        $stockService,
        $operationIdempotency,
        $orderExceptionsRepository,
        $orderLinePricing,
        $productUnits,
    ),
    $purchaseOrderRepository,
    $productRepository,
    $suppliersById,
    $warehousesById,
    $authGuard,
    $orderExceptions,
    $stockLedgerRepository,
    $productUnits,
);
// D-19 / ADR-025: customer price lists, quotations, delivery notes and invoices with PPN (settings validated at boot).
$taxSettings = \App\Support\TaxSettings::fromConfig($config);
$priceListRepository = new \App\Repository\MySql\MySqlPriceListRepository($pdo);
$priceResolver = new \App\Service\PriceResolver($priceListRepository, $productRepository, $productUnits);
$deliveryNoteRepository = new \App\Repository\MySql\MySqlDeliveryNoteRepository($pdo);
$deliveryNotes = new \App\Service\DeliveryNoteService($deliveryNoteRepository, $documentSequences, $customerRepository, $auditLogRepository);
$quotationRepository = new \App\Repository\MySql\MySqlQuotationRepository($pdo);
$salesOrderService = new SalesOrderService(
    $salesOrderRepository,
    $documentSequences,
    $productRepository,
    $customersById,
    $warehousesById,
    $stockService,
    $operationIdempotency,
    $orderLinePricing,
    $orderExceptionsRepository,
    $productUnits,
    $priceResolver,
    $deliveryNotes,
);
$salesOrderController = new SalesOrderController(
    $salesOrderService,
    $salesOrderRepository,
    $productRepository,
    $customersById,
    $warehousesById,
    $authGuard,
    $orderExceptions,
    $stockLedgerRepository,
    $productUnits,
    $deliveryNotes,
    $priceListRepository,
    $quotationRepository,
    $valuationService,
);
$invoiceService = new \App\Service\InvoiceService(new \App\Repository\MySql\MySqlInvoiceRepository($pdo), $deliveryNoteRepository, $salesOrderRepository, $customerRepository, $documentSequences, $transactions, $taxSettings, $auditLogRepository);
$quotationService = new \App\Service\QuotationService($quotationRepository, $documentSequences, $productRepository, $customerRepository, $warehouseRepository, $salesOrderService, $transactions, $productUnits, $orderLinePricing, $priceResolver, $auditLogRepository);
$priceListController = new \App\Controller\PriceListController(new \App\Service\PriceListService($priceListRepository, $customerRepository, $productRepository, $productUnits, $transactions, $auditLogRepository), $authGuard, $customerRepository, $productRepository);
$quotationController = new \App\Controller\QuotationController($quotationService, $authGuard, $customerRepository, $warehouseRepository, $productRepository, $productUnits);
$deliveryNoteController = new \App\Controller\DeliveryNoteController($deliveryNotes, $authGuard, $customerRepository, $valuationService);
$invoiceController = new \App\Controller\InvoiceController($invoiceService, $authGuard, $valuationService);
$valuationController = new \App\Controller\ValuationController($valuationService, $authGuard, $warehouseRepository, $categoryRepository, $customerRepository);
$priceResolutionController = new \App\Controller\Api\PriceResolutionController($priceResolver, $authGuard);
$dashboardController = new DashboardController(new DashboardService($operationalQueries, $invoiceService, $valuationService), $authGuard);
$reportController = new ReportController(new ReportService($operationalQueries), $authGuard);
$availabilityController = new ProductAvailabilityController(new ProductAvailabilityService($operationalQueries), $authGuard);

$router->get('/', [$dashboardController, 'index']);
$router->get('/dashboard', [$dashboardController, 'index']);
$auditTrailController = new \App\Controller\AuditTrailController(new \App\Service\AuditTrailService(new \App\Repository\MySql\MySqlAuditQueryRepository($pdo)), $authGuard);
$router->get('/audit-trail', [$auditTrailController, 'index']);
$router->get('/inventory-operations', [$businessController, 'index']);
$router->get('/inventory-operations/create', [$businessController, 'create']);
$router->get('/inventory-operations/show', [$businessController, 'show']);
$router->get('/inventory-operations/in-transit', [$businessController, 'inTransit']);
$router->get('/inventory-operations/balance', [$businessController, 'balance']);
$router->get('/inventory-operations/source', [$businessController, 'source']);
$router->post('/inventory-operations', [$businessController, 'store']);
$router->post('/inventory-operations/decide', [$businessController, 'decide']);
$router->post('/inventory-operations/post', [$businessController, 'post']);
$router->post('/inventory-operations/dispatch', [$businessController, 'dispatch']);
$router->post('/inventory-operations/receive', [$businessController, 'receive']);
$workQueueController = new \App\Controller\WorkQueueController(new \App\Service\WorkQueueService(new \App\Repository\MySql\MySqlWorkQueueRepository($pdo)), $authGuard);
$draftCheckController = new \App\Controller\DraftCheckController(new \App\Service\DraftCheckService($productRepository, $warehouseRepository, $stockRepository), $authGuard);
$timelineController = new \App\Controller\DocumentTimelineController(new \App\Service\DocumentTimelineService(new \App\Repository\MySql\MySqlDocumentTimelineRepository($pdo)), $authGuard);
$router->get('/timeline', [$timelineController, 'index']);
$router->get('/work-queue', [$workQueueController, 'index']);
$router->get('/drafts/check', [$draftCheckController, 'check']);
$router->get('/replenishment', [$businessController, 'recommendations']);
$router->post('/purchase-orders/close-remainder', [$purchaseOrderController, 'closeRemainder']);
$router->post('/sales-orders/reject', [$salesOrderController, 'reject']);
$router->get('/reports', [$reportController, 'index']);
$lotController = new \App\Controller\LotController($lotService, $authGuard, $warehouseRepository);
$router->get('/reports/expiry', [$lotController, 'expiry']);
$router->get('/reports/expiry.csv', [$lotController, 'expiryCsv']);
$router->get('/lots', [$lotController, 'index']);
$router->get('/lots/show', [$lotController, 'show']);
$router->post('/inventory-operations/expired-write-off', [$businessController, 'writeOffExpired']);
$router->get('/reports/stock-ledger.csv', [$reportController, 'stockLedger']);
$router->get('/reports/orders.csv', [$reportController, 'orders']);
$router->get('/reports/outstanding.csv', [$reportController, 'outstanding']);
$router->get('/api/products/{sku}/availability', [$availabilityController, 'show']);
// Barcode lookup and stock count sessions (D-18, ADR-024).
$barcodeController = new \App\Controller\Api\BarcodeController(new \App\Service\BarcodeService($productUnitRepository, $productRepository), $authGuard);
$router->get('/api/barcodes/{code}', [$barcodeController, 'show']);
$stockCountController = new \App\Controller\StockCountController(
    new \App\Service\StockCountService(
        new \App\Repository\MySql\MySqlStockCountRepository($pdo),
        $stockService,
        $stockRepository,
        $auditLogRepository,
        $productRepository,
        $userRepository,
        $warehouseRepository,
        $categoryRepository,
        $productUnits,
        \App\Support\CountTolerance::fromConfig($config->int('COUNT_RECOUNT_TOLERANCE_PERCENT'), $config->int('COUNT_RECOUNT_TOLERANCE_UNITS')),
    ),
    $authGuard,
    $warehouseRepository,
    $categoryRepository,
    $productRepository,
);
$router->get('/stock-counts', [$stockCountController, 'index']);
$router->get('/stock-counts/create', [$stockCountController, 'create']);
$router->get('/stock-counts/show', [$stockCountController, 'show']);
$router->post('/stock-counts', [$stockCountController, 'store']);
$router->post('/stock-counts/start', [$stockCountController, 'start']);
$router->post('/stock-counts/count', [$stockCountController, 'count']);
$router->post('/stock-counts/found-lot', [$stockCountController, 'foundLot']);
$router->post('/stock-counts/submit', [$stockCountController, 'submit']);
$router->post('/stock-counts/recount', [$stockCountController, 'recount']);
$router->post('/stock-counts/approve', [$stockCountController, 'approve']);
$router->post('/stock-counts/post', [$stockCountController, 'post']);
$router->post('/stock-counts/cancel', [$stockCountController, 'cancel']);
$router->get('/reports/stock-counts', [$stockCountController, 'report']);
$router->get('/reports/stock-counts.csv', [$stockCountController, 'reportCsv']);
$router->get($loginPath, [$authController, 'showLogin']);
$router->post($loginPath, [$authController, 'login']);
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
$router->get('/products/show', [$productController, 'show']);
$router->get('/products/edit', [$productController, 'edit']);
$router->post('/products/update', [$productController, 'update']);
$router->post('/products/activate', [$productController, 'activate']);
$router->post('/products/deactivate', [$productController, 'deactivate']);
$router->post('/products/reorder-point', [$productController, 'reorderPoint']);
$router->post('/products/units', [$productController, 'addUnit']);
$router->post('/products/units/update', [$productController, 'updateUnit']);
$router->get($purchaseOrdersPath, [$purchaseOrderController, 'index']);
$router->get('/purchase-orders/show', [$purchaseOrderController, 'show']);
$router->get('/purchase-orders/create', [$purchaseOrderController, 'create']);
$router->post($purchaseOrdersPath, [$purchaseOrderController, 'store']);
$router->post('/purchase-orders/order', [$purchaseOrderController, 'order']);
$router->post('/purchase-orders/receive', [$purchaseOrderController, 'receive']);
$router->post('/purchase-orders/cancel', [$purchaseOrderController, 'cancel']);
$router->get($salesOrdersPath, [$salesOrderController, 'index']);
$router->get('/sales-orders/show', [$salesOrderController, 'show']);
$router->get('/sales-orders/create', [$salesOrderController, 'create']);
$router->post($salesOrdersPath, [$salesOrderController, 'store']);
$router->post('/sales-orders/submit', [$salesOrderController, 'submit']);
$router->post('/sales-orders/approve', [$salesOrderController, 'approve']);
$router->post('/sales-orders/cancel', [$salesOrderController, 'cancel']);
$router->post('/sales-orders/issue', [$salesOrderController, 'issue']);
$router->post('/sales-orders/close-remaining', [$salesOrderController, 'closeRemaining']);
$router->get('/suppliers', [$supplierController, 'index']);
$router->get('/suppliers/create', [$supplierController, 'create']);
$router->post('/suppliers', [$supplierController, 'store']);
$router->post('/suppliers/import', [$supplierController, 'import']);
$router->get('/suppliers/edit', [$supplierController, 'edit']);
$router->post('/suppliers/update', [$supplierController, 'update']);
$router->post('/suppliers/activate', [$supplierController, 'activate']);
$router->post('/suppliers/deactivate', [$supplierController, 'deactivate']);
$router->get('/api/price-resolution', [$priceResolutionController, 'show']);
$router->get('/price-lists', [$priceListController, 'index']);
$router->get('/price-lists/show', [$priceListController, 'show']);
$router->post('/price-lists', [$priceListController, 'store']);
$router->post('/price-lists/update', [$priceListController, 'update']);
$router->post('/price-lists/items', [$priceListController, 'addItem']);
$router->post('/price-lists/items/update', [$priceListController, 'updateItem']);
$router->post('/price-lists/items/delete', [$priceListController, 'deleteItem']);
$router->post('/price-lists/assign', [$priceListController, 'assign']);
$router->get('/quotations', [$quotationController, 'index']);
$router->get('/quotations/create', [$quotationController, 'create']);
$router->get('/quotations/show', [$quotationController, 'show']);
$router->get('/quotations/print', [$quotationController, 'print']);
$router->post('/quotations', [$quotationController, 'store']);
$router->post('/quotations/send', [$quotationController, 'send']);
$router->post('/quotations/accept', [$quotationController, 'accept']);
$router->post('/quotations/reject', [$quotationController, 'reject']);
$router->post('/quotations/cancel', [$quotationController, 'cancel']);
$router->post('/quotations/convert', [$quotationController, 'convert']);
$router->get('/delivery-notes', [$deliveryNoteController, 'index']);
$router->get('/delivery-notes/show', [$deliveryNoteController, 'show']);
$router->get('/invoices', [$invoiceController, 'index']);
$router->get('/invoices/show', [$invoiceController, 'show']);
$router->get('/invoices/print', [$invoiceController, 'print']);
$router->post('/invoices', [$invoiceController, 'store']);
$router->post('/invoices/payments', [$invoiceController, 'payment']);
$router->post('/invoices/void', [$invoiceController, 'void']);
$router->get('/reports/receivables', [$invoiceController, 'receivables']);
$router->get('/reports/receivables.csv', [$invoiceController, 'receivablesCsv']);
$router->get('/reports/sales-by-customer', [$invoiceController, 'salesByCustomer']);
$router->get('/reports/sales-by-customer.csv', [$invoiceController, 'salesByCustomerCsv']);
// D-20 / ADR-026: inventory valuation FIFO per batch, period reconciliation, gross margin and losses (Admin only).
$router->get('/reports/valuation', [$valuationController, 'valuation']);
$router->get('/reports/valuation.csv', [$valuationController, 'valuationCsv']);
$router->get('/reports/valuation-reconciliation', [$valuationController, 'reconciliation']);
$router->get('/reports/valuation-reconciliation.csv', [$valuationController, 'reconciliationCsv']);
$router->get('/reports/gross-margin', [$valuationController, 'margin']);
$router->get('/reports/gross-margin.csv', [$valuationController, 'marginCsv']);
$router->get('/reports/inventory-losses', [$valuationController, 'losses']);
$router->get('/reports/inventory-losses.csv', [$valuationController, 'lossesCsv']);
$router->get('/customers', [$customerController, 'index']);
$router->get('/customers/create', [$customerController, 'create']);
$router->post('/customers', [$customerController, 'store']);
$router->post('/customers/import', [$customerController, 'import']);
$router->get('/customers/edit', [$customerController, 'edit']);
$router->post('/customers/update', [$customerController, 'update']);
$router->post('/customers/activate', [$customerController, 'activate']);
$router->post('/customers/deactivate', [$customerController, 'deactivate']);

try {
    // Expired protected POSTs are authentication failures, rather than misleading CSRF failures.
    if ($request->method() === 'POST' && $request->path() !== $loginPath) { $authGuard->requireAuth(); }
    $csrfInput = $request->post()['csrf_token'] ?? '';
    if ($request->method() === 'POST' && (!is_string($csrfInput) || !$session->isValidCsrfToken($csrfInput))) {
        throw new HttpException(403, 'Invalid CSRF token.');
    }
    $response = $router->dispatch($request);
} catch (HttpException $exception) {
    $unauthenticated = $exception->statusCode() === 401;
    if (str_starts_with($request->path(), '/api/')) {
        $response = ErrorResponder::api($unauthenticated ? 'Authentication required' : $exception->getMessage(), $exception->statusCode());
    } elseif ($unauthenticated && ($request->server()['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
        $response = new Response('', 302, ['Location' => $loginPath]);
    } else {
        $response = ErrorResponder::browser($exception, $config->bool('APP_DEBUG'));
    }
} catch (Throwable $throwable) {
    $status = method_exists($throwable, 'statusCode') && is_int($throwable->statusCode()) ? $throwable->statusCode() : 500;
    if ($status >= 500) {
        (new \App\Support\JsonFileLogger(dirname(__DIR__) . '/var/log/app.log'))->log('error', 'HTTP request failed.', [
            'exception' => $throwable::class,
            'method' => $request->method(),
            'path' => $request->path(),
            'request_id' => preg_match('/^[a-f0-9]{32}$/D', (string) ($request->server()['HTTP_X_REQUEST_ID'] ?? ''))
                ? $request->server()['HTTP_X_REQUEST_ID'] : null,
        ]);
    }
    if (str_starts_with($request->path(), '/api/')) {
        $response = ErrorResponder::api($status === 500 ? 'Unexpected server error.' : $throwable->getMessage(), $status);
    } else {
        $response = ErrorResponder::browser($throwable, $config->bool('APP_DEBUG'));
    }
}

$requestAuditRecorder->record($request, $response, $session->auth());
$session->close();
$response->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'Expires' => '0'])->send();
