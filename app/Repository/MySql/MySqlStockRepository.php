<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\StockRepositoryInterface;
use PDO;
use RuntimeException;

final class MySqlStockRepository implements StockRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function lockByProductWarehouse(int $productId, int $warehouseId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT quantity FROM product_stocks
             WHERE product_id = :product_id AND warehouse_id = :warehouse_id
             FOR UPDATE'
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);
        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO product_stocks (product_id, warehouse_id, quantity)
             VALUES (:product_id, :warehouse_id, 0)'
        );
        $insert->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);

        // INSERT already holds the new row's exclusive lock until this transaction ends.
    }

    public function increment(int $productId, int $warehouseId, int $quantity): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE product_stocks
             SET quantity = quantity + :quantity
             WHERE product_id = :product_id AND warehouse_id = :warehouse_id'
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity]);
    }

    public function decrement(int $productId, int $warehouseId, int $quantity): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE product_stocks
             SET quantity = quantity - :quantity
             WHERE product_id = :product_id AND warehouse_id = :warehouse_id'
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity]);
    }

    public function quantity(int $productId, int $warehouseId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT quantity FROM product_stocks WHERE product_id = :product_id AND warehouse_id = :warehouse_id' . ($this->pdo->inTransaction() ? ' FOR UPDATE' : '')
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) $row['quantity'] : 0;
    }
}
