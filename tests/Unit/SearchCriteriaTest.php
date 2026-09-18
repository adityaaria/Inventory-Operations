<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OrderSearchCriteria;
use App\Support\ProductSearchCriteria;
use PHPUnit\Framework\TestCase;

final class SearchCriteriaTest extends TestCase
{
    public function testProductCriteriaNormalizesPaginationAndSort(): void
    {
        $criteria = ProductSearchCriteria::fromArray(['page' => '-1', 'per_page' => '99', 'sort' => 'bad', 'direction' => 'DESC', 'q' => ' SKU ']);

        self::assertSame(1, $criteria->page());
        self::assertSame(10, $criteria->perPage());
        self::assertSame('name', $criteria->sortBy());
        self::assertSame('desc', $criteria->direction());
        self::assertSame('SKU', $criteria->term());
    }

    public function testOrderCriteriaKeepsAllowedStatusAndSort(): void
    {
        $criteria = OrderSearchCriteria::fromArray(['status' => 'Approved', 'sort' => 'order_date', 'direction' => 'desc', 'q' => 'SO']);

        self::assertSame('Approved', $criteria->status());
        self::assertSame('order_date', $criteria->sortBy());
        self::assertSame('desc', $criteria->direction());
        self::assertSame('SO', $criteria->term());
    }
}
