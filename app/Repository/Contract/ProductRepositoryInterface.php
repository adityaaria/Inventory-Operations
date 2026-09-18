<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Product;
use App\Entity\ProductStock;
use App\Support\PaginatedResult;
use App\Support\ProductSearchCriteria;

interface ProductRepositoryInterface
{
    /** @return PaginatedResult<Product> */
    public function search(ProductSearchCriteria $criteria): PaginatedResult;
    public function findById(int $id): ?Product;
    public function create(string $sku, string $name, string $unit, float $purchasePrice, float $sellingPrice, int $reorderPoint, int $categoryId, bool $isActive): Product;
    public function update(int $id, string $sku, string $name, string $unit, float $purchasePrice, float $sellingPrice, int $reorderPoint, int $categoryId): Product;
    public function setActive(int $id, bool $isActive): void;
    /** @return list<ProductStock> */
    public function stocksForProduct(int $productId): array;
}
