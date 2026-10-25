<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use PDO;

/**
 * Read-only invariant checks for stock count sessions (D-18, ADR-024), shared by the migration runner, the seed summary
 * and the integration tests. Every count in report() must be 0.
 *
 * - posted delta: for every posted count and lot, Σ posted line deltas = Σ STOCK_COUNT ledger rows of that count and lot,
 *   every posted line's delta equals its approved variance, and no STOCK_COUNT ledger row lacks a posted line;
 * - below reserved: no posted count line left its product below the reservation at posting time;
 * - approved by counter: no count was approved by a user who counted or recounted one of its lines;
 * - expected: every counted line's expected quantity = snapshot + Σ ledger rows of the lot between the snapshot watermark
 *   and the line's count (or recount) watermark;
 * - open overlaps: no two open counts of one warehouse share a product.
 */
final class StockCountChecks
{
    private const SIGNED = "CASE s.movement_type WHEN 'Receipt' THEN CAST(s.quantity AS SIGNED) WHEN 'Issue' THEN -CAST(s.quantity AS SIGNED) ELSE s.quantity_delta END";
    private const OPEN = "'Counting', 'Submitted', 'RecountRequested', 'Approved'";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{count_posted_delta_mismatches: int, count_below_reserved: int, count_approved_by_counter: int, count_expected_mismatches: int, count_open_overlaps: int} */
    public function report(): array
    {
        $ledger = "SELECT reference_id, lot_id, SUM(quantity_delta) AS units FROM stock_ledger WHERE reference_type = 'STOCK_COUNT' GROUP BY reference_id, lot_id";
        $posted = "SELECT cl.count_id, cl.posted_lot_id, SUM(cl.posted_delta) AS units FROM stock_count_lines cl INNER JOIN stock_counts c ON c.id = cl.count_id
                   WHERE c.status = 'Posted' AND cl.posted_delta <> 0 GROUP BY cl.count_id, cl.posted_lot_id";

        return [
            'count_posted_delta_mismatches' => $this->count("SELECT COUNT(*) FROM ({$posted}) p LEFT JOIN ({$ledger}) m ON m.reference_id = p.count_id AND m.lot_id = p.posted_lot_id WHERE COALESCE(m.units, 0) <> p.units")
                + $this->count("SELECT COUNT(*) FROM ({$ledger}) m LEFT JOIN ({$posted}) p ON p.count_id = m.reference_id AND p.posted_lot_id = m.lot_id WHERE p.count_id IS NULL")
                + $this->count("SELECT COUNT(*) FROM stock_count_lines cl INNER JOIN stock_counts c ON c.id = cl.count_id WHERE c.status = 'Posted'
                    AND (cl.posted_delta IS NULL OR cl.approved_variance IS NULL OR cl.posted_delta <> cl.approved_variance OR (cl.posted_delta <> 0 AND cl.posted_lot_id IS NULL))"),
            'count_below_reserved' => $this->count('SELECT COUNT(*) FROM stock_count_lines WHERE pair_quantity_after IS NOT NULL AND (pair_reserved_at_post IS NULL OR pair_quantity_after < pair_reserved_at_post)'),
            'count_approved_by_counter' => $this->count('SELECT COUNT(*) FROM stock_counts c WHERE c.approved_by IS NOT NULL AND EXISTS (SELECT 1 FROM stock_count_lines cl WHERE cl.count_id = c.id AND (cl.counted_by = c.approved_by OR cl.recounted_by = c.approved_by))'),
            'count_expected_mismatches' => $this->expectedMismatches('count_ledger_id', 'expected_quantity') + $this->expectedMismatches('recount_ledger_id', 'recount_expected_quantity'),
            'count_open_overlaps' => $this->count('SELECT COUNT(*) FROM stock_count_products a INNER JOIN stock_counts ca ON ca.id = a.count_id
                INNER JOIN stock_count_products b ON b.product_id = a.product_id AND b.count_id > a.count_id INNER JOIN stock_counts cb ON cb.id = b.count_id
                WHERE ca.warehouse_id = cb.warehouse_id AND ca.status IN (' . self::OPEN . ') AND cb.status IN (' . self::OPEN . ')'),
        ];
    }

    /** Informational counts (not invariants). @return array<string, int|string|array<string, int>> */
    public function statistics(): array
    {
        $statement = $this->pdo->query('SELECT status, COUNT(*) FROM stock_counts GROUP BY status ORDER BY status');

        return [
            'counts_by_status' => $statement === false ? [] : array_map('intval', $statement->fetchAll(PDO::FETCH_KEY_PAIR)),
            'count_lines' => $this->count('SELECT COUNT(*) FROM stock_count_lines'),
            'posted_variance_lines' => $this->count("SELECT COUNT(*) FROM stock_count_lines cl INNER JOIN stock_counts c ON c.id = cl.count_id WHERE c.status = 'Posted' AND cl.posted_delta <> 0"),
            'recounted_lines' => $this->count('SELECT COUNT(*) FROM stock_count_lines WHERE recounted_quantity IS NOT NULL'),
            'found_lots_created' => $this->count("SELECT COUNT(*) FROM stock_lots WHERE source_type = 'STOCK_COUNT'"),
            'count_ledger_rows' => $this->count("SELECT COUNT(*) FROM stock_ledger WHERE reference_type = 'STOCK_COUNT'"),
            'net_posted_units' => $this->count("SELECT COALESCE(SUM(quantity_delta), 0) FROM stock_ledger WHERE reference_type = 'STOCK_COUNT'"),
            'net_posted_value' => (string) $this->scalar("SELECT COALESCE(SUM(cl.posted_delta * cl.posted_unit_cost), 0) FROM stock_count_lines cl INNER JOIN stock_counts c ON c.id = cl.count_id WHERE c.status = 'Posted'"),
        ];
    }

    private function expectedMismatches(string $watermark, string $expected): int
    {
        return $this->count("SELECT COUNT(*) FROM stock_count_lines cl INNER JOIN stock_counts c ON c.id = cl.count_id
            WHERE cl.lot_id IS NOT NULL AND cl.{$watermark} IS NOT NULL
              AND cl.{$expected} <> CAST(cl.snapshot_quantity AS SIGNED) + COALESCE((SELECT SUM(" . self::SIGNED . ") FROM stock_ledger s
                  WHERE s.lot_id = cl.lot_id AND s.id > c.snapshot_ledger_id AND s.id <= cl.{$watermark}), 0)");
    }

    private function count(string $sql): int
    {
        return (int) $this->scalar($sql);
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? -1 : $statement->fetchColumn();
    }
}
