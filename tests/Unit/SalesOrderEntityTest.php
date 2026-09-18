<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use PHPUnit\Framework\TestCase;

final class SalesOrderEntityTest extends TestCase
{
    public function testSalesOrderExposesStatusOwnershipAndItems(): void
    {
        $item = new SalesOrderItem(10, 1, 20, 3, 1500.0);
        $order = new SalesOrder(1, 'SO-001', 5, 2, SalesOrder::STATUS_DRAFT, '2026-08-31', 7, null, null, [$item]);

        self::assertSame(SalesOrder::STATUS_DRAFT, $order->status());
        self::assertSame(7, $order->createdBy());
        self::assertSame(3, $order->items()[0]->quantity());
        self::assertSame(1500.0, $order->items()[0]->sellingPrice());
    }
}
