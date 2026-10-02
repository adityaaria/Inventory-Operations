<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Category;
use App\Entity\Product;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProductRepositoryTest extends TestCase
{
    public function testRejectsDuplicateSku(): void
    {
        $repository = new InMemoryProductRepository([
            new Product(1, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
        ]);

        $this->expectException(InvalidArgumentException::class);

        $repository->create(new ProductInput('SKU-001', 'Widget B', 'pcs', 2000.0, 2500.0, 5, 1), true);
    }

    public function testSearchPaginatesProducts(): void
    {
        $repository = new InMemoryProductRepository([
            new Product(1, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
            new Product(2, 'SKU-002', 'Widget B', 'pcs', 2000.0, 2500.0, 5, 1, true),
            new Product(3, 'SKU-003', 'Widget C', 'pcs', 3000.0, 3500.0, 5, 1, true),
        ]);

        $result = $repository->search(ProductSearchCriteria::fromArray(['page' => 2, 'per_page' => 2]));

        self::assertSame(3, $result->total());
        self::assertSame(2, $result->page());
        self::assertCount(1, $result->items());
        self::assertSame('SKU-003', $result->items()[0]->sku());
    }

    public function testCategoryRepositoryListsActiveRows(): void
    {
        $repository = new InMemoryCategoryRepository([
            new Category(1, 'Raw Materials', 'Material inputs', true),
            new Category(2, 'Inactive', 'Hidden category', false),
        ]);

        self::assertCount(1, $repository->active());
    }
}
