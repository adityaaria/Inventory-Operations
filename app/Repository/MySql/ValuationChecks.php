<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use PDO;

/**
 * Read-only invariant checks of the FIFO-per-batch valuation (D-20 / ADR-026), shared by the migration runner, the seed
 * summary and the tests. Every count must be 0. All money is compared in integer cents.
 *
 * - ledger cost missing: every row with a lot carries unit_cost and cost_amount;
 * - ledger cost without lot: rows before the valuation start (no lot) never carry a cost;
 * - ledger cost lot: the row's unit_cost equals its lot's unit_cost and the row's product/warehouse are the lot's;
 * - ledger cost amount: cost_amount = signed quantity × unit_cost (Receipt +, Issue −, Adjustment = delta);
 * - lot value roll-forward: per lot quantity × unit_cost = opening_quantity × unit_cost + Σ cost_amount of its rows;
 * - pair value: per product/warehouse Σ lot values = Σ lot opening values + Σ cost_amount of the pair's valued rows;
 * - transit cost: every transfer lot row carries its source lot's cost, its destination lot (if any) has the same cost,
 *   the TRANSFER out rows cost −dispatched × cost and the in rows cost received × cost;
 * - transit value: per transfer with lots, −Σ out cost − Σ in cost − (damaged + short) × cost = units still in transit
 *   × cost (dispatched for Dispatched transfers, 0 once Received);
 * - COGS delivery note: per delivery note −Σ cost_amount of its linked Issue rows = Σ lot rows × lot unit cost;
 * - COGS order: per sales order −Σ cost_amount of its valued Issue rows = Σ COGS of its delivery notes.
 */
final class ValuationChecks
{
    private const SIGNED = MySqlInventoryValuationRepository::SIGNED;
    private const CENTS = 'CAST(ROUND(s.cost_amount * 100) AS SIGNED)';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, int> */
    public function report(): array
    {
        return [
            'ledger_cost_missing' => $this->count('SELECT COUNT(*) FROM stock_ledger WHERE lot_id IS NOT NULL AND (unit_cost IS NULL OR cost_amount IS NULL)'),
            'ledger_cost_without_lot' => $this->count('SELECT COUNT(*) FROM stock_ledger WHERE lot_id IS NULL AND (unit_cost IS NOT NULL OR cost_amount IS NOT NULL)'),
            'ledger_cost_lot_mismatches' => $this->count('SELECT COUNT(*) FROM stock_ledger s INNER JOIN stock_lots l ON l.id = s.lot_id
                WHERE s.unit_cost IS NOT NULL AND (s.unit_cost <> l.unit_cost OR s.product_id <> l.product_id OR s.warehouse_id <> l.warehouse_id)'),
            'ledger_cost_amount_mismatches' => $this->count('SELECT COUNT(*) FROM stock_ledger s WHERE s.cost_amount IS NOT NULL AND s.cost_amount <> (' . self::SIGNED . ') * s.unit_cost'),
            'lot_value_rollforward_mismatches' => $this->count('SELECT COUNT(*) FROM stock_lots l LEFT JOIN (SELECT s.lot_id, SUM(' . self::CENTS . ') AS cents FROM stock_ledger s
                    WHERE s.lot_id IS NOT NULL AND s.cost_amount IS NOT NULL GROUP BY s.lot_id) m ON m.lot_id = l.id
                WHERE CAST(ROUND(l.quantity * l.unit_cost * 100) AS SIGNED) <> CAST(ROUND(l.opening_quantity * l.unit_cost * 100) AS SIGNED) + COALESCE(m.cents, 0)'),
            'pair_value_mismatches' => $this->count('SELECT COUNT(*) FROM product_stocks ps
                LEFT JOIN (SELECT product_id, warehouse_id, SUM(CAST(ROUND(quantity * unit_cost * 100) AS SIGNED)) AS value, SUM(CAST(ROUND(opening_quantity * unit_cost * 100) AS SIGNED)) AS opening
                    FROM stock_lots GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id
                LEFT JOIN (SELECT s.product_id, s.warehouse_id, SUM(' . self::CENTS . ') AS cents FROM stock_ledger s WHERE s.cost_amount IS NOT NULL GROUP BY s.product_id, s.warehouse_id) m
                    ON m.product_id = ps.product_id AND m.warehouse_id = ps.warehouse_id
                WHERE COALESCE(l.value, 0) <> COALESCE(l.opening, 0) + COALESCE(m.cents, 0)'),
            'transit_cost_mismatches' => $this->transitCostMismatches(),
            'transit_value_mismatches' => $this->count("SELECT COUNT(*) FROM (SELECT o.id, o.status,
                    (SELECT COALESCE(SUM(-CAST(ROUND(s.cost_amount * 100) AS SIGNED)), 0) FROM stock_ledger s WHERE s.reference_type = 'TRANSFER' AND s.reference_id = o.id AND s.quantity_delta < 0) AS out_cents,
                    (SELECT COALESCE(SUM(CAST(ROUND(s.cost_amount * 100) AS SIGNED)), 0) FROM stock_ledger s WHERE s.reference_type = 'TRANSFER' AND s.reference_id = o.id AND s.quantity_delta > 0) AS in_cents,
                    (SELECT COALESCE(SUM(CAST(ROUND((COALESCE(t.damaged_quantity, 0) + COALESCE(t.short_quantity, 0)) * t.unit_cost * 100) AS SIGNED)), 0) FROM inventory_operation_lots t WHERE t.operation_id = o.id) AS loss_cents,
                    (SELECT COALESCE(SUM(CAST(ROUND(t.dispatched_quantity * t.unit_cost * 100) AS SIGNED)), 0) FROM inventory_operation_lots t WHERE t.operation_id = o.id) AS dispatched_cents
                FROM inventory_operations o WHERE o.kind = 'Transfer' AND EXISTS (SELECT 1 FROM inventory_operation_lots t WHERE t.operation_id = o.id)) x
                WHERE x.out_cents <> x.dispatched_cents OR x.out_cents - x.in_cents - x.loss_cents <> (CASE WHEN x.status = 'Dispatched' THEN x.dispatched_cents ELSE 0 END)"),
            'cogs_delivery_note_mismatches' => $this->count('SELECT COUNT(*) FROM (SELECT x.delivery_note_id,
                    SUM(-' . self::CENTS . ') AS cogs, SUM(CAST(ROUND(x.quantity * l.unit_cost * 100) AS SIGNED)) AS lots
                FROM delivery_note_lots x INNER JOIN stock_ledger s ON s.id = x.ledger_id INNER JOIN stock_lots l ON l.id = x.lot_id
                WHERE s.cost_amount IS NOT NULL GROUP BY x.delivery_note_id) d WHERE d.cogs <> d.lots'),
            'cogs_order_mismatches' => $this->count("SELECT COUNT(*) FROM (SELECT s.reference_id, SUM(-" . self::CENTS . ") AS cogs FROM stock_ledger s
                    WHERE s.movement_type = 'Issue' AND s.reference_type = 'SO' AND s.cost_amount IS NOT NULL GROUP BY s.reference_id) i
                LEFT JOIN (SELECT dn.sales_order_id, SUM(-" . self::CENTS . ") AS cogs FROM delivery_note_lots x INNER JOIN delivery_notes dn ON dn.id = x.delivery_note_id
                    INNER JOIN stock_ledger s ON s.id = x.ledger_id WHERE s.cost_amount IS NOT NULL GROUP BY dn.sales_order_id) d ON d.sales_order_id = i.reference_id
                WHERE i.cogs <> COALESCE(d.cogs, 0)"),
        ];
    }

    /** Transfer lot rows whose cost differs from their lots or from their TRANSFER ledger rows. */
    public function transitCostMismatches(): int
    {
        return $this->count('SELECT COUNT(*) FROM inventory_operation_lots t INNER JOIN stock_lots src ON src.id = t.source_lot_id LEFT JOIN stock_lots dst ON dst.id = t.destination_lot_id
                WHERE t.unit_cost <> src.unit_cost OR (dst.id IS NOT NULL AND dst.unit_cost <> t.unit_cost)')
            + $this->count("SELECT COUNT(*) FROM inventory_operation_lots t LEFT JOIN (SELECT reference_id, lot_id, SUM(-CAST(ROUND(cost_amount * 100) AS SIGNED)) AS cents FROM stock_ledger
                    WHERE reference_type = 'TRANSFER' AND quantity_delta < 0 AND cost_amount IS NOT NULL GROUP BY reference_id, lot_id) m ON m.reference_id = t.operation_id AND m.lot_id = t.source_lot_id
                WHERE COALESCE(m.cents, 0) <> CAST(ROUND(t.dispatched_quantity * t.unit_cost * 100) AS SIGNED)")
            + $this->count("SELECT COUNT(*) FROM inventory_operation_lots t LEFT JOIN (SELECT reference_id, lot_id, SUM(CAST(ROUND(cost_amount * 100) AS SIGNED)) AS cents FROM stock_ledger
                    WHERE reference_type = 'TRANSFER' AND quantity_delta > 0 AND cost_amount IS NOT NULL GROUP BY reference_id, lot_id) m ON m.reference_id = t.operation_id AND m.lot_id = t.destination_lot_id
                WHERE COALESCE(m.cents, 0) <> CAST(ROUND(COALESCE(t.received_quantity, 0) * t.unit_cost * 100) AS SIGNED)");
    }

    /** Descriptive totals (not invariants), in cents and counts. @return array<string, int|string|null> */
    public function statistics(): array
    {
        $watermark = $this->pdo->query('SELECT legacy_ledger_max_id, created_at FROM stock_lot_watermark WHERE id = 1');
        $row = $watermark === false ? false : $watermark->fetch(PDO::FETCH_ASSOC);

        return [
            'valuation_watermark' => is_array($row) ? (int) $row['legacy_ledger_max_id'] : 0,
            'ledger_rows_valued' => $this->count('SELECT COUNT(*) FROM stock_ledger WHERE cost_amount IS NOT NULL'),
            'ledger_rows_before_valuation_start' => $this->count('SELECT COUNT(*) FROM stock_ledger WHERE cost_amount IS NULL'),
            'on_hand_value_cents' => $this->count('SELECT COALESCE(SUM(CAST(ROUND(quantity * unit_cost * 100) AS SIGNED)), 0) FROM stock_lots'),
            'opening_value_cents' => $this->count('SELECT COALESCE(SUM(CAST(ROUND(opening_quantity * unit_cost * 100) AS SIGNED)), 0) FROM stock_lots'),
            'ledger_cost_cents' => $this->count('SELECT COALESCE(SUM(CAST(ROUND(cost_amount * 100) AS SIGNED)), 0) FROM stock_ledger WHERE cost_amount IS NOT NULL'),
            'in_transit_value_cents' => $this->count("SELECT COALESCE(SUM(CAST(ROUND(t.dispatched_quantity * t.unit_cost * 100) AS SIGNED)), 0) FROM inventory_operation_lots t
                INNER JOIN inventory_operations o ON o.id = t.operation_id WHERE o.status = 'Dispatched'"),
            'cogs_cents' => $this->count("SELECT COALESCE(SUM(-CAST(ROUND(cost_amount * 100) AS SIGNED)), 0) FROM stock_ledger WHERE movement_type = 'Issue' AND cost_amount IS NOT NULL"),
            'receipt_cost_cents' => $this->count("SELECT COALESCE(SUM(CAST(ROUND(cost_amount * 100) AS SIGNED)), 0) FROM stock_ledger WHERE movement_type = 'Receipt' AND cost_amount IS NOT NULL"),
            'distinct_cost_layers' => $this->count('SELECT COUNT(*) FROM (SELECT product_id, unit_cost FROM stock_lots GROUP BY product_id, unit_cost) x'),
            'products_with_several_costs' => $this->count('SELECT COUNT(*) FROM (SELECT product_id FROM stock_lots GROUP BY product_id HAVING COUNT(DISTINCT unit_cost) > 1) x'),
        ];
    }

    private function count(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
