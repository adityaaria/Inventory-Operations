<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\Contract\OperationalQueryRepositoryInterface;
use App\Repository\MySql\MySqlBusinessOperationRepository;
use App\Repository\MySql\MySqlDocumentTimelineRepository;
use App\Repository\MySql\MySqlOperationalHealthRepository;
use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Support\OutstandingCriteria;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** Covers dashboard/availability queries, stock-operation listing, document links and health rollback against MySQL. */
final class MySqlOperationalQueryCoverageIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connect();
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    public function testAdminDashboardMatchesDirectAggregates(): void
    {
        $dashboard = (new MySqlOperationalQueryRepository($this->pdo))->adminDashboard();

        $value = (float) $this->pdo->query('SELECT SUM(ps.quantity * p.purchase_price) FROM product_stocks ps JOIN products p ON p.id = ps.product_id')->fetchColumn();
        self::assertGreaterThan(0, $value);
        self::assertEqualsWithDelta($value, $dashboard['inventory_value'], 0.001);
        $low = (int) $this->pdo->query('SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id = ps.product_id JOIN warehouses w ON w.id = ps.warehouse_id WHERE ps.quantity < p.reorder_point AND p.is_active = 1 AND w.is_active = 1')->fetchColumn();
        self::assertSame($low, $dashboard['low_stock_count']);
        self::assertSame($this->statusCounts('purchase_orders'), $this->sorted($dashboard['purchase_orders_by_status']));
        self::assertSame($this->statusCounts('sales_orders'), $this->sorted($dashboard['sales_orders_by_status']));
        self::assertSame((int) $this->pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn(), array_sum($dashboard['purchase_orders_by_status']));
    }

    public function testSalesDashboardIsScopedToCreator(): void
    {
        $creator = (int) $this->pdo->query('SELECT created_by FROM sales_orders ORDER BY id LIMIT 1')->fetchColumn();
        $dashboard = (new MySqlOperationalQueryRepository($this->pdo))->salesDashboard($creator);

        self::assertSame($creator, $dashboard['sales_user_id']);
        $statement = $this->pdo->prepare('SELECT status, COUNT(*) AS total FROM sales_orders WHERE created_by = :creator GROUP BY status ORDER BY status');
        $statement->execute(['creator' => $creator]);
        $expected = [];
        foreach ($statement->fetchAll() as $row) {
            $expected[(string) $row['status']] = (int) $row['total'];
        }
        self::assertSame($this->sorted($expected), $this->sorted($dashboard['sales_orders_by_status']));
        $statement = $this->pdo->prepare('SELECT SUM(i.quantity * i.selling_price) FROM sales_order_items i JOIN sales_orders so ON so.id = i.sales_order_id WHERE so.created_by = :creator');
        $statement->execute(['creator' => $creator]);
        self::assertEqualsWithDelta((float) $statement->fetchColumn(), $dashboard['sales_order_value'], 0.001);

        $empty = (new MySqlOperationalQueryRepository($this->pdo))->salesDashboard(999999);
        self::assertSame([], $empty['sales_orders_by_status']);
        self::assertSame(0.0, $empty['sales_order_value']);
    }

    public function testWarehouseDashboardQueuesAndLowStockRows(): void
    {
        $dashboard = (new MySqlOperationalQueryRepository($this->pdo))->warehouseDashboard();

        self::assertSame((int) $this->pdo->query("SELECT COUNT(*) FROM purchase_orders po LEFT JOIN purchase_order_closures c ON c.purchase_order_id = po.id WHERE po.status IN ('Ordered','PartiallyReceived') AND c.purchase_order_id IS NULL")->fetchColumn(), $dashboard['po_receipt_queue']);
        self::assertSame((int) $this->pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status = 'Approved'")->fetchColumn(), $dashboard['so_issue_queue']);
        $rows = $dashboard['low_stock_rows'];
        $expected = (int) $this->pdo->query('SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id = ps.product_id JOIN warehouses w ON w.id = ps.warehouse_id WHERE ps.quantity < p.reorder_point AND p.is_active = 1 AND w.is_active = 1')->fetchColumn();
        self::assertGreaterThan(0, $expected);
        self::assertCount($expected, $rows);
        foreach ($rows as $row) {
            self::assertLessThan((int) $row['reorder_point'], (int) $row['quantity']);
        }
        $keys = array_map(static fn (array $r): string => $r['sku'] . '|' . $r['warehouse_name'], $rows);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $keys);
    }

    public function testProductAvailabilitySumsWarehousesAndHidesUnknownOrInactiveSku(): void
    {
        $repository = new MySqlOperationalQueryRepository($this->pdo);
        $availability = $repository->productAvailability('BIS-0001');
        self::assertNotNull($availability);
        self::assertSame('BIS-0001', $availability['sku']);
        $rows = $this->pdo->query("SELECT w.name, ps.quantity FROM product_stocks ps JOIN products p ON p.id = ps.product_id JOIN warehouses w ON w.id = ps.warehouse_id WHERE p.sku = 'BIS-0001' ORDER BY w.name")->fetchAll();
        self::assertSame(array_sum(array_map(static fn (array $r): int => (int) $r['quantity'], $rows)), $availability['total_quantity']);
        self::assertSame(array_column($rows, 'name'), array_column($availability['warehouses'], 'warehouse'));

        self::assertNull($repository->productAvailability('NO-SUCH-SKU'));
        $this->pdo->exec("UPDATE products SET is_active = 0 WHERE sku = 'BIS-0001'");
        self::assertNull($repository->productAvailability('BIS-0001'));
    }

    public function testUnknownOutstandingScopeIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown outstanding scope.');
        (new MySqlOperationalQueryRepository($this->pdo))->outstandingSummary(new OutstandingCriteria('everything', null, null, null));
    }

    public function testOutstandingSummaryStillAcceptsKnownScope(): void
    {
        $summary = (new MySqlOperationalQueryRepository($this->pdo))->outstandingSummary(new OutstandingCriteria(OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_ALL, null, null, null));
        self::assertSame($summary['total'], array_sum($summary['buckets']));
    }

    public function testStockOperationListFiltersByKindStatusAndEscapedReason(): void
    {
        $repository = new MySqlBusinessOperationRepository($this->pdo);
        $adjust = $repository->create(['kind' => 'Adjustment', 'warehouse_id' => 1, 'destination_id' => null, 'reason' => 'Cycle count 100% verified', 'created_by' => 1, 'condition_confirmed' => 0], [['product_id' => 1, 'quantity' => 4, 'baseline' => 10]]);
        $transfer = $repository->create(['kind' => 'Transfer', 'warehouse_id' => 1, 'destination_id' => 2, 'reason' => 'Cycle count 1000 rebalance', 'created_by' => 1, 'condition_confirmed' => 0], [['product_id' => 2, 'quantity' => 3]]);
        $repository->create(['kind' => 'Adjustment', 'warehouse_id' => 2, 'destination_id' => null, 'reason' => 'Damaged_goods', 'created_by' => 1, 'condition_confirmed' => 0], [['product_id' => 3, 'quantity' => 1]]);
        $repository->decide($transfer, 'Approved', 2, 'ok');

        self::assertSame(3, $repository->count([]));
        self::assertSame(2, $repository->count(['kind' => 'Adjustment']));
        self::assertSame(1, $repository->count(['status' => 'Approved']));
        self::assertSame(1, $repository->count(['kind' => 'Adjustment', 'status' => 'PendingApproval', 'q' => '100%']));
        self::assertSame(2, $repository->count(['q' => 'Cycle count 100']));
        self::assertSame(1, $repository->count(['q' => 'd_g']));
        self::assertSame(0, $repository->count(['q' => 'Damaged%goods']));

        $page = $repository->page(['q' => 'cycle count'], 10, 0);
        self::assertSame([$transfer, $adjust], array_map(static fn (array $r): int => (int) $r['id'], $page));
        self::assertSame('Gudang Pusat Cikarang', $page[1]['warehouse_name']);
        self::assertNull($page[1]['destination_name']);
        self::assertNotNull($page[0]['destination_name']);
        self::assertSame('admin@example.test', $page[0]['creator_email']);
        self::assertSame([$adjust], array_map(static fn (array $r): int => (int) $r['id'], $repository->page(['q' => 'cycle count'], 1, 1)));
        $approved = $repository->page(['status' => 'Approved', 'kind' => 'Transfer'], 10, 0);
        self::assertCount(1, $approved);
        self::assertSame('Approved', $approved[0]['status']);
    }

    public function testOperationDocumentLinksPointToSourceOrders(): void
    {
        $po = $this->pdo->query("SELECT id, reference_id, product_id, warehouse_id FROM stock_ledger WHERE reference_type = 'PO' ORDER BY id LIMIT 1")->fetch();
        $so = $this->pdo->query("SELECT id, reference_id, product_id, warehouse_id FROM stock_ledger WHERE reference_type = 'SO' AND product_id <> {$po['product_id']} ORDER BY id LIMIT 1")->fetch();
        $repository = new MySqlBusinessOperationRepository($this->pdo);
        $operation = $repository->create(['kind' => 'Adjustment', 'warehouse_id' => (int) $po['warehouse_id'], 'destination_id' => null, 'reason' => 'Linked review', 'created_by' => 1, 'condition_confirmed' => 0], [
            ['product_id' => (int) $po['product_id'], 'quantity' => 1, 'source_ledger_id' => (int) $po['id']],
            ['product_id' => (int) $so['product_id'], 'quantity' => 1, 'source_ledger_id' => (int) $so['id']],
        ]);
        $plain = $repository->create(['kind' => 'Adjustment', 'warehouse_id' => 1, 'destination_id' => null, 'reason' => 'Unlinked', 'created_by' => 1, 'condition_confirmed' => 0], [['product_id' => 1, 'quantity' => 1]]);

        $timeline = new MySqlDocumentTimelineRepository($this->pdo);
        self::assertSame([
            ['kind' => 'PO', 'id' => (int) $po['reference_id']],
            ['kind' => 'SO', 'id' => (int) $so['reference_id']],
        ], $timeline->links('Operation', $operation));
        self::assertSame([], $timeline->links('Operation', $plain));
        self::assertSame([], $timeline->links('PO', (int) $po['reference_id']));
    }

    public function testFingerprintRollsBackAndRethrowsWhenATableCannotBeRead(): void
    {
        // A tiny join budget makes MySQL refuse full-table reads (ER_TOO_BIG_SELECT) inside the snapshot transaction.
        $this->pdo->exec('SET SESSION max_join_size = 1');
        $repository = new MySqlOperationalHealthRepository($this->pdo);
        try {
            $repository->fingerprint();
            self::fail('Fingerprint must fail when a table read is refused.');
        } catch (PDOException $exception) {
            self::assertSame(1104, (int) $exception->errorInfo[1]);
        }
        self::assertFalse($this->pdo->inTransaction());

        $this->pdo->exec('SET SESSION max_join_size = DEFAULT');
        $this->pdo->exec('SET SESSION sql_big_selects = 1');
        self::assertArrayHasKey('users', $repository->fingerprint());
    }

    /** @return array<string, int> */
    private function statusCounts(string $table): array
    {
        $counts = [];
        foreach ($this->pdo->query("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status ORDER BY status")->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $this->sorted($counts);
    }

    /** @param array<string, int> $counts @return array<string, int> */
    private function sorted(array $counts): array
    {
        ksort($counts, SORT_STRING);

        return $counts;
    }
}
