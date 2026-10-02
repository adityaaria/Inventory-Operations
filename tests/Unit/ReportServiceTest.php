<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repository\InMemory\InMemoryOperationalQueryRepository;
use App\Service\ReportService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportServiceTest extends TestCase
{
    public function testCsvEscapesAndNeutralizesFormulaCells(): void
    {
        $service = new ReportService(new InMemoryOperationalQueryRepository([
            ['Date' => '2026-08-31', 'Reference' => '=HACK', 'Quantity' => '3'],
        ]));

        $csv = $service->stockLedgerCsv(null, null);

        self::assertStringContainsString("'=HACK", $csv);
    }

    public function testRejectsInvalidDateRange(): void
    {
        $service = new ReportService(new InMemoryOperationalQueryRepository());

        $this->expectException(InvalidArgumentException::class);

        $service->ordersCsv('not-date', null, null);
    }

    public function testRejectsFromDateAfterToDate(): void
    {
        $service = new ReportService(new InMemoryOperationalQueryRepository());

        $this->expectException(InvalidArgumentException::class);

        $service->ordersCsv('2026-09-01', '2026-08-01', null);
    }

    public function testOrdersCsvReturnsRowsForSalesUser(): void
    {
        $service = new ReportService(new InMemoryOperationalQueryRepository([
            ['Order' => 'SO-001', 'Status' => 'Approved'],
        ]));

        $csv = $service->ordersCsv(null, null, 7);

        self::assertStringContainsString('SO-001', $csv);
    }

    public function testCsvReturnsNoDataMessageWhenRowsAreEmpty(): void
    {
        $service = new ReportService(new InMemoryOperationalQueryRepository());

        self::assertSame("No data\n", $service->stockLedgerCsv(null, null));
    }
}
