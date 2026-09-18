<?php

declare(strict_types=1);

namespace App\Repository\Contract;

interface StockRepositoryInterface
{
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;
    public function lockByProductWarehouse(int $productId, int $warehouseId): void;
    public function increment(int $productId, int $warehouseId, int $quantity): void;
    public function decrement(int $productId, int $warehouseId, int $quantity): void;
    public function quantity(int $productId, int $warehouseId): int;
}
