<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\StockLedgerEntry;
use App\Repository\Contract\StockLedgerRepositoryInterface;
use PDO;

final class MySqlStockLedgerRepository implements StockLedgerRepositoryInterface, \App\Repository\Contract\AdjustmentLedgerRepositoryInterface
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

    public function appendAdjustment(int $product,int $warehouse,int $delta,string $reference,int $id,int $actor): void
    {
        if($delta===0) { throw new \InvalidArgumentException('Adjustment delta cannot be zero.'); }
        $this->append($product,$warehouse,'Adjustment',$delta,$reference,$id,$actor);
    }

    /** Receipt and Issue pass a positive quantity; Adjustment passes the signed delta, stored as its absolute quantity plus the delta. */
    private function append(int $productId, int $warehouseId, string $movementType, int $signedQuantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        $isAdjustment = $movementType === 'Adjustment';
        $quantity = $isAdjustment ? abs($signedQuantity) : $signedQuantity;
        $delta = $isAdjustment ? $signedQuantity : null;
        $statement = $this->pdo->prepare(
            'INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, quantity_delta)
             VALUES (:product_id, :warehouse_id, :movement_type, :quantity, :reference_type, :reference_id, :performed_by, :quantity_delta)'
        );
        $statement->execute([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'performed_by' => $performedBy,
            'quantity_delta' => $delta,
        ]);
    }

    public function forReference(string $referenceType, int $referenceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, quantity_delta
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
            $row['quantity_delta'] === null ? null : (int) $row['quantity_delta'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
