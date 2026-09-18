<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\StockLedgerEntry;
use App\Repository\Contract\StockLedgerRepositoryInterface;
use PDO;

final class MySqlStockLedgerRepository implements StockLedgerRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function appendReceipt(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        $this->append($productId, $warehouseId, 'Receipt', $quantity, $referenceType, $referenceId, $performedBy);
    }

    public function appendIssue(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        $this->append($productId, $warehouseId, 'Issue', $quantity, $referenceType, $referenceId, $performedBy);
    }

    private function append(int $productId, int $warehouseId, string $movementType, int $quantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by)
             VALUES (:product_id, :warehouse_id, :movement_type, :quantity, :reference_type, :reference_id, :performed_by)'
        );
        $statement->execute([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'performed_by' => $performedBy,
        ]);
    }

    public function forReference(string $referenceType, int $referenceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by
             FROM stock_ledger
             WHERE reference_type = :reference_type AND reference_id = :reference_id
             ORDER BY id ASC'
        );
        $statement->execute(['reference_type' => $referenceType, 'reference_id' => $referenceId]);

        return array_map(static fn (array $row): StockLedgerEntry => new StockLedgerEntry(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['warehouse_id'],
            (string) $row['movement_type'],
            (int) $row['quantity'],
            (string) $row['reference_type'],
            (int) $row['reference_id'],
            (int) $row['performed_by'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
