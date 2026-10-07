<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\ReportController;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\Contract\OperationalQueryRepositoryInterface;
use App\Repository\InMemory\InMemoryOperationalQueryRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\ReportService;
use App\Support\OutstandingCriteria;
use App\Support\Pagination;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportPreviewTest extends TestCase
{
    public function testSummaryUsesEveryMatchingRecordWhilePreviewIsPaginated(): void
    {
        $rows = [];
        for ($i = 0; $i < 23; $i++) $rows[] = ['Type' => $i < 12 ? 'PO' : 'SO', 'Status' => $i < 4 ? 'Received' : 'Draft', 'OrderNumber' => 'Order-' . $i];
        $report = (new ReportService(new InMemoryOperationalQueryRepository($rows)))
            ->preview(new AuthContext(1, 'admin@test', 'Admin'), 'orders', null, null, new Pagination(2));
        self::assertSame(['Total Orders' => 23, 'Purchase Orders' => 12, 'Sales Orders' => 11, 'Completed Orders' => 4], $report['metrics']);
        self::assertSame(['Draft' => 19, 'Received' => 4], $report['charts']['Orders by Status']);
        self::assertCount(10, $report['result']->items());
        self::assertSame('Order-10', $report['result']->items()[0]['OrderNumber']);
        self::assertSame(3, $report['result']->pages());
    }

    public function testSalesPreviewForwardsDateRangeAndAuthenticatedOwner(): void
    {
        $queries = $this->createMock(OperationalQueryRepositoryInterface::class);
        $queries->expects(self::once())->method('reportSummary')->with('orders', '2026-10-01', '2026-10-06', 7)->willReturn([
            'total' => 3, 'counts' => ['Fulfilled' => 1, 'Cancelled' => 1, 'Draft' => 1], 'distribution' => ['SO' => 3], 'received' => 0, 'issued' => 0,
        ]);
        $queries->expects(self::once())->method('reportPage')->with('orders', '2026-10-01', '2026-10-06', 7, 10, 0)->willReturn([]);
        $queries->expects(self::never())->method('orderRows');
        $queries->expects(self::never())->method('stockLedgerRows');
        $report = (new ReportService($queries))->preview(new AuthContext(7, 'sales@test', 'Sales'), 'orders', '2026-10-01', '2026-10-06', new Pagination());
        self::assertSame(['Your Orders' => 3, 'Open Orders' => 1, 'Completed Orders' => 1, 'Cancelled Orders' => 1], $report['metrics']);
    }

    public function testSalesCannotRequestStockPreview(): void
    {
        $queries = $this->createMock(OperationalQueryRepositoryInterface::class);
        $queries->expects(self::never())->method('stockLedgerRows');
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Forbidden');
        (new ReportService($queries))->preview(new AuthContext(7, 'sales@test', 'Sales'), 'stock-ledger', null, null, new Pagination());
    }

    public function testStockSummaryAndEmptyPreviewUseRealRows(): void
    {
        $service = new ReportService(new InMemoryOperationalQueryRepository([
            ['Movement' => 'Receipt', 'Quantity' => 12, 'Warehouse' => 'Main'],
            ['Movement' => 'Issue', 'Quantity' => 4, 'Warehouse' => 'Main'],
        ]));
        $actor = new AuthContext(3, 'warehouse@test', 'WarehouseStaff');
        $report = $service->preview($actor, 'stock-ledger', null, null, new Pagination(99));
        self::assertSame(['Stock Movements' => 2, 'Units Received' => 12, 'Units Issued' => 4, 'Net Units Moved' => 8], $report['metrics']);
        self::assertSame(1, $report['result']->page());
        $empty = (new ReportService(new InMemoryOperationalQueryRepository()))->preview($actor, 'stock-ledger', null, null, new Pagination());
        self::assertSame([], $empty['result']->items());
        self::assertSame(0, $empty['metrics']['Stock Movements']);
    }

    public static function invalidDates(): array
    {
        return [['2026-02-30', null], ['2026-13-01', null], ['2026-10-01', '2026-09-01'], ['bad', null]];
    }

    #[DataProvider('invalidDates')]
    public function testCalendarAndRangeValidationRejectsDatesBeforeQuery(?string $from, ?string $to): void
    {
        $queries = $this->createMock(OperationalQueryRepositoryInterface::class);
        $queries->expects(self::never())->method('orderRows');
        $this->expectException(InvalidArgumentException::class);
        (new ReportService($queries))->preview(new AuthContext(1, 'admin@test', 'Admin'), 'orders', $from, $to, new Pagination());
    }

    public function testReportsControllerShowsErrorsWithoutMisleadingExportAndEscapesRows(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@test', 'Admin'));
        $controller = new ReportController(new ReportService(new InMemoryOperationalQueryRepository([
            ['Type' => 'SO', 'Status' => 'Draft', 'Party' => '<script>alert(1)</script>'],
        ])), new AuthGuard($session));
        foreach ([['from' => ['bad']], ['type' => ['bad']], ['type' => 'bad'], ['from' => '2026-02-30']] as $query) {
            $response = $controller->index(new Request('GET', '/reports', $query, [], []));
            self::assertSame(422, $response->statusCode());
            self::assertStringContainsString('role="alert"', $response->body());
            self::assertStringNotContainsString(' download', $response->body());
        }
        $response = $controller->index(new Request('GET', '/reports', [], [], []));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body());
        self::assertStringContainsString('data-export="server"', $response->body());
    }

    public static function outstandingScopes(): array
    {
        return [
            'admin sees every open document' => [new AuthContext(1, 'admin@test', 'Admin'), 'all', null],
            'warehouse sees fulfilment work' => [new AuthContext(4, 'warehouse@test', 'WarehouseStaff'), 'fulfilment', null],
            'sales sees own orders only' => [new AuthContext(7, 'sales@test', 'Sales'), 'sales', 7],
        ];
    }

    #[DataProvider('outstandingScopes')]
    public function testOutstandingPreviewScopeIsDerivedFromAuthenticatedRole(AuthContext $actor, string $scope, ?int $owner): void
    {
        $queries = $this->createMock(OperationalQueryRepositoryInterface::class);
        $criteria = self::callback(static fn (OutstandingCriteria $criteria): bool => $criteria->scope === $scope && $criteria->owner === $owner
            && $criteria->from === '2026-09-01' && $criteria->to === '2026-10-07' && $criteria->document === 'SO' && $criteria->bucket === '8-30 days');
        $queries->expects(self::once())->method('outstandingSummary')->with($criteria)->willReturn([
            'total' => 0, 'buckets' => array_fill_keys(OperationalQueryRepositoryInterface::AGE_BUCKETS, 0), 'statuses' => [], 'inbound_units' => 0, 'outbound_units' => 0, 'oldest_days' => 0,
        ]);
        $queries->expects(self::once())->method('outstandingPage')->with($criteria, 10, 0)->willReturn([]);
        $queries->expects(self::never())->method('reportSummary');
        $report = (new ReportService($queries))->preview($actor, 'outstanding', '2026-09-01', '2026-10-07', new Pagination(), ['document' => 'SO', 'age' => '8-30 days']);
        self::assertSame('outstanding', $report['type']);
        self::assertSame(['Type', 'OrderNumber', 'Party', 'Status', 'Created', 'AgeDays', 'AgeBucket', 'DaysSinceApproval', 'OutstandingQty'], $report['columns']);
    }

    public function testOutstandingMetricsSeparateInboundAndOutboundUnitsAndCountAgedDocuments(): void
    {
        $rows = [
            ['Type' => 'PO', 'Status' => 'Ordered', 'AgeDays' => 3, 'AgeBucket' => '3-7 days', 'OutstandingQty' => 40],
            ['Type' => 'PO', 'Status' => 'PartiallyReceived', 'AgeDays' => 45, 'AgeBucket' => '31+ days', 'OutstandingQty' => 5],
            ['Type' => 'SO', 'Status' => 'Approved', 'AgeDays' => 75, 'AgeBucket' => '31+ days', 'OutstandingQty' => 8],
            ['Type' => 'OP', 'Status' => 'PendingApproval', 'AgeDays' => 12, 'AgeBucket' => '8-30 days', 'OutstandingQty' => null],
        ];
        $service = new ReportService(new InMemoryOperationalQueryRepository($rows));
        $admin = $service->preview(new AuthContext(1, 'admin@test', 'Admin'), 'outstanding', null, null, new Pagination());
        self::assertSame(['Outstanding Documents' => 4, 'Open Over 7 Days' => 3, 'Oldest Age (Days)' => 75, 'PO Units Awaiting Receipt' => 45, 'SO Units Awaiting Issue' => 8, 'Stock Proposals Waiting' => 1], $admin['metrics']);
        self::assertSame(['0-2 days' => 0, '3-7 days' => 1, '8-30 days' => 1, '31+ days' => 2], $admin['charts']['Outstanding by Age']);
        self::assertSame(['OP PendingApproval' => 1, 'PO Ordered' => 1, 'PO PartiallyReceived' => 1, 'SO Approved' => 1], $admin['charts']['Outstanding by Status']);
        $proposals = $service->preview(new AuthContext(1, 'admin@test', 'Admin'), 'outstanding', null, null, new Pagination(), ['document' => 'OP']);
        self::assertSame(1, $proposals['result']->total());
        self::assertSame(0, $proposals['metrics']['PO Units Awaiting Receipt']);
        $sales = $service->preview(new AuthContext(7, 'sales@test', 'Sales'), 'outstanding', null, null, new Pagination());
        self::assertSame(['Your Open Orders', 'Open Over 7 Days', 'Oldest Age (Days)', 'Units Awaiting Issue'], array_keys($sales['metrics']));
    }

    public function testOutstandingCsvStreamsScopedRowsWithAgeColumns(): void
    {
        $queries = $this->createMock(OperationalQueryRepositoryInterface::class);
        $queries->expects(self::never())->method('iterateReportRows');
        $queries->expects(self::once())->method('iterateOutstandingRows')->with(self::callback(static fn (OutstandingCriteria $criteria): bool => $criteria->scope === 'sales' && $criteria->owner === 7 && $criteria->bucket === '31+ days'))->willReturn([
            ['Type' => 'SO', 'OrderNumber' => 'SO-1', 'Party' => '=cmd', 'Status' => 'Draft', 'Created' => '2026-08-01', 'AgeDays' => 67, 'AgeBucket' => '31+ days', 'DaysSinceApproval' => null, 'OutstandingQty' => 2],
        ]);
        $stream = (new ReportService($queries))->csvStream(new AuthContext(7, 'sales@test', 'Sales'), 'outstanding', null, null, ['age' => '31+ days']);
        ob_start();
        $stream();
        $csv = (string) ob_get_clean();
        self::assertSame("Type,OrderNumber,Party,Status,Created,AgeDays,AgeBucket,DaysSinceApproval,OutstandingQty\nSO,SO-1,'=cmd,Draft,2026-08-01,67,\"31+ days\",,2\n", $csv);
    }

    public static function invalidOutstandingFilters(): array
    {
        return [
            'sales cannot widen to purchase orders' => ['Sales', ['document' => 'PO']],
            'sales cannot widen to stock proposals' => ['Sales', ['document' => 'OP']],
            'unknown document' => ['Admin', ['document' => 'XX']],
            'unknown age bucket' => ['Admin', ['age' => '1-2 days']],
            'array input' => ['WarehouseStaff', ['age' => ['31+ days']]],
            'retired bucket name' => ['Admin', ['age' => '61+ days']],
        ];
    }

    /** @param array<string, mixed> $filters */
    #[DataProvider('invalidOutstandingFilters')]
    public function testOutstandingFiltersOutsideRoleScopeAreRejectedBeforeQuery(string $role, array $filters): void
    {
        $queries = $this->createMock(OperationalQueryRepositoryInterface::class);
        $queries->expects(self::never())->method('outstandingSummary');
        $queries->expects(self::never())->method('iterateOutstandingRows');
        $service = new ReportService($queries);
        $actor = new AuthContext(7, 'user@test', $role);
        try {
            $service->preview($actor, 'outstanding', null, null, new Pagination(), $filters);
            self::fail('Preview accepted an invalid filter.');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        $service->csvStream($actor, 'outstanding', null, null, $filters);
    }
}
