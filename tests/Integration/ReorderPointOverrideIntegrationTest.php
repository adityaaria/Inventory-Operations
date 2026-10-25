<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlBusinessOperationRepository;
use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlPurchaseOrderRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Security\AuthContext;
use App\Service\MasterDataAuthorizationService;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
use App\Service\ReorderPointService;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;
use App\Support\RequestOrigin;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabase;

/** D-10 / ADR-016 against real MySQL: one `<` effective-threshold rule in dashboard, filter, replenishment and command. */
final class ReorderPointOverrideIntegrationTest extends TestCase
{
    // Since D-12 the single rule compares the available quantity (quantity − reserved; since D-17 also − expired lots);
    // it lives in LowStockSql.
    private static function effectiveLowPairs(): string
    {
        return 'SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id = ps.product_id JOIN warehouses w ON w.id = ps.warehouse_id
        WHERE ' . \App\Repository\MySql\LowStockSql::isLow() . ' AND p.is_active = 1 AND w.is_active = 1';
    }

    private PDO $pdo;
    private StockService $stock;
    private MySqlProductRepository $products;
    private AuthContext $admin;
    private ReorderPointService $service;
    /** @var list<int> */
    private array $warehouses;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->stock = new StockService(new MySqlStockRepository($this->pdo), new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
        $this->products = new MySqlProductRepository($this->pdo);
        $this->admin = new AuthContext(1, 'admin@example.test', 'Admin');
        $this->service = $this->service(new MySqlAuditLogRepository($this->pdo));
        $this->warehouses = array_map('intval', $this->pdo->query('SELECT id FROM warehouses WHERE is_active = 1 ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(3, $this->warehouses);
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    public function testDashboardCountsUseEffectiveThresholdAndStrictBoundary(): void
    {
        $queries = new MySqlOperationalQueryRepository($this->pdo);
        $before = $queries->adminDashboard()['low_stock_count'];
        self::assertSame((int) $this->pdo->query(self::effectiveLowPairs())->fetchColumn(), $before);
        [$w1, $w2, $w3] = $this->warehouses;
        $product = $this->product('ROP-DASH', 10, [$w1 => 10, $w2 => 20, $w3 => 20]);
        self::assertSame($before, $queries->adminDashboard()['low_stock_count'], 'quantity == product reorder point is not low.');

        $this->service->setOverride($this->admin, $product, $w2, 21);
        $this->service->setOverride($this->admin, $product, $w3, 20);
        self::assertSame($before + 1, $queries->adminDashboard()['low_stock_count'], 'Only 20 < 21 is low; 20 == 20 is not.');
        $rows = array_values(array_filter($queries->warehouseDashboard()['low_stock_rows'], static fn (array $r): bool => $r['sku'] === 'ROP-DASH'));
        self::assertCount(1, $rows);
        self::assertSame(21, (int) $rows[0]['reorder_point']);
        self::assertSame(10, (int) $rows[0]['product_reorder_point']);
        self::assertSame(21, (int) $rows[0]['reorder_point_override']);
        self::assertCount($before + 1, $queries->lowStockRows());

        $this->service->setOverride($this->admin, $product, $w1, 11);
        self::assertSame($before + 2, (int) $this->pdo->query(self::effectiveLowPairs())->fetchColumn());
        self::assertSame($before + 2, $queries->adminDashboard()['low_stock_count']);
    }

    public function testProductFilterIsLowInAnyActiveWarehouseNotTotal(): void
    {
        [$w1, $w2, $w3] = $this->warehouses;
        $product = $this->product('ROP-FILTER', 10, [$w1 => 2, $w2 => 100, $w3 => 100]);
        self::assertSame(['ROP-FILTER'], $this->skus('low', 'ROP-FILTER'), 'Total 202 > 10, but warehouse 1 is low.');
        self::assertSame([], $this->skus('normal', 'ROP-FILTER'));

        $this->service->setOverride($this->admin, $product, $w1, 2);
        self::assertSame([], $this->skus('low', 'ROP-FILTER'), 'quantity == override is not low.');
        self::assertSame(['ROP-FILTER'], $this->skus('normal', 'ROP-FILTER'));

        $this->service->setOverride($this->admin, $product, $w3, 150);
        self::assertSame(['ROP-FILTER'], $this->skus('low', 'ROP-FILTER'), 'An override above stock makes the product low.');

        $low = $this->products->search(ProductSearchCriteria::fromArray(['stock_status' => 'low']))->total();
        $normal = $this->products->search(ProductSearchCriteria::fromArray(['stock_status' => 'normal']))->total();
        $expectedLow = (int) $this->pdo->query('SELECT COUNT(DISTINCT p.id) FROM products p JOIN product_stocks ps ON ps.product_id = p.id JOIN warehouses w ON w.id = ps.warehouse_id
            WHERE p.is_active = 1 AND w.is_active = 1 AND CAST(ps.quantity AS SIGNED) - CAST(ps.reserved_quantity AS SIGNED) - (SELECT COALESCE(SUM(l.quantity), 0) FROM stock_lots l WHERE l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id AND l.expiry_date < ' . self::today() . ') < COALESCE(ps.reorder_point_override, p.reorder_point)')->fetchColumn();
        self::assertSame($expectedLow, $low);
        self::assertSame((int) $this->pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn(), $low + $normal);
        foreach ($this->products->stocksForProduct($product) as $stock) {
            self::assertSame($stock->warehouseId() === $w3, $stock->isLowStock(), 'Badge matches the SQL rule.');
        }
    }

    public function testReplenishmentUsesOverrideMinusStockMinusOpenSupply(): void
    {
        [$w1] = $this->warehouses;
        $product = $this->product('ROP-REPL', 10, [$w1 => 12]);
        $repository = new MySqlBusinessOperationRepository($this->pdo);
        self::assertSame(0, $repository->recommendationCount('ROP-REPL', $w1), 'Default 10 - 12 = 0: nothing to suggest.');

        $this->service->setOverride($this->admin, $product, $w1, 40);
        $rows = $repository->recommendations('ROP-REPL', 10, 0, $w1);
        self::assertCount(1, $rows);
        self::assertSame(40, (int) $rows[0]['reorder_point']);
        self::assertSame(28, (int) $rows[0]['suggested']);

        $orders = new PurchaseOrderService(new MySqlPurchaseOrderRepository($this->pdo), new \App\Repository\MySql\MySqlDocumentSequenceRepository($this->pdo), $this->products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], [$w1 => new Warehouse($w1, 'Warehouse', '', true)], $this->stock);
        $po = $orders->createDraft($this->admin, 1, $w1, [['product_id' => $product, 'quantity' => 5]]);
        $orders->markOrdered($this->admin, $po->id());
        $rows = $repository->recommendations('ROP-REPL', 10, 0, $w1);
        self::assertSame(5, (int) $rows[0]['inbound']);
        self::assertSame(23, (int) $rows[0]['suggested'], 'max(0, 40 - 12 - 5).');

        $this->service->setOverride($this->admin, $product, $w1, null);
        self::assertSame(0, $repository->recommendationCount('ROP-REPL', $w1));
    }

    public function testCommandListsOverridePairsWithEffectiveThreshold(): void
    {
        [$w1, $w2, $w3] = $this->warehouses;
        $product = $this->product('ROP-CMD', 5, [$w1 => 8, $w2 => 5, $w3 => 6]);
        $this->service->setOverride($this->admin, $product, $w1, 9);

        $process = proc_open([PHP_BINARY, 'scripts/check-low-stock.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);

        $lines = array_values(array_filter(explode("\n", $output), static fn (string $line): bool => str_starts_with($line, 'ROP-CMD |')));
        self::assertCount(1, $lines, 'Warehouse 2 has quantity == default 5 and warehouse 3 is above it, so only the override pair is listed.');
        self::assertStringContainsString('quantity=8 | reorder=9 (warehouse override)', $lines[0]);
        self::assertSame((int) $this->pdo->query(self::effectiveLowPairs())->fetchColumn(), substr_count($output, "\n"));
    }

    public function testOverrideChangeIsAuditedAtomicallyWithoutLedgerOrQuantityChange(): void
    {
        [$w1] = $this->warehouses;
        $product = $this->product('ROP-AUDIT', 10, [$w1 => 7]);
        $ledger = $this->scalar('SELECT COUNT(*) FROM stock_ledger');
        $this->service->setOverride($this->admin, $product, $w1, 3);

        $statement = $this->pdo->prepare("SELECT actor_id, entity_type, entity_id, status, metadata_json AS metadata FROM audit_logs WHERE action = 'products.reorder-point' AND entity_id = :id");
        $statement->execute(['id' => $product]);
        $audit = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $audit);
        self::assertSame(1, (int) $audit[0]['actor_id']);
        self::assertSame('products', $audit[0]['entity_type']);
        self::assertSame($product, (int) $audit[0]['entity_id']);
        self::assertSame('success', $audit[0]['status']);
        $expected = ['warehouse_id' => $w1, 'previous_override' => null, 'override' => 3, 'product_reorder_point' => 10, 'effective_reorder_point' => 3];
        $metadata = json_decode((string) $audit[0]['metadata'], true);
        self::assertIsArray($metadata);
        ksort($expected);
        ksort($metadata); // MySQL JSON stores object keys in its own order.
        self::assertSame($expected, $metadata);
        self::assertSame($ledger, $this->scalar('SELECT COUNT(*) FROM stock_ledger'), 'A threshold change is not a stock movement.');
        self::assertSame(7, (new MySqlStockRepository($this->pdo))->quantity($product, $w1));

        $failing = $this->service(new class implements AuditLogRepositoryInterface {
            public function append(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, RequestOrigin $origin, array $metadata = []): void
            {
                throw new RuntimeException('audit unavailable');
            }
        });
        try {
            $failing->setOverride($this->admin, $product, $w1, 50);
            self::fail('Change committed without its audit row.');
        } catch (RuntimeException) {
        }
        self::assertSame(3, $this->products->lockStock($product, $w1)?->reorderPointOverride(), 'Rolled back with the failed audit.');

        try {
            $this->service->setOverride(new AuthContext(2, 'sales1@example.test', 'Sales'), $product, $w1, 99);
            self::fail('Sales changed a reorder point.');
        } catch (HttpException $error) {
            self::assertSame(403, $error->statusCode());
        }
        self::assertSame(3, $this->products->lockStock($product, $w1)?->reorderPointOverride());
    }

    public function testMigrationAddsColumnIdempotentlyWithoutTouchingQuantities(): void
    {
        $quantities = $this->scalar('SELECT COALESCE(SUM(quantity), 0) FROM product_stocks');
        $this->pdo->exec('ALTER TABLE product_stocks DROP CHECK chk_product_stocks_reorder_override_non_negative');
        $this->pdo->exec('ALTER TABLE product_stocks DROP COLUMN reorder_point_override');
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'reorder-override-1'");

        foreach ([1, 2] as $run) {
            $process = proc_open([PHP_BINARY, 'scripts/migrate-reorder-point-override.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]);
            $error = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), 'Run ' . $run . ': ' . $error);
            self::assertSame('ready', json_decode($output, true)['status']);
        }
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'product_stocks' AND column_name = 'reorder_point_override' AND is_nullable = 'YES'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'product_stocks' AND constraint_name = 'chk_product_stocks_reorder_override_non_negative'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'reorder-override-1'"));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM product_stocks WHERE reorder_point_override IS NOT NULL'));
        self::assertSame($quantities, $this->scalar('SELECT COALESCE(SUM(quantity), 0) FROM product_stocks'));
    }

    private function service(AuditLogRepositoryInterface $audit): ReorderPointService
    {
        return new ReorderPointService($this->products, new MasterDataAuthorizationService(), $audit, new MySqlTransactionManager($this->pdo));
    }

    /** Creates a product (all pairs at zero) and receives the given quantity per warehouse through StockService. @param array<int,int> $quantities */
    private function product(string $sku, int $reorderPoint, array $quantities): int
    {
        $id = (new ProductService($this->products, new MasterDataAuthorizationService(), $this->stock))
            ->create($this->admin, new ProductInput($sku, 'Reorder test ' . $sku, 'pcs', 10.0, 20.0, $reorderPoint, 1))->id();
        foreach ($quantities as $warehouse => $quantity) {
            $this->stock->receive($warehouse, [new StockMovement($id, $quantity)], 1, 'TEST', $id);
        }

        return $id;
    }

    /** @return list<string> */
    private function skus(string $status, string $term): array
    {
        return array_map(static fn ($p): string => $p->sku(), $this->products->search(ProductSearchCriteria::fromArray(['stock_status' => $status, 'q' => $term]))->items());
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /** D-17 / ADR-023: available excludes expired lots (expiry before the business date), quoted for SQL. */
    private static function today(): string
    {
        return "'" . \App\Support\BusinessDate::today() . "'";
    }
}
