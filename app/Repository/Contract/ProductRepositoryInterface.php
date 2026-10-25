<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Product;
use App\Entity\ProductStock;
use App\Support\PaginatedResult;
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;

interface ProductRepositoryInterface
{
    /** @return PaginatedResult<Product> */
    public function search(ProductSearchCriteria $criteria): PaginatedResult;
    /** @return list<Product> Active catalog for order selection, independent of list pagination. */
    public function active(): array;
    public function findById(int $id): ?Product;
    public function create(ProductInput $input, bool $isActive): Product;
    public function update(int $id, ProductInput $input): Product;
    public function setActive(int $id, bool $isActive): void;
    /** @return list<ProductStock> */
    public function stocksForProduct(int $productId): array;
    /** @param list<int> $productIds @return array<int, list<ProductStock>> */
    public function stocksForProducts(array $productIds): array;
    /** Locks one product/warehouse stock row (FOR UPDATE) inside the caller's transaction; null when the pair does not exist. */
    public function lockStock(int $productId, int $warehouseId): ?ProductStock;
    /** Sets (int) or clears (null) the per-warehouse reorder point override. Never changes quantity. */
    public function setReorderPointOverride(int $productId, int $warehouseId, ?int $override): void;
}
