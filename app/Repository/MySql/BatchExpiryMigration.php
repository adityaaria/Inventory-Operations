<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use PDO;
use Throwable;

/**
 * Data step of the batch/lot migration (D-17 / ADR-023), run by scripts/migrate-batch-expiry.php after the additive DDL.
 * One transaction: lock every stock pair; each pair with quantity > 0 and no lot gets one LEGACY lot (no expiry,
 * opening = quantity, unit cost = current purchase price per base unit); the ledger watermark records the last
 * pre-migration ledger id (those rows keep lot_id NULL); on the first run products of food and beverage categories get
 * tracks_expiry = 1 (later Admin choices survive re-runs). Every invariant, old and new, is re-checked before commit; any
 * failure rolls back and returns a conflict report without recording schema version 'batch-expiry-1'. Re-running after
 * success is a no-op that re-verifies.
 */
final class BatchExpiryMigration
{
    public const VERSION = 'batch-expiry-1';
    /** Category names (case-insensitive) treated as food and beverage. */
    public const FOOD_PATTERN = '(minuman|kopi|teh|susu|^mi |mi &|makanan|biskuit|wafer|bumbu|saus|sembako|food|beverage|drink|snack|dairy|beras|gula)';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Read-only report: what apply() would do. @return array<string, mixed> */
    public function dryRun(): array
    {
        $applied = $this->applied();
        $report = [
            'mode' => 'dry-run',
            'already_applied' => $applied,
            'pairs_to_convert' => $this->int('SELECT COUNT(*) FROM product_stocks ps WHERE ps.quantity > 0 AND NOT EXISTS (SELECT 1 FROM stock_lots l WHERE l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id)'),
            'units_to_convert' => $this->int('SELECT COALESCE(SUM(ps.quantity), 0) FROM product_stocks ps WHERE ps.quantity > 0 AND NOT EXISTS (SELECT 1 FROM stock_lots l WHERE l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id)'),
            'legacy_stock_value' => (float) $this->value('SELECT COALESCE(SUM(ps.quantity * p.purchase_price), 0) FROM product_stocks ps INNER JOIN products p ON p.id = ps.product_id WHERE ps.quantity > 0 AND NOT EXISTS (SELECT 1 FROM stock_lots l WHERE l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id)'),
            'pairs_with_zero_stock' => $this->int('SELECT COUNT(*) FROM product_stocks WHERE quantity = 0'),
            'ledger_rows_kept_without_lot' => $this->int('SELECT COUNT(*) FROM stock_ledger WHERE lot_id IS NULL'),
            'food_beverage_categories' => $applied ? [] : $this->pdo->query("SELECT name FROM categories WHERE LOWER(name) REGEXP '" . self::FOOD_PATTERN . "' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
            'products_to_track_expiry' => $applied ? 0 : $this->int("SELECT COUNT(*) FROM products p INNER JOIN categories c ON c.id = p.category_id WHERE LOWER(c.name) REGEXP '" . self::FOOD_PATTERN . "' AND p.tracks_expiry = 0"),
            'in_transit_transfers_without_lots' => $this->int("SELECT COUNT(*) FROM inventory_operations o WHERE o.kind = 'Transfer' AND o.status = 'Dispatched' AND NOT EXISTS (SELECT 1 FROM inventory_operation_lots t WHERE t.operation_id = o.id)"),
            'approved_stock_proposals_pair_level' => $this->int("SELECT COUNT(*) FROM inventory_operations WHERE kind = 'Adjustment' AND status IN ('PendingApproval', 'Approved')"),
        ];

        return $report + ['unsafe' => $this->unsafe()];
    }

    /** @return array<string, mixed> status ready|conflict plus counts */
    public function apply(): array
    {
        if ($this->applied()) {
            $checks = $this->checks();

            return ['status' => array_sum($checks) === 0 ? 'ready' : 'conflict', 'already_applied' => true, 'legacy_lots_created' => 0, 'products_flagged' => 0, 'checks' => $checks];
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT product_id FROM product_stocks ORDER BY product_id, warehouse_id FOR UPDATE')->fetchAll();
            $unsafe = $this->unsafe();
            if (array_sum($unsafe) !== 0) {
                $this->pdo->rollBack();

                return ['status' => 'conflict', 'reason' => 'Existing data breaks an invariant; nothing was changed.', 'unsafe' => $unsafe];
            }
            $created = $this->pdo->exec("INSERT INTO stock_lots (product_id, warehouse_id, lot_seq, lot_code, expiry_date, unit_cost, quantity, opening_quantity, source_type)
                SELECT ps.product_id, ps.warehouse_id, 1, 'LEGACY', NULL, p.purchase_price, ps.quantity, ps.quantity, 'LEGACY'
                FROM product_stocks ps INNER JOIN products p ON p.id = ps.product_id
                WHERE ps.quantity > 0 AND NOT EXISTS (SELECT 1 FROM stock_lots l WHERE l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id)
                ORDER BY ps.product_id, ps.warehouse_id");
            $this->pdo->exec('UPDATE product_stocks ps INNER JOIN (SELECT product_id, warehouse_id, MAX(lot_seq) AS top FROM stock_lots GROUP BY product_id, warehouse_id) l
                ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id SET ps.lot_count = l.top WHERE ps.lot_count <> l.top');
            $this->pdo->exec('INSERT INTO stock_lot_watermark (id, legacy_ledger_max_id) SELECT 1, COALESCE(MAX(id), 0) FROM stock_ledger ON DUPLICATE KEY UPDATE id = id');
            $flagged = $this->pdo->exec("UPDATE products p INNER JOIN categories c ON c.id = p.category_id SET p.tracks_expiry = 1 WHERE LOWER(c.name) REGEXP '" . self::FOOD_PATTERN . "' AND p.tracks_expiry = 0");
            $checks = $this->checks();
            if (array_sum($checks) !== 0) {
                $this->pdo->rollBack();

                return ['status' => 'conflict', 'reason' => 'An invariant failed after the conversion; everything was rolled back.', 'checks' => $checks];
            }
            $statement = $this->pdo->prepare('INSERT INTO schema_versions (version, description) VALUES (:version, :description) ON DUPLICATE KEY UPDATE description = VALUES(description)');
            $statement->execute(['version' => self::VERSION, 'description' => 'Batch/lot tracking with expiry and FEFO issue (D-17, ADR-023)']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['status' => 'ready', 'already_applied' => false, 'legacy_lots_created' => (int) $created, 'products_flagged' => (int) $flagged, 'checks' => $checks];
    }

    /** Invariants that must hold after the conversion (old and new). @return array<string, int> */
    public function checks(): array
    {
        $checks = (new BatchLotChecks($this->pdo))->report();
        $checks['ledger_mismatches'] = $this->int("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type = 'Receipt' THEN quantity WHEN movement_type = 'Issue' THEN -quantity ELSE COALESCE(quantity_delta, 0) END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)");
        $checks['reservation_mismatches'] = (new StockReservationReconciler($this->pdo))->mismatches();
        $checks['reserved_above_quantity'] = $this->int('SELECT COUNT(*) FROM product_stocks WHERE reserved_quantity > quantity');

        return $checks + (new PartialFulfilmentChecks($this->pdo))->report() + (new TransferTransitChecks($this->pdo))->report() + (new MultiUnitChecks($this->pdo))->report();
    }

    /** Conditions under which the conversion must not run. @return array<string, int> */
    private function unsafe(): array
    {
        return [
            'ledger_mismatches' => $this->int("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type = 'Receipt' THEN quantity WHEN movement_type = 'Issue' THEN -quantity ELSE COALESCE(quantity_delta, 0) END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)"),
            'reserved_above_quantity' => $this->int('SELECT COUNT(*) FROM product_stocks WHERE reserved_quantity > quantity'),
            'partial_lot_pairs' => $this->int('SELECT COUNT(*) FROM product_stocks ps INNER JOIN (SELECT product_id, warehouse_id, SUM(quantity) AS units FROM stock_lots GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE l.units <> ps.quantity'),
            'ledger_rows_with_lot_before_migration' => $this->applied() ? 0 : $this->int('SELECT COUNT(*) FROM stock_ledger WHERE lot_id IS NOT NULL'),
        ];
    }

    private function applied(): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM schema_versions WHERE version = :version');
        $statement->execute(['version' => self::VERSION]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function int(string $sql): int
    {
        return (int) $this->value($sql);
    }

    private function value(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? 0 : $statement->fetchColumn();
    }
}
