<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Support\BusinessDate;
use PDO;

/**
 * Read-only invariant checks for batch/lot tracking (D-17 / ADR-023), shared by the migration runner, the seed summary
 * and the integration tests. Every count must be 0.
 *
 * - lot aggregate: per pair Σ stock_lots.quantity = product_stocks.quantity;
 * - lot ledger: per lot quantity = opening_quantity + Σ signed ledger rows with its lot_id;
 * - lot count: product_stocks.lot_count = number of lots of the pair = highest lot_seq;
 * - ledger without lot: no ledger row after the migration watermark lacks a lot;
 * - issue from expired lot: no Issue row took units of a lot that was expired at the issue time (business date);
 * - FEFO: for every Issue row no other lot of the pair that sorts earlier in FEFO order (expiry NULLS LAST, received_at,
 *   id), existed and was not expired at that time still had units right after the row (no override is implemented);
 * - transfer lots: lot rows add up to their transfer line (dispatched, received, damaged, short) and to the TRANSFER
 *   ledger rows of the source and destination lots.
 */
final class BatchLotChecks
{
    private const SIGNED = "CASE s.movement_type WHEN 'Receipt' THEN CAST(s.quantity AS SIGNED) WHEN 'Issue' THEN -CAST(s.quantity AS SIGNED) ELSE s.quantity_delta END";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{lot_aggregate_mismatches: int, lot_ledger_mismatches: int, lot_count_mismatches: int, ledger_rows_without_lot: int, issue_from_expired_lot: int, fefo_violations: int, transfer_lot_mismatches: int} */
    public function report(): array
    {
        return [
            'lot_aggregate_mismatches' => $this->count('SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(quantity) AS units FROM stock_lots GROUP BY product_id, warehouse_id) l
                ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.units, 0)'),
            'lot_ledger_mismatches' => $this->count('SELECT COUNT(*) FROM stock_lots l LEFT JOIN (SELECT s.lot_id, SUM(' . self::SIGNED . ') AS units FROM stock_ledger s WHERE s.lot_id IS NOT NULL GROUP BY s.lot_id) m
                ON m.lot_id = l.id WHERE CAST(l.quantity AS SIGNED) <> CAST(l.opening_quantity AS SIGNED) + COALESCE(m.units, 0)'),
            'lot_count_mismatches' => $this->count('SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, COUNT(*) AS lots, MAX(lot_seq) AS top FROM stock_lots GROUP BY product_id, warehouse_id) l
                ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.lot_count <> COALESCE(l.lots, 0) OR ps.lot_count <> COALESCE(l.top, 0)'),
            'ledger_rows_without_lot' => $this->count('SELECT COUNT(*) FROM stock_ledger s WHERE s.lot_id IS NULL AND s.id > COALESCE((SELECT legacy_ledger_max_id FROM stock_lot_watermark WHERE id = 1), 0)'),
            'issue_from_expired_lot' => $this->count("SELECT COUNT(*) FROM stock_ledger s INNER JOIN stock_lots l ON l.id = s.lot_id
                WHERE s.movement_type = 'Issue' AND l.expiry_date IS NOT NULL AND l.expiry_date < " . $this->dateAt('s.created_at')),
            'fefo_violations' => $this->count("SELECT COUNT(*) FROM stock_ledger s INNER JOIN stock_lots l ON l.id = s.lot_id
                WHERE s.movement_type = 'Issue' AND EXISTS (
                    SELECT 1 FROM stock_lots e WHERE e.product_id = l.product_id AND e.warehouse_id = l.warehouse_id AND e.id <> l.id
                      AND (e.expiry_key, e.received_at, e.id) < (l.expiry_key, l.received_at, l.id)
                      AND e.received_at <= s.created_at
                      AND (e.expiry_date IS NULL OR e.expiry_date >= " . $this->dateAt('s.created_at') . ")
                      AND CAST(e.opening_quantity AS SIGNED) + COALESCE((SELECT SUM(" . str_replace('s.', 'x.', self::SIGNED) . ") FROM stock_ledger x WHERE x.lot_id = e.id AND x.id <= s.id), 0) > 0)"),
            'transfer_lot_mismatches' => $this->transferLotMismatches(),
        ];
    }

    /** Lines whose lot rows do not add up, plus lot rows whose TRANSFER ledger rows do not match. */
    public function transferLotMismatches(): int
    {
        return $this->count("SELECT COUNT(*) FROM inventory_operation_items i INNER JOIN (SELECT operation_item_id, SUM(dispatched_quantity) AS d, SUM(received_quantity) AS r, SUM(damaged_quantity) AS g, SUM(short_quantity) AS s
                FROM inventory_operation_lots GROUP BY operation_item_id) t ON t.operation_item_id = i.id
            WHERE t.d <> COALESCE(i.dispatched_quantity, 0) OR NOT (t.r <=> i.received_quantity) OR NOT (t.g <=> i.damaged_quantity) OR NOT (t.s <=> i.short_quantity)")
            + $this->count("SELECT COUNT(*) FROM inventory_operation_lots t LEFT JOIN (SELECT reference_id, lot_id, SUM(-quantity_delta) AS units FROM stock_ledger
                WHERE reference_type = 'TRANSFER' AND movement_type = 'Adjustment' AND quantity_delta < 0 GROUP BY reference_id, lot_id) m ON m.reference_id = t.operation_id AND m.lot_id = t.source_lot_id
            WHERE COALESCE(m.units, 0) <> t.dispatched_quantity")
            + $this->count("SELECT COUNT(*) FROM inventory_operation_lots t LEFT JOIN (SELECT reference_id, lot_id, SUM(quantity_delta) AS units FROM stock_ledger
                WHERE reference_type = 'TRANSFER' AND movement_type = 'Adjustment' AND quantity_delta > 0 GROUP BY reference_id, lot_id) m ON m.reference_id = t.operation_id AND m.lot_id = t.destination_lot_id
            WHERE COALESCE(t.received_quantity, 0) <> COALESCE(m.units, 0)");
    }

    /** Counts that describe the lots (informational, not invariants). @return array<string, int> */
    public function statistics(): array
    {
        $today = ExpirySql::today();

        return [
            'lots' => $this->count('SELECT COUNT(*) FROM stock_lots'),
            'lots_with_stock' => $this->count('SELECT COUNT(*) FROM stock_lots WHERE quantity > 0'),
            'legacy_lots' => $this->count("SELECT COUNT(*) FROM stock_lots WHERE source_type = 'LEGACY'"),
            'expired_lots_with_stock' => $this->count('SELECT COUNT(*) FROM stock_lots WHERE quantity > 0 AND expiry_date < ' . $today),
            'expired_units' => $this->count('SELECT COALESCE(SUM(quantity), 0) FROM stock_lots WHERE quantity > 0 AND expiry_date < ' . $today),
            'tracked_products' => $this->count('SELECT COUNT(*) FROM products WHERE tracks_expiry = 1'),
            'tracked_lots_without_expiry' => $this->count("SELECT COUNT(*) FROM stock_lots l INNER JOIN products p ON p.id = l.product_id WHERE p.tracks_expiry = 1 AND l.expiry_date IS NULL AND l.lot_code <> 'LEGACY' AND l.quantity > 0"),
            'over_reserved_pairs' => $this->count('SELECT COUNT(*) FROM product_stocks ps WHERE ps.reserved_quantity > 0 AND CAST(ps.reserved_quantity AS SIGNED) > CAST(ps.quantity AS SIGNED) - ' . ExpirySql::expiredQuantity('ps')),
            'issue_rows_multi_lot_lines' => $this->count("SELECT COUNT(*) FROM (SELECT reference_id, product_id, created_at FROM stock_ledger WHERE movement_type = 'Issue' AND lot_id IS NOT NULL GROUP BY reference_id, product_id, created_at HAVING COUNT(*) > 1) m"),
            'transfer_lot_rows' => $this->count('SELECT COUNT(*) FROM inventory_operation_lots'),
        ];
    }

    /** Business date of a stored UTC timestamp expression. */
    private function dateAt(string $column): string
    {
        return 'DATE(' . $column . ' + INTERVAL ' . BusinessDate::offsetSeconds() . ' SECOND)';
    }

    private function count(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
