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
}
