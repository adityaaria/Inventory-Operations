<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\PurchaseOrderController;
use App\Controller\SalesOrderController;
use App\Entity\{Customer, Supplier, User, Warehouse};
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\MySql\{MySqlAuditLogRepository, MySqlDocumentSequenceRepository, MySqlProductRepository, MySqlPurchaseOrderRepository, MySqlSalesOrderRepository, MySqlStockCatalogRepository, MySqlStockLedgerRepository, MySqlStockRepository, MySqlTransactionManager};
use App\Security\{AuthContext, AuthGuard, SessionManager};
use App\Service\{PurchaseOrderService, SalesOrderService, StockService};
use App\Support\{OrderNumber, ProductInput};
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** D-11 / ADR-017: server-assigned order numbers against real MySQL. */
final class OrderNumberIntegrationTest extends TestCase
{
    private PDO $pdo;
    private StockService $stock;
    private MySqlProductRepository $products;
    private MySqlDocumentSequenceRepository $sequences;
    private AuthContext $admin;
    private int $product;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->stock = new StockService(new MySqlStockRepository($this->pdo), new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
        $this->products = new MySqlProductRepository($this->pdo);
        $this->sequences = new MySqlDocumentSequenceRepository($this->pdo);
        $this->admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $this->product = $this->products->create(new ProductInput('ORDNUM-SKU', 'Order number fixture', 'pcs', 10.0, 20.0, 1, 1), true)->id();
    }

    public function testSeedSequencesEqualTheHighestNumberPerPeriod(): void
    {
        self::assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM document_sequences'));
        self::assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM (SELECT 'PO' p, SUBSTRING(order_number, 4, 4) per, MAX(CAST(SUBSTRING(order_number, 9) AS UNSIGNED)) n FROM purchase_orders GROUP BY per
              UNION ALL SELECT 'SO', SUBSTRING(order_number, 4, 4), MAX(CAST(SUBSTRING(order_number, 9) AS UNSIGNED)) FROM sales_orders GROUP BY SUBSTRING(order_number, 4, 4)) m
             LEFT JOIN document_sequences s ON s.prefix = m.p AND s.period = m.per WHERE s.last_number IS NULL OR s.last_number <> m.n"
        ), 'Every seeded period has a counter equal to its highest number.');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM purchase_orders WHERE NOT REGEXP_LIKE(order_number, '^PO-[0-9]{4}-[0-9]{4,}$', 'c')"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM sales_orders WHERE NOT REGEXP_LIKE(order_number, '^SO-[0-9]{4}-[0-9]{4,}$', 'c')"));
    }

    public function testServiceContinuesAfterTheCurrentPeriodMaximum(): void
    {
        $period = $this->sequences->currentPeriod();
        self::assertSame(gmdate('ym'), $period, 'Database session clock and PHP both run in UTC in this deployment.');
        $poBase = $this->lastValue('PO', $period);
        $soBase = $this->lastValue('SO', $period);

        $po = $this->purchase()->createDraft($this->admin, 1, 1, [['product_id' => $this->product, 'quantity' => 2]]);
        $so = $this->sales()->createDraft(new AuthContext(2, 'sales1@example.test', User::ROLE_SALES), 1, 1, [['product_id' => $this->product, 'quantity' => 1]]);

        self::assertSame(OrderNumber::format('PO', $period, $poBase + 1), $po->orderNumber());
        self::assertSame(OrderNumber::format('SO', $period, $soBase + 1), $so->orderNumber());
        self::assertSame($poBase + 1, $this->lastValue('PO', $period));
        self::assertSame(gmdate('Y-m-d'), $po->orderDate(), 'YYMM and order_date come from the same clock.');
    }

    public function testTamperedOrderNumberFromTheClientIsIgnored(): void
    {
        $period = $this->sequences->currentPeriod();
        $poBase = $this->lastValue('PO', $period);
        $soBase = $this->lastValue('SO', $period);
        $warehouses = [1 => new Warehouse(1, 'Warehouse', '', true)];

        $purchaseOrders = new MySqlPurchaseOrderRepository($this->pdo);
        $poController = new PurchaseOrderController($this->purchase(), $purchaseOrders, $this->products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], $warehouses, new AuthGuard($this->session($this->admin)));
        $response = $poController->store(new Request('POST', '/purchase-orders', [], ['order_number' => 'PO-HACKED-0001', 'supplier_id' => '1', 'warehouse_id' => '1', 'product_id' => (string) $this->product, 'quantity' => '3'], []));
        self::assertSame(302, $response->statusCode());

        $salesOrders = new MySqlSalesOrderRepository($this->pdo);
        $sales = new AuthContext(2, 'sales1@example.test', User::ROLE_SALES);
        $soController = new SalesOrderController($this->sales(), $salesOrders, $this->products, [1 => new Customer(1, 'Customer', '', '', '', true)], $warehouses, new AuthGuard($this->session($sales)));
        // Even a value that would be the "next" number in another period is not honoured.
        $response = $soController->store(new Request('POST', '/sales-orders', [], ['order_number' => 'SO-9912-0001', 'customer_id' => '1', 'warehouse_id' => '1', 'product_id' => (string) $this->product, 'quantity' => '1'], []));
        self::assertSame(302, $response->statusCode());

        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM purchase_orders WHERE order_number = 'PO-HACKED-0001'"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM sales_orders WHERE order_number = 'SO-9912-0001'"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM document_sequences WHERE period = '9912'"));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM purchase_orders WHERE order_number = ?', [OrderNumber::format('PO', $period, $poBase + 1)]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM sales_orders WHERE order_number = ? AND created_by = 2', [OrderNumber::format('SO', $period, $soBase + 1)]));
    }

    public function testRolledBackClaimIsReusedSoNoGapIsLeft(): void
    {
        $period = $this->sequences->currentPeriod();
        $base = $this->lastValue('PO', $period);

        $this->pdo->beginTransaction();
        self::assertSame($base + 1, $this->sequences->next('PO', $period));
        self::assertSame($base + 2, $this->sequences->next('PO', $period));
        $this->pdo->rollBack();

        self::assertSame($base, $this->lastValue('PO', $period));
        $order = $this->purchase()->createDraft($this->admin, 1, 1, [['product_id' => $this->product, 'quantity' => 1]]);
        self::assertSame(OrderNumber::format('PO', $period, $base + 1), $order->orderNumber());
    }

    public function testClaimOutsideATransactionIsRefused(): void
    {
        $this->expectException(\App\Exception\PersistenceException::class);
        $this->sequences->next('PO', $this->sequences->currentPeriod());
    }

    public function testFailedDraftRollsBackItsClaimAndUniqueKeyStaysTheFinalGuard(): void
    {
        $period = $this->sequences->currentPeriod();
        $base = $this->lastValue('PO', $period);
        $orders = new MySqlPurchaseOrderRepository($this->pdo);
        // A number written outside the service (bypassing the sequence) occupies the next value.
        $orders->createDraft(OrderNumber::format('PO', $period, $base + 1), 1, 1, 1, [['product_id' => $this->product, 'quantity' => 1, 'purchase_price' => 10.0]]);
        $before = $this->scalar('SELECT COUNT(*) FROM purchase_orders');

        try {
            $this->purchase()->createDraft($this->admin, 1, 1, [['product_id' => $this->product, 'quantity' => 1]]);
            self::fail('The duplicate number must be refused by the unique key.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }
        self::assertSame($before, $this->scalar('SELECT COUNT(*) FROM purchase_orders'), 'No order or item row is left behind.');
        self::assertSame($base, $this->lastValue('PO', $period), 'The claim was rolled back with the failed draft.');
        self::assertFalse($this->pdo->inTransaction());

        // Re-running the idempotent migration raises the counter past the foreign number, and creation continues.
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261010-order-number-sequences.sql'));
        self::assertSame($base + 1, $this->lastValue('PO', $period));
        $order = $this->purchase()->createDraft($this->admin, 1, 1, [['product_id' => $this->product, 'quantity' => 1]]);
        self::assertSame(OrderNumber::format('PO', $period, $base + 2), $order->orderNumber());
    }

    public function testMigrationInitializesCountersFromExactPatternNumbersOnly(): void
    {
        $orders = new MySqlPurchaseOrderRepository($this->pdo);
        $salesOrders = new MySqlSalesOrderRepository($this->pdo);
        $poLine = [['product_id' => $this->product, 'quantity' => 1, 'purchase_price' => 10.0]];
        $soLine = [['product_id' => $this->product, 'quantity' => 1, 'selling_price' => 20.0]];
        $legacy = ['PO-9901-0007', 'PO-9901-0012', 'po-9901-0015', 'PO-9901-012', 'PO-99010000099', 'PO-20261009141927', 'PO-9902-00042', 'PO-9903-0005-X'];
        foreach ($legacy as $number) {
            $orders->createDraft($number, 1, 1, 1, $poLine);
        }
        foreach (['SO-9901-0003', 'SO-20261009144421', 'SO-9901-ABCD'] as $number) {
            $salesOrders->createDraft($number, 1, 1, 2, $soLine);
        }
        // Simulate a database from before this change: no counter table and no schema version row.
        $this->pdo->exec('DROP TABLE document_sequences');
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'order-number-1'");
        $expected = $this->pdo->query("SELECT CONCAT('PO-', SUBSTRING(order_number, 4, 4)), MAX(CAST(SUBSTRING(order_number, 9) AS UNSIGNED)) FROM purchase_orders WHERE order_number LIKE 'PO-26__-____' GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ([1, 2] as $run) {
            $output = $this->runMigration($run);
            self::assertSame('ready', $output['status']);
            self::assertFalse($output['destructive']);
        }

        self::assertSame(15, $this->lastValue('PO', '9901'), 'Case-insensitive match; 3-digit, 11-digit and suffixed numbers ignored.');
        self::assertSame(42, $this->lastValue('PO', '9902'), 'Five-digit numbers count by value.');
        self::assertSame(0, $this->lastValue('PO', '9903'));
        self::assertSame(3, $this->lastValue('SO', '9901'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM document_sequences WHERE period NOT REGEXP '^[0-9]{4}$' OR period = '2026'"));
        foreach ($expected as $key => $max) {
            [$prefix, $period] = explode('-', (string) $key);
            self::assertSame((int) $max, $this->lastValue($prefix, $period), 'Seed period ' . $key);
        }
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'order-number-1'"));
        // Legacy numbers are unchanged.
        foreach ($legacy as $number) {
            self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM purchase_orders WHERE BINARY order_number = ?', [$number]), $number);
        }

        // Counters are never lowered by a re-run.
        $this->pdo->exec("UPDATE document_sequences SET last_number = 100 WHERE prefix = 'PO' AND period = '9901'");
        $this->runMigration(3);
        self::assertSame(100, $this->lastValue('PO', '9901'));
        $this->pdo->beginTransaction();
        self::assertSame(101, $this->sequences->next('PO', '9901'));
        $this->pdo->rollBack();
    }

    public function testParallelProcessesGetUniqueConsecutiveNumbers(): void
    {
        $period = $this->sequences->currentPeriod();
        $poBase = $this->lastValue('PO', $period);
        $soBase = $this->lastValue('SO', $period);
        $jobs = [];
        foreach (['PO', 'SO', 'PO', 'SO', 'PO', 'SO'] as $prefix) {
            $jobs[] = ['prefix' => $prefix, 'count' => 8, 'product' => $this->product];
        }
        $directory = sys_get_temp_dir() . '/inventory-order-numbers-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        // Hold both counters (claims inside an open transaction) so every worker queues on the same rows; the
        // rollback then releases them, and the rolled-back values must be handed out again (no gap).
        $this->pdo->beginTransaction();
        $this->sequences->next('PO', $period);
        $this->sequences->next('SO', $period);
        try {
            foreach ($jobs as $index => $job) {
                file_put_contents($directory . '/' . $index . '.json', json_encode($job, JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/order-number-worker.php', $directory . '/' . $index . '.json', $directory . '/' . $index . '.ready', $directory . '/gate'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/' . $index . '.out', 'w'], 2 => ['file', $directory . '/' . $index . '.err', 'w']], $pipes, dirname(__DIR__, 2));
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = $process;
            }
            $this->awaitFiles($directory, count($jobs), '.ready');
            touch($directory . '/gate');
            $this->awaitFiles($directory, count($jobs), '.ready.started');
            usleep(200000);
            $this->pdo->rollBack();
            $numbers = ['PO' => [], 'SO' => []];
            foreach ($processes as $index => $process) {
                self::assertSame(0, proc_close($process), (string) file_get_contents($directory . '/' . $index . '.err'));
                $numbers[$jobs[$index]['prefix']] = array_merge($numbers[$jobs[$index]['prefix']], json_decode((string) file_get_contents($directory . '/' . $index . '.out'), true, 512, JSON_THROW_ON_ERROR));
            }
            $processes = [];
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }

        foreach (['PO' => $poBase, 'SO' => $soBase] as $prefix => $base) {
            $assigned = $numbers[$prefix];
            sort($assigned, SORT_STRING);
            $expected = array_map(static fn (int $value): string => OrderNumber::format($prefix, $period, $value), range($base + 1, $base + 24));
            sort($expected, SORT_STRING);
            self::assertSame($expected, $assigned, $prefix . ': 24 concurrent drafts get unique, consecutive numbers.');
            self::assertSame($base + 24, $this->lastValue($prefix, $period));
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) - COUNT(DISTINCT order_number) FROM purchase_orders'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) - COUNT(DISTINCT order_number) FROM sales_orders'));
    }

    private function purchase(): PurchaseOrderService
    {
        return new PurchaseOrderService(new MySqlPurchaseOrderRepository($this->pdo), $this->sequences, $this->products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Warehouse', '', true)], $this->stock);
    }

    private function sales(): SalesOrderService
    {
        return new SalesOrderService(new MySqlSalesOrderRepository($this->pdo), $this->sequences, $this->products, [1 => new Customer(1, 'Customer', '', '', '', true)], [1 => new Warehouse(1, 'Warehouse', '', true)], $this->stock);
    }

    private function session(AuthContext $actor): SessionManager
    {
        $session = new SessionManager();
        $session->login($actor);

        return $session;
    }

    /** @return array<string, mixed> */
    private function runMigration(int $run): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/migrate-order-number-sequences.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), 'Run ' . $run . ': ' . $error);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    private function awaitFiles(string $directory, int $count, string $suffix): void
    {
        $deadline = microtime(true) + 20;
        for ($index = 0; $index < $count; $index++) {
            while (!is_file($directory . '/' . $index . $suffix)) {
                if (microtime(true) > $deadline) self::fail('Workers did not reach the concurrency barrier.');
                usleep(1000);
            }
        }
    }

    private function lastValue(string $prefix, string $period): int
    {
        return $this->scalar('SELECT COALESCE(MAX(last_number), 0) FROM document_sequences WHERE prefix = ? AND period = ?', [$prefix, $period]);
    }

    /** @param list<int|string> $params */
    private function scalar(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
