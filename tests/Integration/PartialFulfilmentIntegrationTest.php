<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Customer;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\ValidationException;
use App\Repository\Contract\OperationalQueryRepositoryInterface;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlDocumentSequenceRepository;
use App\Repository\MySql\MySqlDocumentTimelineRepository;
use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Repository\MySql\MySqlOperationRequestRepository;
use App\Repository\MySql\MySqlOrderExceptionRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Repository\MySql\MySqlWorkQueueRepository;
use App\Repository\MySql\PartialFulfilmentChecks;
use App\Repository\MySql\StockReservationReconciler;
use App\Security\AuthContext;
use App\Service\DocumentTimelineService;
use App\Service\MasterDataAuthorizationService;
use App\Service\OperationIdempotency;
use App\Service\OrderLinePricing;
use App\Service\ProductService;
use App\Service\SalesOrderService;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\OutstandingCriteria;
use App\Support\ProductInput;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** D-14 / ADR-020 against real MySQL: persistence, invariants, read models and migration of partial SO fulfilment. */
final class PartialFulfilmentIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MySqlStockRepository $balances;
    private StockService $stock;
    private MySqlSalesOrderRepository $orders;
    private SalesOrderService $sales;
    private AuthContext $admin;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->balances = new MySqlStockRepository($this->pdo);
        $this->stock = new StockService($this->balances, new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
        $this->orders = new MySqlSalesOrderRepository($this->pdo);
        $this->sales = new SalesOrderService(
            $this->orders,
            new MySqlDocumentSequenceRepository($this->pdo),
            new MySqlProductRepository($this->pdo),
            [1 => new Customer(1, 'Customer', '', '', '', true)],
            [1 => new Warehouse(1, 'Main', '', true)],
            $this->stock,
            new OperationIdempotency(new MySqlOperationRequestRepository($this->pdo)),
            new OrderLinePricing(),
            new MySqlOrderExceptionRepository($this->pdo),
        );
        $this->admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
    }

    public function testDemoSeedHasPartialShipmentsAndSatisfiesEveryInvariant(): void
    {
        self::assertSame(['issued_ledger_mismatches' => 0, 'fulfilment_status_mismatches' => 0, 'closure_mismatches' => 0], (new PartialFulfilmentChecks($this->pdo))->report());
        self::assertSame(0, (new StockReservationReconciler($this->pdo))->mismatches());
        self::assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM sales_orders WHERE status = 'PartiallyFulfilled'"));
        self::assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM sales_order_closures'));
        self::assertGreaterThan(10, $this->scalar("SELECT COUNT(*) FROM (SELECT reference_id FROM stock_ledger WHERE movement_type = 'Issue' AND reference_type = 'SO' GROUP BY reference_id HAVING COUNT(DISTINCT created_at) > 1) m"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'partial-fulfilment-1'"));
    }

    public function testPartialShipmentsPersistWithLedgerReservationAndAudit(): void
    {
        $product = $this->product('PF-FLOW', [1 => 20]);
        $order = $this->approved($product, 9);
        $item = $order->items()[0]->id();

        $this->sales->issue($this->admin, $order->id(), str_repeat('a', 32), [$item => 4]);
        $this->sales->issue($this->admin, $order->id(), str_repeat('a', 32), [$item => 4]); // replay
        $partial = $this->orders->findById($order->id()) ?? self::fail('order');
        self::assertSame([SalesOrder::STATUS_PARTIALLY_FULFILLED, 4, 5], [$partial->status(), $partial->items()[0]->issuedQuantity(), $partial->remainingUnits()]);
        self::assertSame([16, 5], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        try {
            $this->sales->issue($this->admin, $order->id(), str_repeat('a', 32), [$item => 5]);
            self::fail('Different payload with the same key must conflict.');
        } catch (\App\Exception\HttpException $exception) {
            self::assertSame(409, $exception->statusCode());
        }

        $this->sales->issue($this->admin, $order->id(), str_repeat('b', 32), [$item => 5]);
        $done = $this->orders->findById($order->id()) ?? self::fail('order');
        self::assertSame([SalesOrder::STATUS_FULFILLED, 9], [$done->status(), $done->items()[0]->issuedQuantity()]);
        self::assertSame([11, 0], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        $ledger = (new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $order->id());
        self::assertSame([4, 5], array_map(static fn ($entry): int => $entry->quantity(), $ledger));
        self::assertNotNull($ledger[0]->createdAt());
        $metadata = $this->pdo->query("SELECT metadata_json FROM audit_logs WHERE action = 'sales-orders.issue' AND entity_id = " . $order->id() . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $metadata);
        self::assertSame(['PartiallyFulfilled', 5], [json_decode((string) $metadata[0], true)['status_after'], json_decode((string) $metadata[0], true)['remaining_units']]);
        $this->assertInvariants();
    }

    public function testDatabaseRefusesIssuedAboveOrdered(): void
    {
        $product = $this->product('PF-CHECK', [1 => 5]);
        $order = $this->approved($product, 3);
        $this->expectException(PDOException::class);
        $this->pdo->exec('UPDATE sales_order_items SET issued_quantity = 4 WHERE id = ' . $order->items()[0]->id());
    }

    public function testCloseRemainingPersistsClosureReleasesReservationAndShowsInTimeline(): void
    {
        $product = $this->product('PF-CLOSE', [1 => 20]);
        $order = $this->approved($product, 10);
        $this->sales->issue($this->admin, $order->id(), null, [$order->items()[0]->id() => 6]);

        $this->sales->closeRemaining($this->admin, $order->id(), 'Customer refused the rest');

        self::assertSame(SalesOrder::STATUS_PARTIALLY_FULFILLED, $this->orders->findById($order->id())?->status());
        self::assertSame([14, 0], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        $closure = $this->sales->remainderClosure($order->id()) ?? self::fail('closure');
        self::assertSame(['Customer refused the rest', 4, 1], [$closure['reason'], (int) $closure['closed_units'], (int) $closure['closed_by']]);
        self::assertNotSame('', (string) $closure['closed_by_name']);
        try {
            $this->sales->issue($this->admin, $order->id(), null, [$order->items()[0]->id() => 1]);
            self::fail('Issue after closure must be refused.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('closed', $exception->getMessage());
        }
        $titles = array_column((new DocumentTimelineService(new MySqlDocumentTimelineRepository($this->pdo)))->forDocument($this->admin, 'SO', $order->id())['events'], 'title');
        self::assertContains('SO remaining fulfilment closed', $titles);
        self::assertContains('Goods issued (shipment)', $titles);
        $this->assertInvariants();
    }

    public function testCancelOfPartiallyFulfilledIsRefusedAndApprovedCancelReleases(): void
    {
        $product = $this->product('PF-CANCEL', [1 => 20]);
        $partial = $this->approved($product, 5);
        $this->sales->issue($this->admin, $partial->id(), null, [$partial->items()[0]->id() => 2]);
        $approved = $this->approved($product, 4);
        try {
            $this->sales->rejectOrCancel($this->admin, $partial->id());
            self::fail('Cancel must be refused.');
        } catch (ValidationException) {
        }
        $this->sales->rejectOrCancel($this->admin, $approved->id());
        self::assertSame([18, 3], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        self::assertSame(SalesOrder::STATUS_PARTIALLY_FULFILLED, $this->orders->findById($partial->id())?->status());
        $this->assertInvariants();
    }

    public function testReportsOutstandingWorkQueueAndDashboardTreatPartialAsOpenUntilClosed(): void
    {
        $product = $this->product('PF-READ', [1 => 30]);
        $open = $this->approved($product, 10);
        $closed = $this->approved($product, 8);
        $this->sales->issue($this->admin, $open->id(), null, [$open->items()[0]->id() => 3]);
        $this->sales->issue($this->admin, $closed->id(), null, [$closed->items()[0]->id() => 2]);
        $queries = new MySqlOperationalQueryRepository($this->pdo);
        $queue = new MySqlWorkQueueRepository($this->pdo);
        $issueQueueBefore = $queries->warehouseDashboard()['so_issue_queue'];

        $this->sales->closeRemaining($this->admin, $closed->id(), 'Customer refused the rest');

        $rows = [];
        foreach ($queries->iterateOutstandingRows(new OutstandingCriteria(OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_FULFILMENT, null, null, null, 'SO')) as $row) {
            $rows[(string) $row['OrderNumber']] = $row;
        }
        self::assertSame(['PartiallyFulfilled', 7], [$rows[$open->orderNumber()]['Status'], (int) $rows[$open->orderNumber()]['OutstandingQty']], 'Outstanding = ordered − issued.');
        self::assertNotNull($rows[$open->orderNumber()]['DaysSinceApproval']);
        self::assertArrayNotHasKey($closed->orderNumber(), $rows, 'A closed remainder is no longer outstanding.');
        self::assertSame(['Issue' => 1], $queue->counts('WarehouseStaff', 5, 'Issue', $open->orderNumber()));
        self::assertSame([], $queue->counts('WarehouseStaff', 5, 'Issue', $closed->orderNumber()));
        self::assertSame('Issue remaining goods', $queue->page('WarehouseStaff', 5, 'Issue', $open->orderNumber(), 10, 0)[0]['action']);
        self::assertSame($issueQueueBefore - 1, $queries->warehouseDashboard()['so_issue_queue']);
        self::assertGreaterThan(0, $queries->adminDashboard()['sales_orders_by_status']['PartiallyFulfilled'] ?? 0);
        $statuses = array_column(iterator_to_array($queries->iterateReportRows('orders', null, null, null), false), 'Status', 'OrderNumber');
        self::assertSame('PartiallyFulfilled', $statuses[$open->orderNumber()]);
        $this->assertInvariants();
    }

    public function testMigrationBackfillsFulfilledLinesAndIsRepeatable(): void
    {
        $product = $this->product('PF-MIG', [1 => 20]);
        $order = $this->approved($product, 6);
        $this->sales->issue($this->admin, $order->id());
        $updatedAt = (string) $this->pdo->query('SELECT updated_at FROM sales_order_items WHERE id = ' . $order->items()[0]->id())->fetchColumn();
        // Pre-migration schema: no closures table, no issued_quantity, no PartiallyFulfilled. Partial orders cannot exist
        // there, so the seed's partial history is turned back into Approved/Fulfilled orders consistently first.
        $this->pdo->exec('CREATE TABLE pf_closures_backup AS SELECT * FROM sales_order_closures');
        $this->pdo->exec('DROP TABLE sales_order_closures');
        $this->pdo->exec("UPDATE sales_orders SET status = 'Approved' WHERE status = 'PartiallyFulfilled'");
        $this->pdo->exec('ALTER TABLE sales_order_items DROP CHECK chk_sales_order_items_issued_lte_quantity');
        $this->pdo->exec('ALTER TABLE sales_order_items DROP CHECK chk_sales_order_items_issued_whole_units'); // D-16 CHECK, newer than this schema
        $this->pdo->exec('ALTER TABLE sales_order_items DROP COLUMN issued_quantity');
        $this->pdo->exec("ALTER TABLE sales_orders MODIFY COLUMN status ENUM('Draft', 'PendingApproval', 'Approved', 'Fulfilled', 'Cancelled') NOT NULL DEFAULT 'Draft'");
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'partial-fulfilment-1'");

        [$code, $output] = $this->runMigration();
        // The seed's formerly partial orders now have Issue ledger rows while their lines start at issued 0: reported.
        self::assertSame(2, $code, $output);
        self::assertSame('conflict', json_decode($output, true)['status']);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'partial-fulfilment-1'"));
        self::assertSame(6, $this->scalar('SELECT issued_quantity FROM sales_order_items WHERE id = ' . $order->items()[0]->id()), 'Fulfilled lines are backfilled.');
        self::assertSame($updatedAt, (string) $this->pdo->query('SELECT updated_at FROM sales_order_items WHERE id = ' . $order->items()[0]->id())->fetchColumn());

        // Owner repairs the reported orders from their Issue ledger rows (and restores the closures kept aside for this
        // simulation), then the migration completes and is repeatable.
        $this->pdo->exec('INSERT INTO sales_order_closures SELECT * FROM pf_closures_backup');
        $this->pdo->exec("UPDATE sales_order_items soi JOIN sales_orders so ON so.id = soi.sales_order_id
            JOIN (SELECT reference_id, product_id, SUM(quantity) q FROM stock_ledger WHERE movement_type = 'Issue' AND reference_type = 'SO' GROUP BY reference_id, product_id) l ON l.reference_id = so.id AND l.product_id = soi.product_id
            SET soi.issued_quantity = l.q, so.status = 'PartiallyFulfilled' WHERE so.status = 'Approved'");
        foreach ([1, 2] as $run) {
            [$code, $output] = $this->runMigration();
            self::assertSame(0, $code, 'Run ' . $run . ': ' . $output);
            $result = json_decode($output, true);
            self::assertSame(['ready', 0, 0, 0, 0], [$result['status'], $result['issued_ledger_mismatches'], $result['fulfilment_status_mismatches'], $result['closure_mismatches'], $result['reservation_mismatches']]);
        }
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'partial-fulfilment-1'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'sales_order_items' AND constraint_name = 'chk_sales_order_items_issued_lte_quantity'"));
        $this->assertInvariants();
    }

    private function assertInvariants(): void
    {
        self::assertSame(['issued_ledger_mismatches' => 0, 'fulfilment_status_mismatches' => 0, 'closure_mismatches' => 0], (new PartialFulfilmentChecks($this->pdo))->report());
        self::assertSame(0, (new StockReservationReconciler($this->pdo))->mismatches());
    }

    /** @param array<int, int> $quantities */
    private function product(string $sku, array $quantities): int
    {
        $id = (new ProductService(new MySqlProductRepository($this->pdo), new MasterDataAuthorizationService(), $this->stock))
            ->create($this->admin, new ProductInput($sku, 'Partial ' . $sku, 'pcs', 10.0, 20.0, 1, 1))->id();
        foreach ($quantities as $warehouse => $quantity) {
            $this->stock->receive($warehouse, [new StockMovement($id, $quantity)], 1, 'TEST', $id);
        }

        return $id;
    }

    private function approved(int $product, int $quantity): SalesOrder
    {
        $order = $this->sales->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => $quantity]]);
        $this->sales->submit($this->admin, $order->id());
        $this->sales->approve($this->admin, $order->id());

        return $this->orders->findById($order->id()) ?? self::fail('order');
    }

    /** @return array{0: int, 1: string} */
    private function runMigration(): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/migrate-partial-fulfilment.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $error];
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
