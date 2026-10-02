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

    public function testReceiptRejectsMissingWarehouse(): void
    {
        $service = new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Warehouse is required.');

        $service->receive(0, [new StockMovement(10, 1)], 1, 'PO', 99);
    }

    public function testReceiptRejectsMissingPerformer(): void
    {
        $service = new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Performer is required.');

        $service->receive(1, [new StockMovement(10, 1)], 0, 'PO', 99);
    }

    public function testReceiptRejectsMissingReference(): void
    {
        $service = new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference is required.');

        $service->receive(1, [new StockMovement(10, 1)], 1, '', 99);
    }

    public function testReceiptRejectsEmptyMovementList(): void
    {
        $service = new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one stock movement is required.');

        $service->receive(1, [], 1, 'PO', 99);
    }

    public function testReceiptRejectsMissingProduct(): void
    {
        $service = new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product is required.');

        $service->receive(1, [new StockMovement(0, 1)], 1, 'PO', 99);
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
