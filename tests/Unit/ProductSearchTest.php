<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductStock;
use App\Entity\Warehouse;
use App\Support\ProductSearchCriteria;
use PHPUnit\Framework\TestCase;

final class ProductSearchTest extends TestCase
{
    public function testProductExposesCatalogFields(): void
    {
        $product = new Product(1, 'SKU-001', 'Widget A', 'pcs', 10000.0, 12500.0, 10, 1, true);

        self::assertSame(1, $product->id());
        self::assertSame('SKU-001', $product->sku());
        self::assertSame('Widget A', $product->name());
        self::assertSame('pcs', $product->unit());
        self::assertSame(10000.0, $product->purchasePrice());
        self::assertSame(12500.0, $product->sellingPrice());
        self::assertSame(12500.0, $product->price());
        self::assertSame(10, $product->reorderPoint());
        self::assertSame(1, $product->categoryId());
        self::assertTrue($product->isActive());
    }

    public function testProductStockDetectsLowStock(): void
    {
        $stock = new ProductStock(1, 2, 'SKU-001', 'Widget A', 'Main Warehouse', 3, 5);

        self::assertTrue($stock->isLowStock());
    }

    public function testSearchCriteriaHasSafeDefaults(): void
    {
        $criteria = ProductSearchCriteria::fromArray([]);

        self::assertSame('', $criteria->term());
        self::assertNull($criteria->categoryId());
        self::assertNull($criteria->stockStatus());
        self::assertSame(1, $criteria->page());
        self::assertSame(10, $criteria->perPage());
        self::assertSame('name', $criteria->sortBy());
        self::assertSame('asc', $criteria->direction());
    }

    public function testSimpleMasterDataEntitiesExposeFields(): void
    {
        self::assertSame('Material inputs', (new Category(1, 'Raw Materials', 'Material inputs', true))->description());
        self::assertSame('Main Warehouse', (new Warehouse(1, 'Main Warehouse', 'Jakarta', true))->name());
    }
}
