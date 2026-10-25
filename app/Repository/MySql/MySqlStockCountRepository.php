<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Exception\ValidationException;
use App\Repository\Contract\StockCountRepositoryInterface;
use PDO;

/**
 * stock_counts, stock_count_counters, stock_count_products and stock_count_lines (D-18, ADR-024). Locking reads touch
 * only these tables (never products, lots or users), so a count lock never takes a stock lock out of the ADR-023 order.
 */
final class MySqlStockCountRepository implements StockCountRepositoryInterface
{
    private const SIGNED = "CASE s.movement_type WHEN 'Receipt' THEN CAST(s.quantity AS SIGNED) WHEN 'Issue' THEN -CAST(s.quantity AS SIGNED) ELSE s.quantity_delta END";
    private const OPEN = "'Counting', 'Submitted', 'RecountRequested', 'Approved'";

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $warehouseId, string $scope, ?int $categoryId, array $productIds, array $counterIds, string $reason, int $createdBy): int
    {
        return PersistenceErrors::write(function () use ($warehouseId, $scope, $categoryId, $productIds, $counterIds, $reason, $createdBy): int {
            $statement = $this->pdo->prepare('INSERT INTO stock_counts (warehouse_id, scope, category_id, reason, created_by) VALUES (:warehouse_id, :scope, :category_id, :reason, :created_by)');
            $statement->execute(['warehouse_id' => $warehouseId, 'scope' => $scope, 'category_id' => $categoryId, 'reason' => $reason, 'created_by' => $createdBy]);
            $id = (int) $this->pdo->lastInsertId();
            $this->insertProducts($id, $productIds);
            $this->insertCounters($id, $counterIds, $createdBy);

            return $id;
        });
    }

    public function find(int $id, bool $lock = false): ?array
    {
        if ($lock) {
            $statement = $this->pdo->prepare('SELECT * FROM stock_counts WHERE id = :id FOR UPDATE');
        } else {
            $statement = $this->pdo->prepare(
                'SELECT c.*, w.name AS warehouse_name, cat.name AS category_name, cu.name AS created_by_name, su.name AS started_by_name,
                        bu.name AS submitted_by_name, ru.name AS recount_requested_by_name, au.name AS approved_by_name, pu.name AS posted_by_name, xu.name AS cancelled_by_name
                 FROM stock_counts c INNER JOIN warehouses w ON w.id = c.warehouse_id LEFT JOIN categories cat ON cat.id = c.category_id
                 INNER JOIN users cu ON cu.id = c.created_by LEFT JOIN users su ON su.id = c.started_by LEFT JOIN users bu ON bu.id = c.submitted_by
                 LEFT JOIN users ru ON ru.id = c.recount_requested_by LEFT JOIN users au ON au.id = c.approved_by LEFT JOIN users pu ON pu.id = c.posted_by
                 LEFT JOIN users xu ON xu.id = c.cancelled_by WHERE c.id = :id'
            );
        }
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $counters = $this->pdo->prepare('SELECT user_id FROM stock_count_counters WHERE count_id = :id ORDER BY user_id' . ($lock ? ' FOR UPDATE' : ''));
        $counters->execute(['id' => $id]);
        $row['counters'] = array_map('intval', $counters->fetchAll(PDO::FETCH_COLUMN));
        $products = $this->pdo->prepare('SELECT product_id FROM stock_count_products WHERE count_id = :id ORDER BY product_id' . ($lock ? ' FOR UPDATE' : ''));
        $products->execute(['id' => $id]);
        $row['product_ids'] = array_map('intval', $products->fetchAll(PDO::FETCH_COLUMN));

        return $row;
    }

    public function lines(int $id, bool $lock = false): array
    {
        if ($lock) {
            $statement = $this->pdo->prepare('SELECT * FROM stock_count_lines WHERE count_id = :id ORDER BY product_id, id FOR UPDATE');
        } else {
            $statement = $this->pdo->prepare(
                'SELECT cl.*, p.sku, p.name AS product_name, p.unit AS base_unit, p.tracks_expiry, l.unit_cost AS lot_unit_cost, l.quantity AS lot_quantity,
                        cu.name AS counted_by_name, ru.name AS recounted_by_name
                 FROM stock_count_lines cl INNER JOIN products p ON p.id = cl.product_id LEFT JOIN stock_lots l ON l.id = COALESCE(cl.lot_id, cl.posted_lot_id)
                 LEFT JOIN users cu ON cu.id = cl.counted_by LEFT JOIN users ru ON ru.id = cl.recounted_by
                 WHERE cl.count_id = :id ORDER BY p.sku, cl.expiry_date IS NULL, cl.expiry_date, cl.lot_code, cl.id'
            );
        }
        $statement->execute(['id' => $id]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resolveScope(int $warehouseId, string $scope, ?int $categoryId, array $selected): array
    {
        $sql = 'SELECT p.id FROM products p INNER JOIN product_stocks ps ON ps.product_id = p.id AND ps.warehouse_id = ? WHERE p.is_active = 1';
        $params = [$warehouseId];
        if ($scope === 'Category') {
            $sql .= ' AND p.category_id = ?';
            $params[] = (int) $categoryId;
        } elseif ($scope === 'Products') {
            $selected = array_values(array_unique(array_map('intval', $selected)));
            if ($selected === []) {
                return [];
            }
            $sql .= ' AND p.id IN (' . implode(',', array_fill(0, count($selected), '?')) . ')';
            array_push($params, ...$selected);
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY p.id');
        $statement->execute($params);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function overlapping(int $countId, int $warehouseId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT c.id FROM stock_counts c INNER JOIN stock_count_products sp ON sp.count_id = c.id
             WHERE c.id <> ? AND c.warehouse_id = ? AND c.status IN (' . self::OPEN . ") AND sp.product_id IN ({$placeholders}) ORDER BY c.id"
        );
        $statement->execute([$countId, $warehouseId, ...array_map('intval', $productIds)]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function ledgerWatermark(): int
    {
        $statement = $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM stock_ledger');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    public function lotMovements(array $lotIds, int $afterId, int $upToId): array
    {
        return $this->lotSums($lotIds, 's.id > ? AND s.id <= ?', [$afterId, $upToId], false);
    }

    public function lotBalances(array $lotIds, int $upToId): array
    {
        return $this->lotSums($lotIds, 's.id <= ?', [$upToId], true);
    }

    public function laterCountCorrections(int $countId, array $afterIdByLot): array
    {
        $result = [];
        foreach (array_chunk($afterIdByLot, 200, true) as $chunk) {
            $conditions = [];
            $params = [$countId];
            foreach ($chunk as $lotId => $afterId) {
                $conditions[] = '(s.lot_id = ? AND s.id > ?)';
                $params[] = (int) $lotId;
                $params[] = (int) $afterId;
            }
            $statement = $this->pdo->prepare("SELECT s.lot_id, COUNT(*) FROM stock_ledger s WHERE s.reference_type = 'STOCK_COUNT' AND s.reference_id <> ? AND (" . implode(' OR ', $conditions) . ') GROUP BY s.lot_id');
            $statement->execute($params);
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$lotId, $rows]) {
                $result[(int) $lotId] = (int) $rows;
            }
        }

        return $result;
    }

    public function start(int $id, int $actor, int $watermark, array $productIds, array $lines): void
    {
        PersistenceErrors::write(function () use ($id, $actor, $watermark, $productIds, $lines): void {
            $existing = $this->pdo->prepare('SELECT product_id FROM stock_count_products WHERE count_id = :id');
            $existing->execute(['id' => $id]);
            $known = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
            $this->insertProducts($id, array_values(array_diff($productIds, $known)));
            foreach (array_chunk($lines, 200) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $line) {
                    $values[] = "(?, ?, ?, ?, ?, 'Snapshot', ?)";
                    array_push($params, $id, $line['product_id'], $line['lot_id'], $line['lot_code'], $line['expiry_date'], $line['snapshot_quantity']);
                }
                $this->pdo->prepare('INSERT INTO stock_count_lines (count_id, product_id, lot_id, lot_code, expiry_date, origin, snapshot_quantity) VALUES ' . implode(', ', $values))->execute($params);
            }
            $statement = $this->pdo->prepare("UPDATE stock_counts SET status = 'Counting', started_by = :actor, started_at = CURRENT_TIMESTAMP, snapshot_ledger_id = :watermark WHERE id = :id AND status = 'Draft'");
            $statement->execute(['actor' => $actor, 'watermark' => $watermark, 'id' => $id]);
            $this->assertChanged($statement->rowCount());
        });
    }

    public function addFoundLine(int $id, array $line): int
    {
        return PersistenceErrors::write(function () use ($id, $line): int {
            $statement = $this->pdo->prepare("INSERT INTO stock_count_lines (count_id, product_id, lot_id, lot_code, expiry_date, origin, snapshot_quantity) VALUES (:count_id, :product_id, :lot_id, :lot_code, :expiry_date, 'Found', :snapshot)");
            $statement->execute(['count_id' => $id, 'product_id' => $line['product_id'], 'lot_id' => $line['lot_id'], 'lot_code' => $line['lot_code'], 'expiry_date' => $line['expiry_date'], 'snapshot' => $line['snapshot_quantity']]);

            return (int) $this->pdo->lastInsertId();
        });
    }

    public function recordCount(int $lineId, int $quantity, string $unitCode, int $unitQuantity, int $actor, int $watermark, int $expected): void
    {
        $this->writeCount($lineId, 'counted_quantity = :quantity, counted_unit_code = :unit_code, counted_unit_quantity = :unit_quantity, counted_by = :actor, counted_at = CURRENT_TIMESTAMP, count_ledger_id = :watermark, expected_quantity = :expected', $quantity, $unitCode, $unitQuantity, $actor, $watermark, $expected);
    }

    public function recordRecount(int $lineId, int $quantity, string $unitCode, int $unitQuantity, int $actor, int $watermark, int $expected): void
    {
        $this->writeCount($lineId, 'recounted_quantity = :quantity, recounted_unit_code = :unit_code, recounted_unit_quantity = :unit_quantity, recounted_by = :actor, recounted_at = CURRENT_TIMESTAMP, recount_ledger_id = :watermark, recount_expected_quantity = :expected', $quantity, $unitCode, $unitQuantity, $actor, $watermark, $expected);
    }

    public function resumeCounting(int $id): void
    {
        $this->pdo->prepare("UPDATE stock_counts SET status = 'Counting' WHERE id = :id AND status = 'RecountRequested'")->execute(['id' => $id]);
    }

    public function submitted(int $id, int $actor): void
    {
        $statement = $this->pdo->prepare("UPDATE stock_counts SET status = 'Submitted', submitted_by = :actor, submitted_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'Counting'");
        $statement->execute(['actor' => $actor, 'id' => $id]);
        $this->assertChanged($statement->rowCount());
    }

    public function recountRequested(int $id, int $actor, string $reason, array $lineIds, array $addCounterIds): void
    {
        PersistenceErrors::write(function () use ($id, $actor, $reason, $lineIds, $addCounterIds): void {
            $statement = $this->pdo->prepare("UPDATE stock_counts SET status = 'RecountRequested', recount_round = recount_round + 1, recount_reason = :reason, recount_requested_by = :actor, recount_requested_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'Submitted'");
            $statement->execute(['reason' => $reason, 'actor' => $actor, 'id' => $id]);
            $this->assertChanged($statement->rowCount());
            $placeholders = implode(',', array_fill(0, count($lineIds), '?'));
            $this->pdo->prepare("UPDATE stock_count_lines SET recount_requested = (SELECT recount_round FROM stock_counts WHERE id = ?) WHERE count_id = ? AND id IN ({$placeholders}) AND recount_requested = 0")
                ->execute([$id, $id, ...array_map('intval', $lineIds)]);
            $this->insertCounters($id, $addCounterIds, $actor);
        });
    }

    public function approved(int $id, int $actor, ?string $note): void
    {
        PersistenceErrors::write(function () use ($id, $actor, $note): void {
            $statement = $this->pdo->prepare("UPDATE stock_counts SET status = 'Approved', approved_by = :actor, approved_at = CURRENT_TIMESTAMP, approval_note = :note WHERE id = :id AND status = 'Submitted'");
            $statement->execute(['actor' => $actor, 'note' => $note, 'id' => $id]);
            $this->assertChanged($statement->rowCount());
            $this->pdo->prepare('UPDATE stock_count_lines SET approved_variance = variance WHERE count_id = :id')->execute(['id' => $id]);
        });
    }

    public function posted(int $id, int $actor, array $posted): void
    {
        PersistenceErrors::write(function () use ($id, $actor, $posted): void {
            $line = $this->pdo->prepare('UPDATE stock_count_lines SET posted_lot_id = :lot_id, posted_delta = :delta, posted_unit_cost = :unit_cost, pair_quantity_after = :after, pair_reserved_at_post = :reserved WHERE id = :id AND count_id = :count_id');
            foreach ($posted as $lineId => $result) {
                $line->execute(['lot_id' => $result['lot_id'], 'delta' => $result['delta'], 'unit_cost' => $result['unit_cost'], 'after' => $result['pair_quantity_after'], 'reserved' => $result['pair_reserved'], 'id' => $lineId, 'count_id' => $id]);
            }
            $statement = $this->pdo->prepare("UPDATE stock_counts SET status = 'Posted', posted_by = :actor, posted_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'Approved'");
            $statement->execute(['actor' => $actor, 'id' => $id]);
            $this->assertChanged($statement->rowCount());
        });
    }

    public function cancelled(int $id, int $actor, string $reason): void
    {
        $statement = $this->pdo->prepare("UPDATE stock_counts SET status = 'Cancelled', cancelled_by = :actor, cancelled_at = CURRENT_TIMESTAMP, cancel_reason = :reason WHERE id = :id AND status NOT IN ('Posted', 'Cancelled')");
        $statement->execute(['actor' => $actor, 'reason' => $reason, 'id' => $id]);
        $this->assertChanged($statement->rowCount());
    }

    public function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->filters($filters);
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.warehouse_id, c.scope, c.category_id, c.status, c.reason, c.created_at, c.started_at, c.submitted_at, c.approved_at, c.posted_at, c.recount_round,
                    w.name AS warehouse_name, cat.name AS category_name, u.name AS created_by_name,
                    (SELECT COUNT(*) FROM stock_count_lines cl WHERE cl.count_id = c.id) AS line_count,
                    (SELECT COUNT(*) FROM stock_count_lines cl WHERE cl.count_id = c.id AND cl.counted_quantity IS NOT NULL) AS counted_lines,
                    (SELECT GROUP_CONCAT(cu.name ORDER BY cu.name SEPARATOR \', \') FROM stock_count_counters cc INNER JOIN users cu ON cu.id = cc.user_id WHERE cc.count_id = c.id) AS counter_names
             FROM stock_counts c INNER JOIN warehouses w ON w.id = c.warehouse_id LEFT JOIN categories cat ON cat.id = c.category_id INNER JOIN users u ON u.id = c.created_by'
            . $where . ' ORDER BY c.created_at DESC, c.id DESC LIMIT :row_limit OFFSET :row_offset'
        );
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':row_offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchCount(array $filters): int
    {
        [$where, $params] = $this->filters($filters);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM stock_counts c' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function postedLines(array $filters): array
    {
        [$where, $params] = $this->reportWhere($filters);
        $statement = $this->pdo->prepare(
            'SELECT c.id AS count_id, c.posted_at, w.name AS warehouse_name, p.sku, p.name AS product_name, p.unit AS base_unit, cl.lot_code, cl.expiry_date, cl.origin,
                    cl.snapshot_quantity, COALESCE(cl.recount_expected_quantity, cl.expected_quantity) AS expected_quantity,
                    COALESCE(cl.recounted_quantity, cl.counted_quantity) AS counted_quantity, cl.recounted_quantity IS NOT NULL AS recounted,
                    cl.posted_delta AS variance, cl.posted_unit_cost AS unit_cost, cl.posted_lot_id AS lot_id
             FROM stock_count_lines cl INNER JOIN stock_counts c ON c.id = cl.count_id INNER JOIN warehouses w ON w.id = c.warehouse_id INNER JOIN products p ON p.id = cl.product_id
             WHERE c.status = \'Posted\' AND cl.posted_delta <> 0' . $where . ' ORDER BY c.posted_at DESC, c.id DESC, p.sku, cl.lot_code, cl.id'
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function history(array $filters): array
    {
        [$where, $params] = $this->reportWhere($filters);
        $statement = $this->pdo->prepare(
            "SELECT c.id, c.status, c.scope, c.created_at, c.posted_at, c.recount_round, w.name AS warehouse_name, cat.name AS category_name,
                    COUNT(cl.id) AS line_count,
                    SUM(cl.posted_delta <> 0) AS variance_lines,
                    SUM(cl.recounted_quantity IS NOT NULL) AS recounted_lines,
                    SUM(cl.origin = 'Found' AND cl.lot_id IS NULL) AS found_lots,
                    COALESCE(SUM(GREATEST(cl.posted_delta, 0)), 0) AS units_gained,
                    COALESCE(SUM(GREATEST(-cl.posted_delta, 0)), 0) AS units_lost,
                    COALESCE(SUM(cl.posted_delta), 0) AS net_units,
                    COALESCE(SUM(cl.posted_delta * cl.posted_unit_cost), 0) AS net_value,
                    COALESCE(SUM(ABS(cl.posted_delta) * cl.posted_unit_cost), 0) AS absolute_value
             FROM stock_counts c INNER JOIN warehouses w ON w.id = c.warehouse_id LEFT JOIN categories cat ON cat.id = c.category_id
             LEFT JOIN stock_count_lines cl ON cl.count_id = c.id
             WHERE c.status = 'Posted'" . $where . ' GROUP BY c.id ORDER BY c.posted_at DESC, c.id DESC'
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array{warehouse_id?: int, from?: string, to?: string} $filters @return array{0: string, 1: list<int|string>} */
    private function reportWhere(array $filters): array
    {
        $where = '';
        $params = [];
        if (($filters['warehouse_id'] ?? 0) > 0) {
            $where .= ' AND c.warehouse_id = ?';
            $params[] = (int) $filters['warehouse_id'];
        }
        if (($filters['from'] ?? '') !== '') {
            $where .= ' AND c.posted_at >= ?';
            $params[] = (string) $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where .= ' AND c.posted_at < ? + INTERVAL 1 DAY';
            $params[] = (string) $filters['to'];
        }

        return [$where, $params];
    }

    /** @param array{status?: string, warehouse_id?: int, counter_id?: int} $filters @return array{0: string, 1: array<string, int|string>} */
    private function filters(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (($filters['status'] ?? '') !== '') {
            $clauses[] = 'c.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (($filters['warehouse_id'] ?? 0) > 0) {
            $clauses[] = 'c.warehouse_id = :warehouse_id';
            $params['warehouse_id'] = (int) $filters['warehouse_id'];
        }
        if (($filters['counter_id'] ?? 0) > 0) {
            $clauses[] = 'EXISTS (SELECT 1 FROM stock_count_counters cc WHERE cc.count_id = c.id AND cc.user_id = :counter_id)';
            $params['counter_id'] = (int) $filters['counter_id'];
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param list<int> $lotIds @param list<int> $bounds @return array<int, int> */
    private function lotSums(array $lotIds, string $range, array $bounds, bool $withOpening): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $lotIds))), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare('SELECT s.lot_id, COALESCE(SUM(' . self::SIGNED . "), 0) FROM stock_ledger s WHERE s.lot_id IN ({$placeholders}) AND {$range} GROUP BY s.lot_id");
            $statement->execute([...$chunk, ...$bounds]);
            foreach ($chunk as $lotId) {
                $result[$lotId] = 0;
            }
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$lotId, $sum]) {
                $result[(int) $lotId] = (int) $sum;
            }
            if ($withOpening) {
                $opening = $this->pdo->prepare("SELECT id, opening_quantity FROM stock_lots WHERE id IN ({$placeholders})");
                $opening->execute($chunk);
                foreach ($opening->fetchAll(PDO::FETCH_NUM) as [$lotId, $quantity]) {
                    $result[(int) $lotId] += (int) $quantity;
                }
            }
        }

        return $result;
    }

    /** @param list<int> $productIds */
    private function insertProducts(int $id, array $productIds): void
    {
        $statement = $this->pdo->prepare('INSERT INTO stock_count_products (count_id, product_id) VALUES (:count_id, :product_id)');
        foreach ($productIds as $productId) {
            $statement->execute(['count_id' => $id, 'product_id' => $productId]);
        }
    }

    /** @param list<int> $userIds */
    private function insertCounters(int $id, array $userIds, int $assignedBy): void
    {
        $statement = $this->pdo->prepare('INSERT IGNORE INTO stock_count_counters (count_id, user_id, assigned_by) VALUES (:count_id, :user_id, :assigned_by)');
        foreach ($userIds as $userId) {
            $statement->execute(['count_id' => $id, 'user_id' => $userId, 'assigned_by' => $assignedBy]);
        }
    }

    private function writeCount(int $lineId, string $assignments, int $quantity, string $unitCode, int $unitQuantity, int $actor, int $watermark, int $expected): void
    {
        PersistenceErrors::write(function () use ($lineId, $assignments, $quantity, $unitCode, $unitQuantity, $actor, $watermark, $expected): void {
            $statement = $this->pdo->prepare('UPDATE stock_count_lines SET ' . $assignments . ' WHERE id = :id');
            $statement->execute(['quantity' => $quantity, 'unit_code' => $unitCode, 'unit_quantity' => $unitQuantity, 'actor' => $actor, 'watermark' => $watermark, 'expected' => $expected, 'id' => $lineId]);
        });
    }

    private function assertChanged(int $rows): void
    {
        if ($rows !== 1) {
            throw new ValidationException('The count changed meanwhile; reload the page.');
        }
    }
}
