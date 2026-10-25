<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use PDO;
use Throwable;

/**
 * Data step of the valuation migration (D-20 / ADR-026), run by scripts/migrate-inventory-valuation.php after the
 * additive DDL (phase A) and before the CHECK (phase B). One transaction: lock every stock pair (no movement can run),
 * give every ledger row that has a lot and no cost the lot's unit cost and cost_amount = signed quantity × unit cost,
 * then re-check every invariant (valuation, lots, stock = ledger); any failure rolls back and returns a conflict report.
 * Rows without a lot (before the ADR-023 watermark) are never given a cost. Re-running is a no-op that re-verifies.
 */
final class InventoryValuationMigration
{
    public const VERSION = 'inventory-valuation-1';
    private const SIGNED = MySqlInventoryValuationRepository::SIGNED;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Read-only preview with value totals (cents); works before phase A as well. @return array<string, mixed> */
    public function dryRun(): array
    {
        $hasColumns = $this->hasColumns();
        $pending = $hasColumns ? 's.lot_id IS NOT NULL AND s.unit_cost IS NULL' : 's.lot_id IS NOT NULL';
        $byClass = $this->pdo->query("SELECT s.movement_type, s.reference_type, COUNT(*) AS rows_count, SUM(CAST(ROUND((" . self::SIGNED . ") * l.unit_cost * 100) AS SIGNED)) AS cents
            FROM stock_ledger s INNER JOIN stock_lots l ON l.id = s.lot_id WHERE {$pending} GROUP BY s.movement_type, s.reference_type ORDER BY s.movement_type, s.reference_type");
        $classes = [];
        foreach ($byClass === false ? [] : $byClass->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $classes[] = ['movement' => (string) $row['movement_type'], 'reference' => (string) $row['reference_type'], 'rows' => (int) $row['rows_count'], 'cost_cents' => (int) $row['cents']];
        }
        $projected = $this->int("SELECT COALESCE(SUM(CAST(ROUND((" . self::SIGNED . ") * l.unit_cost * 100) AS SIGNED)), 0) FROM stock_ledger s INNER JOIN stock_lots l ON l.id = s.lot_id");
        $opening = $this->int('SELECT COALESCE(SUM(CAST(ROUND(opening_quantity * unit_cost * 100) AS SIGNED)), 0) FROM stock_lots');
        $current = $this->int('SELECT COALESCE(SUM(CAST(ROUND(quantity * unit_cost * 100) AS SIGNED)), 0) FROM stock_lots');

        return [
            'mode' => 'dry-run',
            'already_applied' => $this->applied(),
            'columns_present' => $hasColumns,
            'watermark' => $this->int('SELECT COALESCE((SELECT legacy_ledger_max_id FROM stock_lot_watermark WHERE id = 1), 0)'),
            'ledger_rows_before_valuation_start' => $this->int('SELECT COUNT(*) FROM stock_ledger WHERE lot_id IS NULL'),
            'rows_to_backfill' => array_sum(array_column($classes, 'rows')),
            'backfill_by_movement' => $classes,
            'lot_value_now_cents' => $current,
            'lot_opening_value_cents' => $opening,
            'ledger_cost_after_backfill_cents' => $projected,
            'projected_rollforward_difference_cents' => $current - $opening - $projected,
            'master_price_value_cents' => $this->int('SELECT COALESCE(SUM(CAST(ROUND(ps.quantity * p.purchase_price * 100) AS SIGNED)), 0) FROM product_stocks ps INNER JOIN products p ON p.id = ps.product_id'),
            'projected_lot_rollforward_mismatches' => $this->int('SELECT COUNT(*) FROM stock_lots l LEFT JOIN (SELECT s.lot_id, SUM(' . self::SIGNED . ') AS units FROM stock_ledger s WHERE s.lot_id IS NOT NULL GROUP BY s.lot_id) m
                ON m.lot_id = l.id WHERE CAST(l.quantity AS SIGNED) <> CAST(l.opening_quantity AS SIGNED) + COALESCE(m.units, 0)'),
        ];
    }

    /** @return array<string, mixed> status ready|conflict plus counts */
    public function apply(): array
    {
        if (!$this->hasColumns()) {
            return ['status' => 'conflict', 'reason' => 'Run phase A (database/migrations/20261012-inventory-valuation.sql) first.'];
        }
        $this->pdo->beginTransaction();
        try {
            // Every movement locks its pair first (ADR-002/023), so holding all pairs freezes the ledger.
            $this->pdo->query('SELECT product_id FROM product_stocks ORDER BY product_id, warehouse_id FOR UPDATE')->fetchAll();
            $updated = (int) $this->pdo->exec('UPDATE stock_ledger s INNER JOIN stock_lots l ON l.id = s.lot_id
                SET s.unit_cost = l.unit_cost, s.cost_amount = (' . self::SIGNED . ') * l.unit_cost
                WHERE s.lot_id IS NOT NULL AND (s.unit_cost IS NULL OR s.cost_amount IS NULL)');
            $checks = $this->checks();
            if (array_sum($checks) !== 0) {
                $this->pdo->rollBack();

                return ['status' => 'conflict', 'reason' => 'An invariant failed after the backfill; everything was rolled back.', 'checks' => $checks];
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return ['status' => 'ready', 'already_applied' => $this->applied(), 'rows_backfilled' => $updated, 'checks' => $checks, 'statistics' => (new ValuationChecks($this->pdo))->statistics()];
    }

    /** @return array<string, int> */
    public function checks(): array
    {
        $lots = (new BatchLotChecks($this->pdo))->report();

        return (new ValuationChecks($this->pdo))->report() + [
            'lot_aggregate_mismatches' => $lots['lot_aggregate_mismatches'],
            'lot_ledger_mismatches' => $lots['lot_ledger_mismatches'],
            'ledger_rows_without_lot' => $lots['ledger_rows_without_lot'],
            // ADR-003: stock = Σ ledger per pair (all history, valued or not).
            'ledger_mismatches' => $this->int('SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT s.product_id, s.warehouse_id, SUM(' . self::SIGNED . ') AS units FROM stock_ledger s GROUP BY s.product_id, s.warehouse_id) m
                ON m.product_id = ps.product_id AND m.warehouse_id = ps.warehouse_id WHERE CAST(ps.quantity AS SIGNED) <> COALESCE(m.units, 0)'),
        ];
    }

    public function applied(): bool
    {
        return $this->int("SELECT COUNT(*) FROM schema_versions WHERE version = '" . self::VERSION . "'") === 1;
    }

    private function hasColumns(): bool
    {
        return $this->int("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_ledger' AND column_name IN ('unit_cost', 'cost_amount')") === 2;
    }

    private function int(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }
}
