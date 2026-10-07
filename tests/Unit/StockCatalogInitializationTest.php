<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repository\InMemory\{InMemoryStockRepository, InMemoryStockCatalogRepository};
use App\Repository\Contract\StockLedgerRepositoryInterface;
use App\Service\StockService;
use PHPUnit\Framework\TestCase;

final class StockCatalogInitializationTest extends TestCase
{
    public function testZeroInitializationLocksWarehousesInOrderAndNeverWritesMovementLedger(): void
    {
        $stocks = new InMemoryStockRepository(); $stocks->seed(10, 1, 7);
        $ledger = $this->createMock(StockLedgerRepositoryInterface::class);
        $ledger->expects($this->never())->method('appendReceipt'); $ledger->expects($this->never())->method('appendIssue');
        $service = new StockService($stocks, $ledger, null, new InMemoryStockCatalogRepository([10], [2,1,2]));
        $service->initializeProduct(10);
        self::assertSame(['1:10','2:10'], $stocks->lockedKeys());
        self::assertSame(7, $stocks->quantity(10,1));
        self::assertSame(0, $stocks->quantity(10,2));
    }

    public function testNewWarehouseLocksProductsInDeterministicOrder(): void
    {
        $stocks = new InMemoryStockRepository();
        $service = new StockService($stocks, $this->createMock(StockLedgerRepositoryInterface::class), null, new InMemoryStockCatalogRepository([10,3,10], [1,2]));
        $service->initializeWarehouse(2);
        self::assertSame(['2:3','2:10'], $stocks->lockedKeys());
    }
}
