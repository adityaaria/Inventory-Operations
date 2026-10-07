<?php
declare(strict_types=1);

// Controlled interleaving evidence, not a production endpoint or parallel-thread test.
require getcwd() . '/vendor/autoload.php';

use App\Entity\{Customer, Supplier, User, Warehouse};
use App\Repository\Contract\StockRepositoryInterface;
use App\Repository\MySql\{MySqlProductRepository, MySqlSalesOrderRepository, MySqlPurchaseOrderRepository, MySqlStockRepository, MySqlStockLedgerRepository};
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\{AuthContext, AuthGuard, SessionManager};
use App\Service\{AuthService, SalesOrderService, PurchaseOrderService, StockService};

$database = getenv('DB_DATABASE') ?: '';
if (!str_starts_with($database, 'inventory_audit_')) {
    throw new RuntimeException('An isolated inventory_audit_* database is required.');
}
$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $database . ';charset=utf8mb4', getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stocks = new MySqlStockRepository($pdo);
$ledger = new MySqlStockLedgerRepository($pdo);
$products = new MySqlProductRepository($pdo);
$actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
$warehouse = new Warehouse(1, 'Main Warehouse', 'Jakarta', true);

final class InterleavingStockRepository implements StockRepositoryInterface
{
    public ?Closure $beforeBegin = null;
    public function __construct(private StockRepositoryInterface $inner) {}
    public function beginTransaction(): void {
        $hook = $this->beforeBegin;
        $this->beforeBegin = null;
        if ($hook !== null) { $hook(); }
        $this->inner->beginTransaction();
    }
    public function commit(): void { $this->inner->commit(); }
    public function rollBack(): void { $this->inner->rollBack(); }
    public function lockByProductWarehouse(int $productId, int $warehouseId): void { $this->inner->lockByProductWarehouse($productId, $warehouseId); }
    public function increment(int $productId, int $warehouseId, int $quantity): void { $this->inner->increment($productId, $warehouseId, $quantity); }
    public function decrement(int $productId, int $warehouseId, int $quantity): void { $this->inner->decrement($productId, $warehouseId, $quantity); }
    public function quantity(int $productId, int $warehouseId): int { return $this->inner->quantity($productId, $warehouseId); }
}

$interleaving = new InterleavingStockRepository($stocks);
$poRepo = new MySqlPurchaseOrderRepository($pdo);
$po = new PurchaseOrderService($poRepo, $products, [1 => new Supplier(1, 'Demo Supplier One', '', '', '', true)], [1 => $warehouse], new StockService($interleaving, $ledger));
$order = $po->createDraft($actor, 'PO-AUDIT-' . uniqid(), 1, 1, [['product_id' => 1, 'quantity' => 10, 'purchase_price' => 100.0]]);
$po->markOrdered($actor, $order->id());
$itemId = $order->items()[0]->id();
$interleaving->beforeBegin = fn () => $po->receive($actor, $order->id(), [$itemId => 5]);
$po->receive($actor, $order->id(), [$itemId => 5]);
$updated = $poRepo->findById($order->id());
$results['same_po_interleaving'] = ['ordered_quantity' => 10, 'received_quantity' => $updated->items()[0]->receivedQuantity(), 'status' => $updated->status()];


$soRepo = new MySqlSalesOrderRepository($pdo);
$so = new SalesOrderService($soRepo, $products, [1 => new Customer(1, 'Demo Customer One', '', '', '', true)], [1 => $warehouse], new StockService($interleaving, $ledger));
$order = $so->createDraft($actor, 'SO-AUDIT-' . uniqid(), 1, 1, [['product_id' => 1, 'quantity' => 1, 'selling_price' => 100.0]]);
$so->submit($actor, $order->id());
$so->approve($actor, $order->id());
$before = $stocks->quantity(1, 1);
$interleaving->beforeBegin = fn () => $so->issue($actor, $order->id());
$so->issue($actor, $order->id());
$results['same_so_interleaving'] = ['ordered_quantity' => 1, 'stock_decrease' => $before - $stocks->quantity(1, 1), 'ledger_rows' => count($ledger->forReference('SO', $order->id())), 'status' => $soRepo->findById($order->id())->status()];

$users = new InMemoryUserRepository([new User(1, 'Admin', 'admin@example.test', password_hash('password', PASSWORD_BCRYPT), User::ROLE_ADMIN, true)]);
$session = new SessionManager();
(new AuthService($users, $session))->login('admin@example.test', 'password');
$users->update(1, 'Admin', 'admin@example.test', User::ROLE_SALES);
$users->setActive(1, false);
$results['revoked_user_session'] = ['database_role' => $users->findById(1)->role(), 'database_active' => $users->findById(1)->isActive(), 'guard_role' => (new AuthGuard($session))->requireUserManagement()->role()];
$session->login($actor);
$guard = new AuthGuard($session);
$GLOBALS['csrf_token'] = $session->csrfToken();
$GLOBALS['workspace_role'] = User::ROLE_ADMIN;
$authorization = new App\Service\MasterDataAuthorizationService();
$sku = 'AUDIT-' . uniqid();
$controller = new App\Controller\ProductController(new App\Service\ProductService($products, $authorization), $products, new App\Repository\MySql\MySqlCategoryRepository($pdo), $guard);
$response = $controller->store(new App\Http\Request('POST', '/products', [], ['sku' => $sku, 'name' => 'Audit numeric validation', 'unit' => 'pcs', 'purchase_price' => 'abc', 'selling_price' => 'abc', 'reorder_point' => '2.9', 'category_id' => 1], []));
$statement = $pdo->prepare('SELECT purchase_price, selling_price, reorder_point FROM products WHERE sku = :sku');
$statement->execute(['sku' => $sku]);
$results['invalid_numeric_input'] = ['http_status' => $response->statusCode(), 'stored' => $statement->fetch(PDO::FETCH_ASSOC)];

$customerRepo = new App\Repository\MySql\MySqlCustomerRepository($pdo);
$customerController = new App\Controller\CustomerController(new App\Service\CustomerService($customerRepo, $authorization), $customerRepo, $guard);
$name = 'Audit Import ' . uniqid();
$response = $customerController->import(new App\Http\Request('POST', '/customers/import', [], ['csv_data' => "name,email,phone,address\n{$name},,,First valid row\n,,,Second invalid row\n"], []));
$statement = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE name = :name');
$statement->execute(['name' => $name]);
$results['partial_failed_import'] = ['http_status' => $response->statusCode(), 'first_row_persisted' => (int) $statement->fetchColumn()];
echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
