<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\StockCatalogRepositoryInterface;
use PDO;

final class MySqlStockCatalogRepository implements StockCatalogRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function lockCreation(): void
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Catalog initialization requires a transaction.');
        // Existing immutable bootstrap row supplies a database mutex without a new schema/table.
        $statement = $this->pdo->prepare("SELECT id FROM schema_versions WHERE version = :version FOR UPDATE");
        $statement->execute(['version' => 'phase-0']);
        if ($statement->fetchColumn() === false) throw new \RuntimeException('Catalog bootstrap lock is unavailable.');
    }

    public function productIds(): array
    {
        $statement = $this->pdo->prepare('SELECT id FROM products ORDER BY id ASC');
        $statement->execute();
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function warehouseIds(): array
    {
        $statement = $this->pdo->prepare('SELECT id FROM warehouses ORDER BY id ASC');
        $statement->execute();
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
