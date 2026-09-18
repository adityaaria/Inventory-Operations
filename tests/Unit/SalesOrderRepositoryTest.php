<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\SalesOrder;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SalesOrderRepositoryTest extends TestCase
{
    public function testCreatesSubmitsApprovesCancelsAndFulfillsSalesOrder(): void
    {
        $repository = new InMemorySalesOrderRepository();
        $order = $repository->createDraft('SO-001', 1, 1, 7, [
            ['product_id' => 10, 'quantity' => 2, 'selling_price' => 2000.0],
        ]);

        self::assertSame(SalesOrder::STATUS_DRAFT, $order->status());
        $repository->submit($order->id());
        self::assertSame(SalesOrder::STATUS_PENDING_APPROVAL, $repository->findById($order->id())?->status());
        $repository->approve($order->id(), 1);
        self::assertSame(SalesOrder::STATUS_APPROVED, $repository->findById($order->id())?->status());
        $repository->markFulfilled($order->id());
        self::assertSame(SalesOrder::STATUS_FULFILLED, $repository->findById($order->id())?->status());

        $cancelled = $repository->createDraft('SO-002', 1, 1, 7, [
            ['product_id' => 11, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
        $repository->cancel($cancelled->id());
        self::assertSame(SalesOrder::STATUS_CANCELLED, $repository->findById($cancelled->id())?->status());
    }

    public function testRejectsDuplicateProductLines(): void
    {
        $repository = new InMemorySalesOrderRepository();

        $this->expectException(InvalidArgumentException::class);

        $repository->createDraft('SO-001', 1, 1, 7, [
            ['product_id' => 10, 'quantity' => 2, 'selling_price' => 2000.0],
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
    }
}
