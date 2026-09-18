<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Service\StockMovement;
use App\Service\StockService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StockServiceIssueTest extends TestCase
{
    public function testIssueDecrementsStockAndWritesLedgerInDeterministicOrder(): void
    {
        $stock = new InMemoryStockRepository();
        $stock->seed(10, 1, 5);
        $stock->seed(20, 1, 4);
        $ledger = new InMemoryStockLedgerRepository();
        $service = new StockService($stock, $ledger);

        $service->issue(1, [new StockMovement(20, 2), new StockMovement(10, 3)], 1, 'SO', 99);

        self::assertSame(['1:10', '1:20'], $stock->lockedKeys());
        self::assertSame(2, $stock->quantity(10, 1));
        self::assertSame(2, $stock->quantity(20, 1));
        self::assertSame('Issue', $ledger->entries()[0]->movementType());
    }

    public function testIssueRejectsInsufficientStockAndRollsBack(): void
    {
        $stock = new InMemoryStockRepository();
        $stock->seed(10, 1, 2);
        $service = new StockService($stock, new InMemoryStockLedgerRepository());

        $this->expectException(InvalidArgumentException::class);

        $service->issue(1, [new StockMovement(10, 3)], 1, 'SO', 99);
    }
}
