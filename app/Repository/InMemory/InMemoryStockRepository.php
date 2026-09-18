<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contract\StockRepositoryInterface;

final class InMemoryStockRepository implements StockRepositoryInterface
{
    /** @var array<string, int> */
    private array $stocks = [];
    /** @var array<string, int> */
    private array $snapshot = [];
    /** @var list<string> */
    private array $lockedKeys = [];
    private bool $inTransaction = false;

    public function beginTransaction(): void
    {
        $this->snapshot = $this->stocks;
        $this->lockedKeys = [];
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->snapshot = [];
        $this->inTransaction = false;
    }

    public function rollBack(): void
    {
        if ($this->inTransaction) {
            $this->stocks = $this->snapshot;
        }
        $this->snapshot = [];
        $this->inTransaction = false;
    }

    public function lockByProductWarehouse(int $productId, int $warehouseId): void
    {
        $this->lockedKeys[] = $warehouseId . ':' . $productId;
    }

    public function increment(int $productId, int $warehouseId, int $quantity): void
    {
        $key = $this->key($productId, $warehouseId);
        $this->stocks[$key] = ($this->stocks[$key] ?? 0) + $quantity;
    }

    public function decrement(int $productId, int $warehouseId, int $quantity): void
    {
        $key = $this->key($productId, $warehouseId);
        $this->stocks[$key] = ($this->stocks[$key] ?? 0) - $quantity;
    }

    public function quantity(int $productId, int $warehouseId): int
    {
        return $this->stocks[$this->key($productId, $warehouseId)] ?? 0;
    }

    /** @return list<string> */
    public function lockedKeys(): array
    {
        return $this->lockedKeys;
    }

    public function seed(int $productId, int $warehouseId, int $quantity): void
    {
        $this->stocks[$this->key($productId, $warehouseId)] = $quantity;
    }

    private function key(int $productId, int $warehouseId): string
    {
        return $warehouseId . ':' . $productId;
    }
}
