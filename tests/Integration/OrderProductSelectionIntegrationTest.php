<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\MySqlProductRepository;
use App\Support\ProductSearchCriteria;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class OrderProductSelectionIntegrationTest extends TestCase
{
    public function testActiveOrderCatalogIncludesProductsBeyondFirstListPage(): void
    {
        TestDatabase::reset();
        $repository = new MySqlProductRepository(TestDatabase::connect());
        $all = $repository->active();
        self::assertGreaterThanOrEqual(30, count($all));
        self::assertCount(10, $repository->search(ProductSearchCriteria::fromArray([]))->items());
        $id = $all[count($all)-1]->id();
        $repository->setActive($id, false);
        self::assertCount(count($all)-1, $repository->active());
        self::assertNotContains($id, array_map(static fn ($product): int => $product->id(), $repository->active()));
    }
}
