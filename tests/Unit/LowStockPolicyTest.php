<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repository\InMemory\InMemoryOperationalQueryRepository;
use App\Service\LowStockService;
use PHPUnit\Framework\TestCase;

final class LowStockPolicyTest extends TestCase
{
    public function testLowStockUsesWarehouseQuantityBelowReorderPoint(): void
    {
        $rows = (new LowStockService(new InMemoryOperationalQueryRepository()))->rows();

        self::assertSame('SKU-001', $rows[0]['sku']);
        self::assertLessThan($rows[0]['reorder_point'], $rows[0]['quantity']);
    }
}
