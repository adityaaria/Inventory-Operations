<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\{Customer, Supplier, User, Warehouse};
use App\Repository\MySql\{MySqlProductRepository, MySqlSalesOrderRepository, MySqlPurchaseOrderRepository, MySqlStockRepository, MySqlStockLedgerRepository, MySqlAuditLogRepository};
use App\Security\AuthContext;
use App\Service\{SalesOrderService, PurchaseOrderService, StockService};
use Tests\Support\TestDatabase;

try {
    $job = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $pdo = TestDatabase::connect(); // Refuses application databases before connecting.
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    $stock = new StockService(new MySqlStockRepository($pdo), new MySqlStockLedgerRepository($pdo), new MySqlAuditLogRepository($pdo), new \App\Repository\MySql\MySqlStockCatalogRepository($pdo), new \App\Repository\MySql\MySqlTransactionManager($pdo));
    $idempotency = new \App\Service\OperationIdempotency(new \App\Repository\MySql\MySqlOperationRequestRepository($pdo));
    $products = new MySqlProductRepository($pdo);
    $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
    $warehouses = [1 => new Warehouse(1, 'Main Warehouse', '', true)];
    file_put_contents($argv[2], 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($argv[3])) {
        if (microtime(true) > $deadline) throw new RuntimeException('Concurrency gate timed out.');
        usleep(1000);
    }
    file_put_contents($argv[2] . '.started', 'started');
    if (in_array($job['operation'], ['inventory_post', 'inventory_dispatch', 'inventory_receive'], true)) {
        $operations = new \App\Service\BusinessOperationService(new \App\Repository\MySql\MySqlBusinessOperationRepository($pdo), $stock, new MySqlAuditLogRepository($pdo));
        match ($job['operation']) {
            'inventory_post' => $operations->post($actor, $job['id']),
            'inventory_dispatch' => $operations->dispatch($actor, $job['id']), // D-15 / ADR-021
            default => $operations->receive($actor, $job['id'], $job['lines'] ?? null, $job['reason'] ?? null),
        };
    } elseif ($job['operation'] === 'count_post') {
        // D-18 / ADR-024: posting an approved stock count (delta applied to the current lot quantity).
        $counts = new \App\Service\StockCountService(new \App\Repository\MySql\MySqlStockCountRepository($pdo), $stock, new MySqlStockRepository($pdo), new MySqlAuditLogRepository($pdo), $products,
            new \App\Repository\MySql\MySqlUserRepository($pdo), new \App\Repository\MySql\MySqlWarehouseRepository($pdo), new \App\Repository\MySql\MySqlCategoryRepository($pdo),
            new \App\Service\ProductUnitCatalog($products, new \App\Repository\MySql\MySqlProductUnitRepository($pdo)));
        $counts->post($actor, $job['id']);
    } elseif (in_array($job['operation'], ['invoice_payment', 'invoice_create', 'quotation_convert'], true)) {
        // D-19 / ADR-025: invoice payments (invoice row lock), invoice creation (delivery note lock), quotation conversion
        // (quotation lock + UNIQUE quotation_id).
        $customers = new \App\Repository\MySql\MySqlCustomerRepository($pdo);
        $sequences = new \App\Repository\MySql\MySqlDocumentSequenceRepository($pdo);
        $transactions = new \App\Repository\MySql\MySqlTransactionManager($pdo);
        $orders = new MySqlSalesOrderRepository($pdo);
        $notes = new \App\Repository\MySql\MySqlDeliveryNoteRepository($pdo);
        if ($job['operation'] === 'quotation_convert') {
            $units = new \App\Service\ProductUnitCatalog($products, new \App\Repository\MySql\MySqlProductUnitRepository($pdo));
            $resolver = new \App\Service\PriceResolver(new \App\Repository\MySql\MySqlPriceListRepository($pdo), $products, $units);
            $byId = [];
            foreach ($customers->all() as $customer) { $byId[$customer->id()] = $customer; }
            $warehouseRepository = new \App\Repository\MySql\MySqlWarehouseRepository($pdo);
            $warehousesById = [];
            foreach ($warehouseRepository->all() as $warehouse) { $warehousesById[$warehouse->id()] = $warehouse; }
            $salesOrders = new SalesOrderService($orders, $sequences, $products, $byId, $warehousesById, $stock, null, new \App\Service\OrderLinePricing(), null, $units, $resolver);
            (new \App\Service\QuotationService(new \App\Repository\MySql\MySqlQuotationRepository($pdo), $sequences, $products, $customers, $warehouseRepository, $salesOrders, $transactions, $units, new \App\Service\OrderLinePricing(), $resolver, new MySqlAuditLogRepository($pdo)))
                ->convert($actor, (int) $job['id']);
        } else {
            $invoices = new \App\Service\InvoiceService(new \App\Repository\MySql\MySqlInvoiceRepository($pdo), $notes, $orders, $customers, $sequences, $transactions, new \App\Support\TaxSettings(), new MySqlAuditLogRepository($pdo));
            if ($job['operation'] === 'invoice_create') {
                $invoices->createFromDeliveryNote($actor, (int) $job['id']);
            } else {
                $invoices->recordPayment($actor, (int) $job['id'], ['paid_on' => $job['paid_on'], 'amount' => $job['amount'], 'method' => 'Transfer', 'reference' => 'parallel']);
            }
        }
    } elseif ($job['operation'] === 'catalog_product') {
        (new \App\Service\ProductService($products, new \App\Service\MasterDataAuthorizationService(), $stock))->create($actor, new \App\Support\ProductInput('PARALLEL-CATALOG', 'Parallel catalog', 'pcs', 10.0, 20.0, 1, 1));
    } elseif ($job['operation'] === 'catalog_warehouse') {
        (new \App\Service\WarehouseService(new \App\Repository\MySql\MySqlWarehouseRepository($pdo), new \App\Service\MasterDataAuthorizationService(), $stock))->create($actor, 'Parallel warehouse', 'Test');
    } elseif (in_array($job['operation'], ['approve', 'issue', 'close_remaining'], true)) {
        // The exceptions repository makes issue respect a closed remainder (D-14, ADR-020).
        // D-19 / ADR-025: every shipment creates its delivery note in the same transaction, as in the application.
        $notes = new \App\Service\DeliveryNoteService(new \App\Repository\MySql\MySqlDeliveryNoteRepository($pdo), new \App\Repository\MySql\MySqlDocumentSequenceRepository($pdo), new \App\Repository\MySql\MySqlCustomerRepository($pdo), new MySqlAuditLogRepository($pdo));
        $service = new SalesOrderService(new MySqlSalesOrderRepository($pdo), new \App\Repository\MySql\MySqlDocumentSequenceRepository($pdo), $products, [1 => new Customer(1, 'Customer', '', '', '', true)], $warehouses, $stock, $idempotency, new \App\Service\OrderLinePricing(), new \App\Repository\MySql\MySqlOrderExceptionRepository($pdo), null, null, $notes);
        match ($job['operation']) {
            'approve' => $service->approve($actor, $job['id']),
            'issue' => $service->issue($actor, $job['id'], $job['key'] ?? null, isset($job['quantities']) ? array_map('intval', $job['quantities']) : null),
            default => $service->closeRemaining($actor, $job['id'], $job['reason']),
        };
    } else {
        $service = new PurchaseOrderService(new MySqlPurchaseOrderRepository($pdo), new \App\Repository\MySql\MySqlDocumentSequenceRepository($pdo), $products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], $warehouses, $stock, $idempotency);
        $service->receive($actor, $job['id'], [$job['item_id'] => $job['quantity']], $job['key'] ?? null);
    }
    echo json_encode(['result' => 'committed']);
    exit(0);
} catch (InvalidArgumentException $exception) {
    echo json_encode(['result' => 'rejected', 'message' => $exception->getMessage()]);
    exit(2);
} catch (\App\Exception\HttpException $exception) {
    echo json_encode(['result'=>'rejected','status'=>$exception->statusCode()]);
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage());
    exit(3);
}
