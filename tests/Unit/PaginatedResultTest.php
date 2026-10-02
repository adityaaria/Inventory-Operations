<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PaginatedResult;
use PHPUnit\Framework\TestCase;

final class PaginatedResultTest extends TestCase
{
    public function testExposesItemsAndPaginationMetadata(): void
    {
        $result = new PaginatedResult(['a', 'b', 'c'], 25, 2, 10);

        self::assertSame(['a', 'b', 'c'], $result->items());
        self::assertSame(25, $result->total());
        self::assertSame(2, $result->page());
        self::assertSame(10, $result->perPage());
        self::assertSame(3, $result->pages());
    }

    public function testPagesIsAtLeastOneWhenTotalIsZero(): void
    {
        $result = new PaginatedResult([], 0, 1, 10);

        self::assertSame(1, $result->pages());
    }
}
