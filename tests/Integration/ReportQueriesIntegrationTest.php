<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Security\AuthContext;
use App\Service\ReportService;
use App\Support\Pagination;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReportQueriesIntegrationTest extends TestCase
{
    private function database(): PDO
    {
        return \Tests\Support\TestDatabase::connect();
    }

    public function testOrderDateFiltersWorkWithNativePreparedUnionAndBothTypes(): void
    {
        $pdo = $this->database();
        $day = (string) $pdo->query('SELECT MIN(order_date) FROM (SELECT order_date FROM purchase_orders UNION ALL SELECT order_date FROM sales_orders) dates')->fetchColumn();
        self::assertNotSame('', $day);
        $expected = 0;
        foreach (['purchase_orders', 'sales_orders'] as $table) {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE order_date = :day");
            $statement->execute(['day' => $day]);
            $expected += (int) $statement->fetchColumn();
        }
        $rows = (new MySqlOperationalQueryRepository($pdo))->orderRows($day, $day, null);
        self::assertGreaterThan(0, $expected);
        self::assertCount($expected, $rows);
        foreach ($rows as $row) self::assertSame($day, $row['Date']);
    }

    public function testLedgerIncludesWholeEndDayAndMatchesPreviewAndCsv(): void
    {
        $pdo = $this->database();
        $day = (string) $pdo->query('SELECT DATE(MAX(created_at)) FROM stock_ledger')->fetchColumn();
        self::assertNotSame('', $day);
        $statement = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN movement_type = 'Receipt' THEN quantity ELSE 0 END), 0) AS received, COALESCE(SUM(CASE WHEN movement_type = 'Issue' THEN quantity ELSE 0 END), 0) AS issued FROM stock_ledger WHERE DATE(created_at) = :day");
        $statement->execute(['day' => $day]);
        $expected = $statement->fetch(PDO::FETCH_ASSOC);
        $service = new ReportService(new MySqlOperationalQueryRepository($pdo));
        $preview = $service->preview(new AuthContext(1, 'admin@test', 'Admin'), 'stock-ledger', $day, $day, new Pagination());
        self::assertGreaterThan(0, (int) $expected['total']);
        self::assertSame((int) $expected['total'], $preview['result']->total());
        self::assertSame((int) $expected['received'], $preview['metrics']['Units Received']);
        self::assertSame((int) $expected['issued'], $preview['metrics']['Units Issued']);
        foreach ($preview['result']->items() as $row) self::assertSame($day, $row['Date']);
        self::assertCount((int) $expected['total'], $this->csvRows($service->stockLedgerCsv($day, $day)));
    }

    public function testSalesScopeAndCsvContainAllRowsBeyondPreviewPage(): void
    {
        $pdo = $this->database();
        $owner = (int) $pdo->query('SELECT created_by FROM sales_orders ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $owner);
        $statement = $pdo->prepare('SELECT order_number FROM sales_orders WHERE created_by = :owner ORDER BY order_date DESC, id DESC');
        $statement->execute(['owner' => $owner]);
        $expected = $statement->fetchAll(PDO::FETCH_COLUMN);
        $repository = new MySqlOperationalQueryRepository($pdo);
        $rows = $repository->orderRows(null, null, $owner);
        self::assertSame($expected, array_column($rows, 'OrderNumber'));
        self::assertSame(['SO'], array_values(array_unique(array_column($rows, 'Type'))));
        $service = new ReportService($repository);
        $preview = $service->preview(new AuthContext(1, 'admin@test', 'Admin'), 'orders', null, null, new Pagination(2));
        self::assertGreaterThan(10, $preview['result']->total());
        self::assertLessThanOrEqual(10, count($preview['result']->items()));
        self::assertCount($preview['result']->total(), $this->csvRows($service->ordersCsv(null, null, null)));
    }

    public function testStreamingExportKeepsOwnerScopeAndReleasesCursor(): void
    {
        $pdo = $this->database();
        $service = new ReportService(new MySqlOperationalQueryRepository($pdo));
        $owner = (int) $pdo->query('SELECT created_by FROM sales_orders ORDER BY id LIMIT 1')->fetchColumn();
        $stream = $service->csvStream(new AuthContext($owner, 'sales@test', 'Sales'), 'orders', null, null);
        $response = \App\Support\CsvResponse::download('orders.csv', $stream);
        $rows = $this->csvRows($response->body());
        $statement = $pdo->prepare('SELECT COUNT(*) FROM sales_orders WHERE created_by = :owner');
        $statement->execute(['owner' => $owner]);
        self::assertCount((int) $statement->fetchColumn(), $rows);
        foreach ($rows as $row) self::assertSame('SO', $row[0]);
        self::assertTrue($pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
    }

    /** @return list<array<int, string|null>> */
    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        fgetcsv($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) $rows[] = $row;
        fclose($stream);
        return $rows;
    }
}
