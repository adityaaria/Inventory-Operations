<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Service\StockMovement;
use App\Service\StockService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StockServiceReceiptTest extends TestCase
{
    public function testReceiptIncrementsStockAndWritesLedgerInDeterministicOrder(): void
    {
        $stock = new InMemoryStockRepository();
        $ledger = new InMemoryStockLedgerRepository();
        $service = new StockService($stock, $ledger);

        $service->receive(2, [
            new StockMovement(20, 3),
            new StockMovement(10, 5),
        ], 1, 'PO', 99);

        self::assertSame(['2:10', '2:20'], $stock->lockedKeys());
        self::assertSame(5, $stock->quantity(10, 2));
        self::assertSame(3, $stock->quantity(20, 2));
        self::assertCount(2, $ledger->entries());
        self::assertSame('Receipt', $ledger->entries()[0]->movementType());
    }

    public function testReceiptRejectsNonPositiveQuantity(): void
    {
        $service = new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository());

        $this->expectException(InvalidArgumentException::class);

        $service->receive(1, [new StockMovement(10, 0)], 1, 'PO', 99);
    }

    public function testReceiptRollsBackWhenLedgerFails(): void
    {
        $stock = new InMemoryStockRepository();
        $ledger = new InMemoryStockLedgerRepository(failOnAppend: true);
        $service = new StockService($stock, $ledger);

        try {
            $service->receive(1, [new StockMovement(10, 5)], 1, 'PO', 99);
            self::fail('Expected ledger failure.');
        } catch (RuntimeException) {
            self::assertSame(0, $stock->quantity(10, 1));
        }
    }
}
