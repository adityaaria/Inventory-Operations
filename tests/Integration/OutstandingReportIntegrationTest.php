<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Security\AuthContext;
use App\Service\ReportService;
use App\Support\CsvResponse;
use App\Support\Pagination;
use PDO;
use PHPUnit\Framework\TestCase;

final class OutstandingReportIntegrationTest extends TestCase
{
    private PDO $pdo;
    private ReportService $reports;

    protected function setUp(): void
    {
        \Tests\Support\TestDatabase::reset();
        $this->pdo = \Tests\Support\TestDatabase::connect();
        $this->reports = new ReportService(new MySqlOperationalQueryRepository($this->pdo));
    }

    public function testAdminScopeCountsEveryOpenDocumentAndExcludesClosedRemainder(): void
    {
        $admin = new AuthContext(1, 'admin@test', 'Admin');
        $expected = $this->scalar("SELECT COUNT(*) FROM purchase_orders po LEFT JOIN purchase_order_closures c ON c.purchase_order_id = po.id WHERE po.status IN ('Draft', 'Ordered', 'PartiallyReceived') AND c.purchase_order_id IS NULL")
            + $this->scalar("SELECT COUNT(*) FROM sales_orders WHERE status IN ('Draft', 'PendingApproval', 'Approved')")
            + $this->scalar("SELECT COUNT(*) FROM inventory_operations WHERE status IN ('PendingApproval', 'Approved')");
        $preview = $this->reports->preview($admin, 'outstanding', null, null, new Pagination());
        self::assertGreaterThan(0, $expected);
        self::assertSame($expected, $preview['result']->total());
        self::assertSame($expected, $preview['metrics']['Outstanding Documents']);
        self::assertSame($expected, array_sum($preview['charts']['Outstanding by Age']));
        self::assertCount($expected, $this->csvRows($admin));

        $partial = $this->pdo->query("SELECT id, order_number FROM purchase_orders WHERE status = 'PartiallyReceived' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($partial);
        $statement = $this->pdo->prepare('INSERT INTO purchase_order_closures (purchase_order_id, closed_by, reason) VALUES (:id, 1, :reason)');
        $statement->execute(['id' => $partial['id'], 'reason' => 'Supplier cannot deliver remainder']);
        $after = $this->reports->preview($admin, 'outstanding', null, null, new Pagination());
        self::assertSame($expected - 1, $after['result']->total());
        self::assertNotContains($partial['order_number'], array_column($this->csvRows($admin), 1));
    }

    public function testWarehouseScopeHoldsOnlyFulfilmentWorkWithRemainingQuantities(): void
    {
        $warehouse = new AuthContext(4, 'warehouse@test', 'WarehouseStaff');
        $preview = $this->reports->preview($warehouse, 'outstanding', null, null, new Pagination());
        $rows = $this->csvRows($warehouse);
        self::assertNotSame([], $rows);
        self::assertCount($preview['result']->total(), $rows);
        $remaining = $this->pdo->prepare('SELECT SUM(poi.quantity - poi.received_quantity) FROM purchase_order_items poi INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id WHERE po.order_number = :number');
        $inbound = 0;
        foreach ($rows as [$type, $number, , $status, , , , , $quantity]) {
            self::assertContains($type . ' ' . $status, ['PO Ordered', 'PO PartiallyReceived', 'SO Approved', 'OP Approved']);
            if ($type === 'PO') {
                $remaining->execute(['number' => $number]);
                self::assertSame((int) $remaining->fetchColumn(), (int) $quantity);
                $inbound += (int) $quantity;
            }
        }
        self::assertSame($inbound, $preview['metrics']['PO Units Awaiting Receipt']);
    }

    public function testSalesScopeIsOwnOrdersAndAgeIsMeasuredFromCreation(): void
    {
        $owner = (int) $this->pdo->query("SELECT created_by FROM sales_orders WHERE status IN ('Draft', 'PendingApproval', 'Approved') GROUP BY created_by HAVING COUNT(*) >= 2 ORDER BY created_by LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $owner);
        $ids = $this->pdo->prepare("SELECT id, order_number FROM sales_orders WHERE created_by = :owner AND status IN ('Draft', 'PendingApproval', 'Approved') ORDER BY id LIMIT 2");
        $ids->execute(['owner' => $owner]);
        [$older, $oldest] = $ids->fetchAll(PDO::FETCH_ASSOC);
        $age = $this->pdo->prepare('UPDATE sales_orders SET created_at = NOW() - INTERVAL :days DAY - INTERVAL 1 HOUR WHERE id = :id');
        $age->execute(['days' => 45, 'id' => $older['id']]);
        $age->execute(['days' => 70, 'id' => $oldest['id']]);

        $sales = new AuthContext($owner, 'sales@test', 'Sales');
        $preview = $this->reports->preview($sales, 'outstanding', null, null, new Pagination());
        $first = $preview['result']->items()[0];
        self::assertSame($oldest['order_number'], $first['OrderNumber']);
        self::assertSame(70, (int) $first['AgeDays']);
        self::assertSame('31+ days', $first['AgeBucket']);
        self::assertSame(70, $preview['metrics']['Oldest Age (Days)']);
        self::assertGreaterThanOrEqual(2, $preview['metrics']['Open Over 7 Days']);
        $owned = $this->pdo->prepare('SELECT created_by FROM sales_orders WHERE order_number = :number');
        foreach ($this->csvRows($sales) as $row) {
            self::assertSame('SO', $row[0]);
            $owned->execute(['number' => $row[1]]);
            self::assertSame($owner, (int) $owned->fetchColumn());
        }

        $day = (string) $this->pdo->query('SELECT DATE(NOW() - INTERVAL 45 DAY - INTERVAL 1 HOUR)')->fetchColumn();
        $filtered = $this->reports->preview($sales, 'outstanding', $day, $day, new Pagination());
        self::assertSame([$older['order_number']], array_column($filtered['result']->items(), 'OrderNumber'));
        self::assertSame('31+ days', $filtered['result']->items()[0]['AgeBucket']);
    }

    public function testStockProposalsAreRoleScopedAndFilterableByDocumentAndAge(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO inventory_operations (kind, status, warehouse_id, reason, created_by, created_at) VALUES ('Adjustment', :status, 1, :reason, 4, NOW() - INTERVAL :days DAY - INTERVAL 1 HOUR)");
        $insert->execute(['status' => 'PendingApproval', 'reason' => 'Cycle count waiting review', 'days' => 40]);
        $pending = 'Adjustment #' . $this->pdo->lastInsertId();
        $insert->execute(['status' => 'Approved', 'reason' => 'Cycle count ready to post', 'days' => 2]);
        $approvedId = (int) $this->pdo->lastInsertId();
        $approved = 'Adjustment #' . $approvedId;
        $this->pdo->prepare('UPDATE inventory_operations SET approved_by = 1, approved_at = NOW() - INTERVAL 1 DAY - INTERVAL 1 HOUR WHERE id = :id')->execute(['id' => $approvedId]);
        $insert->execute(['status' => 'Posted', 'reason' => 'Already posted', 'days' => 90]);
        $insert->execute(['status' => 'Rejected', 'reason' => 'Rejected count', 'days' => 90]);

        $admin = new AuthContext(1, 'admin@test', 'Admin');
        $proposals = $this->reports->preview($admin, 'outstanding', null, null, new Pagination(), ['document' => 'OP']);
        $numbers = array_column($proposals['result']->items(), 'OrderNumber');
        self::assertContains($pending, $numbers);
        self::assertContains($approved, $numbers);
        self::assertSame($this->scalar("SELECT COUNT(*) FROM inventory_operations WHERE status IN ('PendingApproval', 'Approved')"), $proposals['result']->total());
        self::assertSame($proposals['result']->total(), $proposals['metrics']['Stock Proposals Waiting']);
        self::assertSame(0, $proposals['metrics']['PO Units Awaiting Receipt'] + $proposals['metrics']['SO Units Awaiting Issue']);
        foreach ($proposals['result']->items() as $row) {
            self::assertSame('OP', $row['Type']);
            self::assertNull($row['OutstandingQty']);
            if ($row['OrderNumber'] === $approved) self::assertSame(1, (int) $row['DaysSinceApproval'], 'Approved proposals age from approval.');
            if ($row['OrderNumber'] === $pending) self::assertNull($row['DaysSinceApproval'], 'Pending proposals have no approval time.');
        }

        $aged = $this->reports->preview($admin, 'outstanding', null, null, new Pagination(), ['document' => 'OP', 'age' => '31+ days']);
        self::assertSame([$pending], array_column($aged['result']->items(), 'OrderNumber'));
        self::assertSame([$pending], array_column($this->csvRows($admin, ['document' => 'OP', 'age' => '31+ days']), 1));

        $warehouse = new AuthContext(4, 'warehouse@test', 'WarehouseStaff');
        $ready = array_column($this->reports->preview($warehouse, 'outstanding', null, null, new Pagination(), ['document' => 'OP'])['result']->items(), 'OrderNumber');
        self::assertContains($approved, $ready);
        self::assertNotContains($pending, $ready);
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /**
     * @param array<string, string> $filters
     * @return list<array<int, string|null>>
     */
    private function csvRows(AuthContext $actor, array $filters = []): array
    {
        $body = CsvResponse::download('outstanding-orders.csv', $this->reports->csvStream($actor, 'outstanding', null, null, $filters))->body();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);
        self::assertSame(['Type', 'OrderNumber', 'Party', 'Status', 'Created', 'AgeDays', 'AgeBucket', 'DaysSinceApproval', 'OutstandingQty'], fgetcsv($stream));
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) $rows[] = $row;
        fclose($stream);
        return $rows;
    }
}
