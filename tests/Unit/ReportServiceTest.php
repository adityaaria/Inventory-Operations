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
}
