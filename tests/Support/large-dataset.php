<?php
declare(strict_types=1);
// Representative capacity fixture: all quantities move through the real business services.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'inventory_capacity_benchmark'
    || !preg_match('/^inventory-load-capacity-[a-z0-9-]+$/D', (string) getenv('CAPACITY_PROJECT'))) {
    fwrite(STDERR, "Refusing fixture generation outside a disposable capacity project/database.\n"); exit(2);
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
$config = require dirname(__DIR__, 2) . '/config/config.php';
$pdo = (new App\Support\DatabaseFactory($config))->create();
$productsCount = (int) ($argv[1] ?? '10000');
$ordersCount = (int) ($argv[2] ?? '10000');
if ($productsCount < 2 || $productsCount > 50000 || $ordersCount < 1 || $ordersCount > 50000) throw new RuntimeException('Fixture counts out of bounds.');
if ((int) $pdo->query("SELECT COUNT(*) FROM products WHERE sku LIKE 'CAPACITY-%'")->fetchColumn() !== 0) throw new RuntimeException('Capacity target already populated; create a fresh disposable project.');
$admin = (new App\Repository\MySql\MySqlUserRepository($pdo))->findByEmail('admin@example.test');
if ($admin === null || $admin->role() !== App\Entity\User::ROLE_ADMIN || !$admin->isActive()) throw new RuntimeException('Seeded Admin required.');
$actor = new App\Security\AuthContext($admin->id(), $admin->email(), $admin->role());
$products = new App\Repository\MySql\MySqlProductRepository($pdo);
$transactions = new App\Repository\MySql\MySqlTransactionManager($pdo);
$stock = new App\Service\StockService(new App\Repository\MySql\MySqlStockRepository($pdo), new App\Repository\MySql\MySqlStockLedgerRepository($pdo), new App\Repository\MySql\MySqlAuditLogRepository($pdo), new App\Repository\MySql\MySqlStockCatalogRepository($pdo), $transactions);
$service = new App\Service\ProductService($products, new App\Service\MasterDataAuthorizationService(), $stock);
$warehouses = [];
foreach ((new App\Repository\MySql\MySqlWarehouseRepository($pdo))->all() as $warehouse) $warehouses[$warehouse->id()] = $warehouse;
$suppliers = [];
foreach ((new App\Repository\MySql\MySqlSupplierRepository($pdo))->all() as $supplier) $suppliers[$supplier->id()] = $supplier;
$customers = [];
foreach ((new App\Repository\MySql\MySqlCustomerRepository($pdo))->all() as $customer) $customers[$customer->id()] = $customer;
$purchaseRepository = new App\Repository\MySql\MySqlPurchaseOrderRepository($pdo);
$po = new App\Service\PurchaseOrderService($purchaseRepository, $products, $suppliers, $warehouses, $stock);
$so = new App\Service\SalesOrderService(new App\Repository\MySql\MySqlSalesOrderRepository($pdo), $products, $customers, $warehouses, $stock);
$start = microtime(true);
$ids = [];
for ($i = 0; $i < $productsCount; $i++) {
    $product = $service->create($actor, new App\Support\ProductInput(sprintf('CAPACITY-%06d', $i), sprintf('Capacity Product %06d', $i), 'pcs', 10.0, 15.0, 10, 1));
    $ids[] = $product->id();
    if (($i + 1) % 1000 === 0) fwrite(STDERR, 'products=' . ($i + 1) . PHP_EOL);
}
for ($i = 0; $i < $ordersCount; $i++) {
    $first = $ids[($i * 2) % $productsCount];
    $second = $ids[($i * 2 + 1) % $productsCount];
    $order = $po->createDraft($actor, sprintf('CAPACITY-PO-%06d', $i), 1, 1, [
        ['product_id' => $first, 'quantity' => 10, 'purchase_price' => 10.0],
        ['product_id' => $second, 'quantity' => 10, 'purchase_price' => 10.0],
    ]);
    $po->markOrdered($actor, $order->id());
    $current = $purchaseRepository->findById($order->id());
    if ($current === null) throw new RuntimeException('Created PO missing.');
    $receipt = [];
    foreach ($current->items() as $item) $receipt[$item->id()] = 10;
    $po->receive($actor, $order->id(), $receipt);
    $sale = $so->createDraft($actor, sprintf('CAPACITY-SO-%06d', $i), 1, 1, [
        ['product_id' => $first, 'quantity' => 4, 'selling_price' => 15.0],
        ['product_id' => $second, 'quantity' => 4, 'selling_price' => 15.0],
    ]);
    $so->submit($actor, $sale->id()); $so->approve($actor, $sale->id()); $so->issue($actor, $sale->id());
    if (($i + 1) % 1000 === 0) fwrite(STDERR, 'PO+SO pairs=' . ($i + 1) . PHP_EOL);
}
$metrics = [
    'products_created' => $productsCount, 'purchase_orders_created' => $ordersCount, 'sales_orders_created' => $ordersCount,
    'elapsed_seconds' => round(microtime(true) - $start, 3), 'ledger_movements_expected' => $ordersCount * 4,
    'quantity_expected' => $ordersCount * 12,
];
foreach (['products', 'product_stocks', 'purchase_orders', 'sales_orders', 'stock_ledger', 'audit_logs'] as $table) {
    $metrics[$table . '_rows'] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
$metrics['capacity_quantity'] = (int) $pdo->query("SELECT SUM(ps.quantity) FROM product_stocks ps JOIN products p ON p.id=ps.product_id WHERE p.sku LIKE 'CAPACITY-%'")->fetchColumn();
$metrics['ledger_balance_mismatches'] = (int) $pdo->query("SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id=ps.product_id LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type='Receipt' THEN quantity WHEN movement_type='Issue' THEN -quantity ELSE quantity_delta END) AS balance FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id=ps.product_id AND l.warehouse_id=ps.warehouse_id WHERE p.sku LIKE 'CAPACITY-%' AND ps.quantity <> COALESCE(l.balance,0)")->fetchColumn();
$metrics['passed'] = $metrics['capacity_quantity'] === $metrics['quantity_expected'] && $metrics['ledger_balance_mismatches'] === 0;
echo json_encode($metrics, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
exit($metrics['passed'] ? 0 : 1);
