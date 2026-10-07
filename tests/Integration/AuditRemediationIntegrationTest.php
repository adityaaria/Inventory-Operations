<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Entity\{Customer, Supplier, User, Warehouse};
use App\Repository\Contract\{StockRepositoryInterface, AuditLogRepositoryInterface};
use App\Repository\MySql\{MySqlProductRepository, MySqlSalesOrderRepository, MySqlPurchaseOrderRepository, MySqlStockRepository, MySqlStockLedgerRepository, MySqlAuditLogRepository};
use App\Security\AuthContext;
use App\Service\{SalesOrderService, PurchaseOrderService, StockService};
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class AuditRemediationIntegrationTest extends TestCase
{
    private PDO $pdo;
    protected function setUp(): void { TestDatabase::reset(); $this->pdo = TestDatabase::connect(); }

    public function testInterleavedDuplicateIssueCommitsOnlyOneMovement(): void
    {
        [$service, $repo, $stock, $ledger, $actor] = $this->sales();
        $order = $service->createDraft($actor, 'SO-RACE', 1, 1, [['product_id' => 1, 'quantity' => 1, 'selling_price' => 100.0]]);
        $service->submit($actor, $order->id()); $service->approve($actor, $order->id());
        $before = $stock->quantity(1, 1);
        $stock->beforeBegin = fn () => $service->issue($actor, $order->id());
        try { $service->issue($actor, $order->id()); self::fail('Repeated fulfillment must fail.'); }
        catch (InvalidArgumentException $exception) { self::assertStringContainsString('Approved', $exception->getMessage()); }
        self::assertSame(1, $before - $stock->quantity(1, 1));
        self::assertCount(1, $ledger->forReference('SO', $order->id()));
        self::assertSame('Fulfilled', $repo->findById($order->id())->status());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='sales-orders.issue' AND entity_id=" . $order->id())->fetchColumn());
    }

    public function testInterleavedFinalReceiptsReadCurrentRemainingAndFinishOrder(): void
    {
        $stock = new InterleavingStock(new MySqlStockRepository($this->pdo));
        $repo = new MySqlPurchaseOrderRepository($this->pdo);
        $ledger = new MySqlStockLedgerRepository($this->pdo);
        $service = new PurchaseOrderService($repo, new MySqlProductRepository($this->pdo), [1 => new Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Warehouse', '', true)], new StockService($stock, $ledger, new MySqlAuditLogRepository($this->pdo)));
        $actor = new AuthContext(1, 'admin@test', User::ROLE_ADMIN);
        $order = $service->createDraft($actor, 'PO-RACE', 1, 1, [['product_id' => 1, 'quantity' => 10, 'purchase_price' => 100.0]]);
        $service->markOrdered($actor, $order->id()); $item = $order->items()[0]->id();
        $stock->beforeBegin = fn () => $service->receive($actor, $order->id(), [$item => 5]);
        $service->receive($actor, $order->id(), [$item => 5]);
        self::assertSame('Received', $repo->findById($order->id())->status());
        self::assertSame(10, $repo->findById($order->id())->items()[0]->receivedQuantity());
        self::assertCount(2, $ledger->forReference('PO', $order->id()));
    }

    public function testCancellationBeforeIssuePreventsStockMutation(): void
    {
        [$service, $repo, $stock, $ledger, $actor] = $this->sales();
        $order = $service->createDraft($actor, 'SO-CANCEL-RACE', 1, 1, [['product_id' => 1, 'quantity' => 1, 'selling_price' => 100.0]]);
        $service->submit($actor, $order->id()); $service->approve($actor, $order->id());
        $before = $stock->quantity(1, 1);
        $stock->beforeBegin = fn () => $service->rejectOrCancel($actor, $order->id());
        try { $service->issue($actor, $order->id()); self::fail('Cancelled issue must fail.'); }
        catch (InvalidArgumentException) {}
        self::assertSame($before, $stock->quantity(1, 1));
        self::assertSame('Cancelled', $repo->findById($order->id())->status());
        self::assertCount(0, $ledger->forReference('SO', $order->id()));
    }

    public function testAuditFailureRollsBackOrderStockAndLedger(): void
    {
        $audit = new class implements AuditLogRepositoryInterface {
            public function append(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, string $ipAddress, string $userAgent, array $metadata = []): void { throw new \RuntimeException('Forced audit failure'); }
        };
        [$service, $repo, $stock, $ledger, $actor] = $this->sales($audit);
        $order = $service->createDraft($actor, 'SO-AUDIT-ROLLBACK', 1, 1, [['product_id' => 1, 'quantity' => 1, 'selling_price' => 100.0]]);
        $service->submit($actor, $order->id()); $service->approve($actor, $order->id()); $before = $stock->quantity(1, 1);
        try { $service->issue($actor, $order->id()); self::fail('Audit failure must abort transaction.'); }
        catch (\RuntimeException $exception) { self::assertSame('Forced audit failure', $exception->getMessage()); }
        self::assertSame($before, $stock->quantity(1, 1)); self::assertCount(0, $ledger->forReference('SO', $order->id()));
        self::assertSame('Approved', $repo->findById($order->id())->status());
    }

    public static function imports(): array
    {
        return [
            ['Customer', "name,email,phone,address\nAudit Valid,,,Address\n,,,Invalid\n"],
            ['Supplier', "name,email,phone,address\nAudit Valid,,,Address\n,,,Invalid\n"],
            ['Warehouse', "name,location\nAudit Valid,Location\n,Invalid\n"],
            ['Category', "name,description\nAudit Valid,Description\n,Invalid\n"],
            ['User', "name,email,password,role\nAudit Valid,audit@test.test,password123,Sales\nInvalid,bad,password123,Sales\n"],
            ['Product', "sku,name,unit,purchase_price,selling_price,reorder_point,category_id\nAUDIT-VALID,Audit Valid,pcs,0,1,0,1\n,Invalid,pcs,0,1,0,1\n"],
        ];
    }

    #[DataProvider('imports')]
    public function testFailedImportIsAtomicForEveryModule(string $kind, string $csv): void
    {
        $repositoryClass = 'App\\Repository\\MySql\\MySql' . $kind . 'Repository';
        $serviceClass = 'App\\Service\\' . $kind . 'Service';
        $controllerClass = 'App\\Controller\\' . $kind . 'Controller';
        $repository = new $repositoryClass($this->pdo);
        $service = $kind === 'User' ? new $serviceClass($repository) : new $serviceClass($repository, new \App\Service\MasterDataAuthorizationService());
        $session = new \App\Security\SessionManager(); $session->login(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));
        $guard = new \App\Security\AuthGuard($session, new \App\Repository\MySql\MySqlUserRepository($this->pdo));
        $imports = new \App\Service\CsvImportService(new \App\Repository\MySql\MySqlTransactionManager($this->pdo));
        $controller = $kind === 'Product' ? new $controllerClass($service, $repository, new \App\Repository\MySql\MySqlCategoryRepository($this->pdo), $guard, $imports) : new $controllerClass($service, $repository, $guard, $imports);
        $response = $controller->import(new \App\Http\Request('POST', '/', [], ['csv_data' => $csv], []));
        self::assertSame(422, $response->statusCode()); self::assertStringContainsString('No rows were imported.', $response->body());
        $table = match ($kind) { 'Category' => 'categories', default => strtolower($kind) . 's' };
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE name='Audit Valid'")->fetchColumn());
    }

    public function testExpectedDatabaseDuplicateReturnsSafeValidationError(): void
    {
        $repo = new MySqlProductRepository($this->pdo);
        $this->expectException(\App\Exception\ValidationException::class);
        $this->expectExceptionMessage('A record with that unique value already exists.');
        $repo->create(new \App\Support\ProductInput('SKU-DEMO-001', 'Duplicate', 'pcs', 0, 0, 0, 1), true);
    }

    private function sales(?AuditLogRepositoryInterface $audit = null): array
    {
        $stock = new InterleavingStock(new MySqlStockRepository($this->pdo)); $ledger = new MySqlStockLedgerRepository($this->pdo); $repo = new MySqlSalesOrderRepository($this->pdo);
        return [new SalesOrderService($repo, new MySqlProductRepository($this->pdo), [1 => new Customer(1, 'Customer', '', '', '', true)], [1 => new Warehouse(1, 'Warehouse', '', true)], new StockService($stock, $ledger, $audit ?? new MySqlAuditLogRepository($this->pdo))), $repo, $stock, $ledger, new AuthContext(1, 'admin@test', User::ROLE_ADMIN)];
    }
}

final class InterleavingStock implements StockRepositoryInterface
{
    public ?\Closure $beforeBegin = null;
    public function __construct(private StockRepositoryInterface $inner) {}
    public function beginTransaction(): void { $hook = $this->beforeBegin; $this->beforeBegin = null; if ($hook !== null) $hook(); $this->inner->beginTransaction(); }
    public function commit(): void { $this->inner->commit(); }
    public function rollBack(): void { $this->inner->rollBack(); }
    public function lockByProductWarehouse(int $productId, int $warehouseId): void { $this->inner->lockByProductWarehouse($productId, $warehouseId); }
    public function increment(int $productId, int $warehouseId, int $quantity): void { $this->inner->increment($productId, $warehouseId, $quantity); }
    public function decrement(int $productId, int $warehouseId, int $quantity): void { $this->inner->decrement($productId, $warehouseId, $quantity); }
    public function quantity(int $productId, int $warehouseId): int { return $this->inner->quantity($productId, $warehouseId); }
}
