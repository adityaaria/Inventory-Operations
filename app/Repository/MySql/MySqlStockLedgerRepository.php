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

    public function appendReceipt(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy, ?int $lotId = null, ?string $unitCost = null): void
    {
        $this->append($productId, $warehouseId, 'Receipt', $quantity, $referenceType, $referenceId, $performedBy, $lotId, $unitCost);
    }

    public function appendIssue(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy, ?int $lotId = null, ?string $unitCost = null): void
    {
        $this->append($productId, $warehouseId, 'Issue', $quantity, $referenceType, $referenceId, $performedBy, $lotId, $unitCost);
    }

    public function appendAdjustment(int $product,int $warehouse,int $delta,string $reference,int $id,int $actor,?int $lot=null,?string $unitCost=null): void
    {
        if($delta===0) { throw new \InvalidArgumentException('Adjustment delta cannot be zero.'); }
        $this->append($product,$warehouse,'Adjustment',$delta,$reference,$id,$actor,$lot,$unitCost);
    }

    /**
     * Receipt and Issue pass a positive quantity; Adjustment passes the signed delta, stored as its absolute quantity plus the delta.
     * D-20 / ADR-026: a row with a lot stores the lot's unit cost and cost_amount = signed quantity × unit cost (whole
     * cents, CostAmount); the DB CHECK chk_stock_ledger_cost recomputes it. Without a passed cost the lot's own cost is read.
     */
    private function append(int $productId, int $warehouseId, string $movementType, int $signedQuantity, string $referenceType, int $referenceId, int $performedBy, ?int $lotId = null, ?string $unitCost = null): void
    {
        $isAdjustment = $movementType === 'Adjustment';
        $quantity = $isAdjustment ? abs($signedQuantity) : $signedQuantity;
        $delta = $isAdjustment ? $signedQuantity : null;
        $signed = $movementType === 'Issue' ? -$signedQuantity : $signedQuantity;
        if ($lotId !== null && $unitCost === null) {
            $lookup = $this->pdo->prepare('SELECT unit_cost FROM stock_lots WHERE id = :id');
            $lookup->execute(['id' => $lotId]);
            $found = $lookup->fetchColumn();
            $unitCost = $found === false ? throw new \App\Exception\PersistenceException('Lot #' . $lotId . ' does not exist.') : (string) $found;
        }
        $cost = $lotId === null ? null : \App\Support\CostAmount::decimal($signed, (string) $unitCost);
        $statement = $this->pdo->prepare(
            'INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, quantity_delta, lot_id, unit_cost, cost_amount)
             VALUES (:product_id, :warehouse_id, :movement_type, :quantity, :reference_type, :reference_id, :performed_by, :quantity_delta, :lot_id, :unit_cost, :cost_amount)'
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
            'lot_id' => $lotId,
            'unit_cost' => $lotId === null ? null : $unitCost,
            'cost_amount' => $cost,
        ]);
    }

    public function forReference(string $referenceType, int $referenceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.product_id, s.warehouse_id, s.movement_type, s.quantity, s.reference_type, s.reference_id, s.performed_by, s.quantity_delta, s.created_at,
                    s.lot_id, l.lot_code, l.expiry_date, s.unit_cost, s.cost_amount
             FROM stock_ledger s LEFT JOIN stock_lots l ON l.id = s.lot_id
             WHERE s.reference_type = :reference_type AND s.reference_id = :reference_id
             ORDER BY s.id ASC'
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
            (string) $row['created_at'],
            $row['lot_id'] === null ? null : (int) $row['lot_id'],
            $row['lot_code'] === null ? null : (string) $row['lot_code'],
            $row['expiry_date'] === null ? null : (string) $row['expiry_date'],
            $row['unit_cost'] === null ? null : (string) $row['unit_cost'],
            $row['cost_amount'] === null ? null : (string) $row['cost_amount'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
