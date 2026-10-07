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
    if ($job['operation'] === 'inventory_post') {
        (new \App\Service\BusinessOperationService(new \App\Repository\MySql\MySqlBusinessOperationRepository($pdo), $stock, new MySqlAuditLogRepository($pdo)))->post($actor, $job['id']);
    } elseif ($job['operation'] === 'catalog_product') {
        (new \App\Service\ProductService($products, new \App\Service\MasterDataAuthorizationService(), $stock))->create($actor, new \App\Support\ProductInput('PARALLEL-CATALOG', 'Parallel catalog', 'pcs', 10.0, 20.0, 1, 1));
    } elseif ($job['operation'] === 'catalog_warehouse') {
        (new \App\Service\WarehouseService(new \App\Repository\MySql\MySqlWarehouseRepository($pdo), new \App\Service\MasterDataAuthorizationService(), $stock))->create($actor, 'Parallel warehouse', 'Test');
    } elseif ($job['operation'] === 'issue') {
        $service = new SalesOrderService(new MySqlSalesOrderRepository($pdo), $products, [1 => new Customer(1, 'Customer', '', '', '', true)], $warehouses, $stock, $idempotency);
        $service->issue($actor, $job['id'], $job['key'] ?? null);
    } else {
        $service = new PurchaseOrderService(new MySqlPurchaseOrderRepository($pdo), $products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], $warehouses, $stock, $idempotency);
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
