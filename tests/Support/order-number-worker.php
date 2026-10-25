<?php

declare(strict_types=1);

// Independent process for OrderNumberIntegrationTest: waits at a file gate, then creates `count` PO or SO drafts through
// the real services (own PDO connection, own transactions) and prints the assigned order numbers as JSON.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\{Customer, Supplier, User, Warehouse};
use App\Repository\MySql\{MySqlAuditLogRepository, MySqlDocumentSequenceRepository, MySqlProductRepository, MySqlPurchaseOrderRepository, MySqlSalesOrderRepository, MySqlStockCatalogRepository, MySqlStockLedgerRepository, MySqlStockRepository, MySqlTransactionManager};
use App\Security\AuthContext;
use App\Service\{PurchaseOrderService, SalesOrderService, StockService};
use Tests\Support\TestDatabase;

try {
    [, $jobFile, $readyFile, $gateFile] = $argv;
    $job = json_decode((string) file_get_contents($jobFile), true, 512, JSON_THROW_ON_ERROR);
    $pdo = TestDatabase::connect(); // Refuses application databases before connecting.
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=20');
    $stock = new StockService(new MySqlStockRepository($pdo), new MySqlStockLedgerRepository($pdo), new MySqlAuditLogRepository($pdo), new MySqlStockCatalogRepository($pdo), new MySqlTransactionManager($pdo));
    $sequences = new MySqlDocumentSequenceRepository($pdo);
    $products = new MySqlProductRepository($pdo);
    $warehouses = [1 => new Warehouse(1, 'Warehouse', '', true)];
    $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
    $service = $job['prefix'] === 'PO'
        ? new PurchaseOrderService(new MySqlPurchaseOrderRepository($pdo), $sequences, $products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], $warehouses, $stock)
        : new SalesOrderService(new MySqlSalesOrderRepository($pdo), $sequences, $products, [1 => new Customer(1, 'Customer', '', '', '', true)], $warehouses, $stock);
    file_put_contents($readyFile, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($gateFile)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Concurrency gate timed out.');
        usleep(1000);
    }
    file_put_contents($readyFile . '.started', 'started');
    $numbers = [];
    for ($i = 0; $i < (int) $job['count']; $i++) {
        $numbers[] = $service->createDraft($admin, 1, 1, [['product_id' => (int) $job['product'], 'quantity' => 1 + $i]])->orderNumber();
    }
    echo json_encode($numbers, JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage());
    exit(3);
}
