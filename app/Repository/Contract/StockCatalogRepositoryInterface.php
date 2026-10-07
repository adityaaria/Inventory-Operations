<?php

declare(strict_types=1);

namespace App\Repository\Contract;

interface StockCatalogRepositoryInterface
{
    /** Serialize product/warehouse creation and zero-balance initialization in the current transaction. */
    public function lockCreation(): void;
    /** @return list<int> */
    public function productIds(): array;
    /** @return list<int> */
    public function warehouseIds(): array;
}
