<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\PurchaseOrder;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderRepositoryTest extends TestCase
{
    public function testCreatesDraftAndPersistsItems(): void
    {
        $repository = new InMemoryPurchaseOrderRepository();

        $purchaseOrder = $repository->createDraft('PO-001', 1, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);

        self::assertSame(PurchaseOrder::STATUS_DRAFT, $purchaseOrder->status());
        self::assertSame(1, $purchaseOrder->supplierId());
        self::assertCount(1, $purchaseOrder->items());
        self::assertSame(5, $purchaseOrder->items()[0]->remainingQuantity());
    }

    public function testMarksOrderAsOrdered(): void
    {
        $repository = new InMemoryPurchaseOrderRepository();
        $purchaseOrder = $repository->createDraft('PO-001', 1, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);

        $repository->markOrdered($purchaseOrder->id());

        self::assertSame(PurchaseOrder::STATUS_ORDERED, $repository->findById($purchaseOrder->id())?->status());
    }

    public function testRecordsReceivedQuantitiesAndStatus(): void
    {
        $repository = new InMemoryPurchaseOrderRepository();
        $purchaseOrder = $repository->createDraft('PO-001', 1, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);
        $repository->markOrdered($purchaseOrder->id());

        $repository->recordReceipt($purchaseOrder->id(), [$purchaseOrder->items()[0]->id() => 3], PurchaseOrder::STATUS_PARTIALLY_RECEIVED);

        $updated = $repository->findById($purchaseOrder->id());
        self::assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $updated?->status());
        self::assertSame(2, $updated?->items()[0]->remainingQuantity());
    }

    public function testRejectsDuplicateProductLines(): void
    {
        $repository = new InMemoryPurchaseOrderRepository();

        $this->expectException(InvalidArgumentException::class);

        $repository->createDraft('PO-001', 1, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
            ['product_id' => 10, 'quantity' => 2, 'purchase_price' => 1000.0],
        ]);
    }
}
