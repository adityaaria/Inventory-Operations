<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use PDO;

/**
 * Read-only invariant checks for transfers with goods in transit (D-15 / ADR-021), shared by the migration runner, the
 * seed summary and the integration tests. Stock = ledger is checked elsewhere and is unaffected: a dispatch writes a
 * negative Adjustment row at the source, a receipt a positive one at the destination for the good units only.
 *
 * - dispatch ledger: per transfer line, the negative TRANSFER Adjustment rows at the source sum to dispatched_quantity.
 * - receipt ledger:  per transfer line, the positive TRANSFER Adjustment rows at the destination sum to received_quantity.
 * - status: Dispatched ⇒ every line dispatched and none received; Received ⇒ every line received; other ⇒ none dispatched.
 * - in-transit equation: Σ dispatched − Σ received − Σ written off (damaged + short) = Σ dispatched of open Dispatched
 *   transfers (reported as the absolute difference, 0 when it holds).
 * - discrepancy reason: a Received transfer with damaged or short units has a receipt reason.
 */
final class TransferTransitChecks
{
    public const DISPATCH_LEDGER_MISMATCHES = "SELECT COUNT(*) FROM inventory_operation_items i
        INNER JOIN inventory_operations o ON o.id = i.operation_id AND o.kind = 'Transfer'
        LEFT JOIN (SELECT reference_id, product_id, warehouse_id, SUM(-quantity_delta) AS units FROM stock_ledger
                   WHERE movement_type = 'Adjustment' AND reference_type = 'TRANSFER' AND quantity_delta < 0
                   GROUP BY reference_id, product_id, warehouse_id) l
            ON l.reference_id = o.id AND l.product_id = i.product_id AND l.warehouse_id = o.warehouse_id
        WHERE COALESCE(i.dispatched_quantity, 0) <> COALESCE(l.units, 0)";

    public const RECEIPT_LEDGER_MISMATCHES = "SELECT COUNT(*) FROM inventory_operation_items i
        INNER JOIN inventory_operations o ON o.id = i.operation_id AND o.kind = 'Transfer'
        LEFT JOIN (SELECT reference_id, product_id, warehouse_id, SUM(quantity_delta) AS units FROM stock_ledger
                   WHERE movement_type = 'Adjustment' AND reference_type = 'TRANSFER' AND quantity_delta > 0
                   GROUP BY reference_id, product_id, warehouse_id) l
            ON l.reference_id = o.id AND l.product_id = i.product_id AND l.warehouse_id = o.destination_id
        WHERE COALESCE(i.received_quantity, 0) <> COALESCE(l.units, 0)";

    public const STATUS_MISMATCHES = "SELECT COUNT(*) FROM inventory_operation_items i
        INNER JOIN inventory_operations o ON o.id = i.operation_id AND o.kind = 'Transfer'
        WHERE (o.status = 'Dispatched' AND (i.dispatched_quantity IS NULL OR i.received_quantity IS NOT NULL))
           OR (o.status = 'Received' AND i.received_quantity IS NULL)
           OR (o.status NOT IN ('Dispatched', 'Received') AND i.dispatched_quantity IS NOT NULL)";

    public const IN_TRANSIT_EQUATION = "SELECT ABS(
            COALESCE(SUM(i.dispatched_quantity), 0) - COALESCE(SUM(i.received_quantity), 0)
            - COALESCE(SUM(i.damaged_quantity), 0) - COALESCE(SUM(i.short_quantity), 0)
            - COALESCE(SUM(CASE WHEN o.status = 'Dispatched' THEN i.dispatched_quantity ELSE 0 END), 0))
        FROM inventory_operation_items i INNER JOIN inventory_operations o ON o.id = i.operation_id AND o.kind = 'Transfer'";

    public const DISCREPANCY_WITHOUT_REASON = "SELECT COUNT(*) FROM inventory_operations o
        WHERE o.kind = 'Transfer' AND o.status = 'Received'
          AND (o.receipt_reason IS NULL OR TRIM(o.receipt_reason) = '')
          AND EXISTS (SELECT 1 FROM inventory_operation_items i WHERE i.operation_id = o.id AND i.damaged_quantity + i.short_quantity > 0)";

    public const IN_TRANSIT_UNITS = "SELECT COALESCE(SUM(i.dispatched_quantity), 0) FROM inventory_operation_items i
        INNER JOIN inventory_operations o ON o.id = i.operation_id WHERE o.kind = 'Transfer' AND o.status = 'Dispatched'";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{transfer_dispatch_ledger_mismatches: int, transfer_receipt_ledger_mismatches: int, transfer_status_mismatches: int, in_transit_equation_mismatch: int, transfer_discrepancy_without_reason: int} */
    public function report(): array
    {
        return [
            'transfer_dispatch_ledger_mismatches' => $this->count(self::DISPATCH_LEDGER_MISMATCHES),
            'transfer_receipt_ledger_mismatches' => $this->count(self::RECEIPT_LEDGER_MISMATCHES),
            'transfer_status_mismatches' => $this->count(self::STATUS_MISMATCHES),
            'in_transit_equation_mismatch' => $this->count(self::IN_TRANSIT_EQUATION),
            'transfer_discrepancy_without_reason' => $this->count(self::DISCREPANCY_WITHOUT_REASON),
        ];
    }

    /** Units currently in transit (dispatched, not yet received). */
    public function inTransitUnits(): int
    {
        return $this->count(self::IN_TRANSIT_UNITS);
    }

    private function count(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
