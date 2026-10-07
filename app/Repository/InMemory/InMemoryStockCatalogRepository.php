<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contract\StockCatalogRepositoryInterface;

final class InMemoryStockCatalogRepository implements StockCatalogRepositoryInterface
{
    /** @var list<int> */
    private readonly array $products;
    /** @var list<int> */
    private readonly array $warehouses;

    /** @param list<int> $productIds @param list<int> $warehouseIds */
    public function __construct(array $productIds = [], array $warehouseIds = [])
    {
        sort($productIds); sort($warehouseIds);
        $this->products = array_values(array_unique($productIds));
        $this->warehouses = array_values(array_unique($warehouseIds));
    }

    public function lockCreation(): void {}
    public function productIds(): array { return $this->products; }
    public function warehouseIds(): array { return $this->warehouses; }
}
