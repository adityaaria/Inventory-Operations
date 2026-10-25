<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\Product;
use App\Entity\StockLedgerEntry;
use App\Exception\ValidationException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\StockCountRepositoryInterface;

/**
 * In-memory stock count sessions (D-18, ADR-024) for unit tests. Ledger reads come from the in-memory ledger and lots
 * from the in-memory stock repository; the generated `variance` column and the CHECK rules of the MySQL table are
 * mirrored here.
 */
final class InMemoryStockCountRepository implements StockCountRepositoryInterface
{
    private const OPEN = ['Counting', 'Submitted', 'RecountRequested', 'Approved'];

    /** @var array<int, array<string, mixed>> */
    private array $counts = [];
    /** @var array<int, array<string, mixed>> */
    private array $lines = [];
    private int $nextCount = 1;
    private int $nextLine = 1;

    public function __construct(
        private readonly InMemoryStockLedgerRepository $ledger,
        private readonly InMemoryStockRepository $stock,
        private readonly ProductRepositoryInterface $products,
    ) {
    }

    public function create(int $warehouseId, string $scope, ?int $categoryId, array $productIds, array $counterIds, string $reason, int $createdBy): int
    {
        $id = $this->nextCount++;
        $this->counts[$id] = [
            'id' => $id, 'warehouse_id' => $warehouseId, 'warehouse_name' => 'Warehouse #' . $warehouseId, 'scope' => $scope, 'category_id' => $categoryId, 'status' => 'Draft',
            'reason' => $reason, 'created_by' => $createdBy, 'created_at' => $this->now(), 'started_by' => null, 'started_at' => null, 'snapshot_ledger_id' => null,
            'submitted_by' => null, 'submitted_at' => null, 'recount_reason' => null, 'recount_requested_by' => null, 'recount_requested_at' => null, 'recount_round' => 0,
            'approved_by' => null, 'approved_at' => null, 'approval_note' => null, 'posted_by' => null, 'posted_at' => null,
            'cancelled_by' => null, 'cancelled_at' => null, 'cancel_reason' => null,
            'counters' => array_values(array_unique(array_map('intval', $counterIds))), 'product_ids' => array_values(array_unique(array_map('intval', $productIds))),
        ];

        return $id;
    }

    public function find(int $id, bool $lock = false): ?array
    {
        if (!isset($this->counts[$id])) {
            return null;
        }
        $count = $this->counts[$id];
        sort($count['counters']);
        sort($count['product_ids']);

        return $count;
    }

    public function lines(int $id, bool $lock = false): array
    {
        $lines = array_values(array_filter($this->lines, static fn (array $line): bool => $line['count_id'] === $id));
        usort($lines, static fn (array $a, array $b): int => [$a['product_id'], $a['id']] <=> [$b['product_id'], $b['id']]);

        return array_map(fn (array $line): array => $this->decorate($line), $lines);
    }

    public function resolveScope(int $warehouseId, string $scope, ?int $categoryId, array $selected): array
    {
        $selected = array_map('intval', $selected);
        $ids = array_map(static fn (Product $product): int => $product->id(), array_filter(
            $this->products->active(),
            static fn (Product $product): bool => match ($scope) {
                'Category' => $product->categoryId() === $categoryId,
                'Products' => in_array($product->id(), $selected, true),
                default => true,
            },
        ));
        sort($ids);

        return $ids;
    }

    public function overlapping(int $countId, int $warehouseId, array $productIds): array
    {
        $ids = [];
        foreach ($this->counts as $count) {
            if ($count['id'] !== $countId && $count['warehouse_id'] === $warehouseId && in_array($count['status'], self::OPEN, true) && array_intersect($count['product_ids'], $productIds) !== []) {
                $ids[] = (int) $count['id'];
            }
        }

        return $ids;
    }

    public function ledgerWatermark(): int
    {
        $entries = $this->ledger->entries();

        return $entries === [] ? 0 : max(array_map(static fn (StockLedgerEntry $entry): int => $entry->id(), $entries));
    }

    public function lotMovements(array $lotIds, int $afterId, int $upToId): array
    {
        return $this->sums($lotIds, static fn (StockLedgerEntry $entry): bool => $entry->id() > $afterId && $entry->id() <= $upToId);
    }

    public function lotBalances(array $lotIds, int $upToId): array
    {
        $sums = $this->sums($lotIds, static fn (StockLedgerEntry $entry): bool => $entry->id() <= $upToId);
        foreach ($sums as $lotId => $sum) {
            $sums[$lotId] = $sum + ($this->stock->findLot($lotId)?->openingQuantity() ?? 0);
        }

        return $sums;
    }

    public function laterCountCorrections(int $countId, array $afterIdByLot): array
    {
        $result = [];
        foreach ($this->ledger->entries() as $entry) {
            $lotId = $entry->lotId();
            if ($lotId !== null && isset($afterIdByLot[$lotId]) && $entry->referenceType() === 'STOCK_COUNT' && $entry->referenceId() !== $countId && $entry->id() > $afterIdByLot[$lotId]) {
                $result[$lotId] = ($result[$lotId] ?? 0) + 1;
            }
        }

        return $result;
    }

    public function start(int $id, int $actor, int $watermark, array $productIds, array $lines): void
    {
        if (($this->counts[$id]['status'] ?? '') !== 'Draft') {
            throw new ValidationException('The count changed meanwhile; reload the page.');
        }
        $count = &$this->counts[$id];
        $count['product_ids'] = array_values(array_unique([...$count['product_ids'], ...$productIds]));
        foreach ($lines as $line) {
            $this->insertLine($id, $line, 'Snapshot');
        }
        $count['status'] = 'Counting';
        $count['started_by'] = $actor;
        $count['started_at'] = $this->now();
        $count['snapshot_ledger_id'] = $watermark;
    }

    public function addFoundLine(int $id, array $line): int
    {
        return $this->insertLine($id, $line, 'Found');
    }

    public function recordCount(int $lineId, int $quantity, string $unitCode, int $unitQuantity, int $actor, int $watermark, int $expected): void
    {
        $this->lines[$lineId] = ['counted_quantity' => $quantity, 'counted_unit_code' => $unitCode, 'counted_unit_quantity' => $unitQuantity, 'counted_by' => $actor, 'counted_at' => $this->now(), 'count_ledger_id' => $watermark, 'expected_quantity' => $expected] + $this->lines[$lineId];
    }

    public function recordRecount(int $lineId, int $quantity, string $unitCode, int $unitQuantity, int $actor, int $watermark, int $expected): void
    {
        $line = $this->lines[$lineId];
        if ($line['counted_by'] === $actor || $line['recount_requested'] < 1) {
            throw new ValidationException('A value violates a required data constraint.'); // chk_stock_count_lines_recounter / _recount
        }
        $this->lines[$lineId] = ['recounted_quantity' => $quantity, 'recounted_unit_code' => $unitCode, 'recounted_unit_quantity' => $unitQuantity, 'recounted_by' => $actor, 'recounted_at' => $this->now(), 'recount_ledger_id' => $watermark, 'recount_expected_quantity' => $expected] + $line;
    }

    public function resumeCounting(int $id): void
    {
        if ($this->counts[$id]['status'] === 'RecountRequested') {
            $this->counts[$id]['status'] = 'Counting';
        }
    }

    public function submitted(int $id, int $actor): void
    {
        $this->transition($id, ['Counting'], ['status' => 'Submitted', 'submitted_by' => $actor, 'submitted_at' => $this->now()]);
    }

    public function recountRequested(int $id, int $actor, string $reason, array $lineIds, array $addCounterIds): void
    {
        $round = (int) $this->counts[$id]['recount_round'] + 1;
        $this->transition($id, ['Submitted'], ['status' => 'RecountRequested', 'recount_round' => $round, 'recount_reason' => $reason, 'recount_requested_by' => $actor, 'recount_requested_at' => $this->now()]);
        foreach ($lineIds as $lineId) {
            if (isset($this->lines[$lineId]) && $this->lines[$lineId]['count_id'] === $id && $this->lines[$lineId]['recount_requested'] === 0) {
                $this->lines[$lineId]['recount_requested'] = $round;
            }
        }
        $this->counts[$id]['counters'] = array_values(array_unique([...$this->counts[$id]['counters'], ...array_map('intval', $addCounterIds)]));
    }

    public function approved(int $id, int $actor, ?string $note): void
    {
        $this->transition($id, ['Submitted'], ['status' => 'Approved', 'approved_by' => $actor, 'approved_at' => $this->now(), 'approval_note' => $note]);
        foreach ($this->lines as $lineId => $line) {
            if ($line['count_id'] === $id) {
                $this->lines[$lineId]['approved_variance'] = self::variance($line);
            }
        }
    }

    public function posted(int $id, int $actor, array $posted): void
    {
        foreach ($posted as $lineId => $result) {
            $line = $this->lines[$lineId];
            if ($result['delta'] !== $line['approved_variance'] || $result['pair_quantity_after'] < $result['pair_reserved']) {
                throw new ValidationException('A value violates a required data constraint.'); // chk_stock_count_lines_posted / _reservation
            }
            $this->lines[$lineId] = ['posted_lot_id' => $result['lot_id'], 'posted_delta' => $result['delta'], 'posted_unit_cost' => $result['unit_cost'], 'pair_quantity_after' => $result['pair_quantity_after'], 'pair_reserved_at_post' => $result['pair_reserved']] + $line;
        }
        $this->transition($id, ['Approved'], ['status' => 'Posted', 'posted_by' => $actor, 'posted_at' => $this->now()]);
    }

    public function cancelled(int $id, int $actor, string $reason): void
    {
        $this->transition($id, ['Draft', 'Counting', 'Submitted', 'RecountRequested', 'Approved'], ['status' => 'Cancelled', 'cancelled_by' => $actor, 'cancelled_at' => $this->now(), 'cancel_reason' => $reason]);
    }

    public function search(array $filters, int $limit, int $offset): array
    {
        $rows = array_values(array_filter($this->counts, static fn (array $count): bool => (($filters['status'] ?? '') === '' || $count['status'] === $filters['status'])
            && (($filters['warehouse_id'] ?? 0) === 0 || $count['warehouse_id'] === $filters['warehouse_id'])
            && (($filters['counter_id'] ?? 0) === 0 || in_array($filters['counter_id'], $count['counters'], true))));
        usort($rows, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        foreach ($rows as &$row) {
            $lines = $this->lines((int) $row['id']);
            $row['line_count'] = count($lines);
            $row['counted_lines'] = count(array_filter($lines, static fn (array $line): bool => $line['counted_quantity'] !== null));
            $row['counter_names'] = implode(', ', array_map(static fn (int $user): string => 'User #' . $user, $row['counters']));
            $row['created_by_name'] = 'User #' . $row['created_by'];
            $row['category_name'] = null;
        }
        unset($row);

        return array_slice($rows, $offset, $limit);
    }

    public function searchCount(array $filters): int
    {
        return count($this->search($filters, PHP_INT_MAX, 0));
    }

    public function postedLines(array $filters): array
    {
        $rows = [];
        foreach ($this->counts as $count) {
            if ($count['status'] !== 'Posted' || (($filters['warehouse_id'] ?? 0) > 0 && $count['warehouse_id'] !== $filters['warehouse_id'])) {
                continue;
            }
            foreach ($this->lines((int) $count['id']) as $line) {
                if ((int) $line['posted_delta'] === 0) {
                    continue;
                }
                $rows[] = ['count_id' => $count['id'], 'posted_at' => $count['posted_at'], 'warehouse_name' => $count['warehouse_name'], 'sku' => $line['sku'], 'product_name' => $line['product_name'],
                    'base_unit' => $line['base_unit'], 'lot_code' => $line['lot_code'], 'expiry_date' => $line['expiry_date'], 'origin' => $line['origin'], 'snapshot_quantity' => $line['snapshot_quantity'],
                    'expected_quantity' => $line['recount_expected_quantity'] ?? $line['expected_quantity'], 'counted_quantity' => $line['recounted_quantity'] ?? $line['counted_quantity'],
                    'recounted' => $line['recounted_quantity'] !== null ? 1 : 0, 'variance' => $line['posted_delta'], 'unit_cost' => $line['posted_unit_cost'], 'lot_id' => $line['posted_lot_id']];
            }
        }

        return $rows;
    }

    public function history(array $filters): array
    {
        $rows = [];
        foreach ($this->counts as $count) {
            if ($count['status'] !== 'Posted' || (($filters['warehouse_id'] ?? 0) > 0 && $count['warehouse_id'] !== $filters['warehouse_id'])) {
                continue;
            }
            $lines = $this->lines((int) $count['id']);
            $deltas = array_map(static fn (array $line): int => (int) $line['posted_delta'], $lines);
            $rows[] = ['id' => $count['id'], 'status' => $count['status'], 'scope' => $count['scope'], 'created_at' => $count['created_at'], 'posted_at' => $count['posted_at'], 'recount_round' => $count['recount_round'],
                'warehouse_name' => $count['warehouse_name'], 'category_name' => null, 'line_count' => count($lines),
                'variance_lines' => count(array_filter($deltas)), 'recounted_lines' => count(array_filter($lines, static fn (array $line): bool => $line['recounted_quantity'] !== null)),
                'found_lots' => count(array_filter($lines, static fn (array $line): bool => $line['origin'] === 'Found' && $line['lot_id'] === null)),
                'units_gained' => array_sum(array_map(static fn (int $delta): int => max(0, $delta), $deltas)), 'units_lost' => array_sum(array_map(static fn (int $delta): int => max(0, -$delta), $deltas)),
                'net_units' => array_sum($deltas),
                'net_value' => array_sum(array_map(static fn (array $line): float => (int) $line['posted_delta'] * (float) ($line['posted_unit_cost'] ?? 0), $lines)),
                'absolute_value' => array_sum(array_map(static fn (array $line): float => abs((int) $line['posted_delta']) * (float) ($line['posted_unit_cost'] ?? 0), $lines))];
        }

        return $rows;
    }

    /** @param array{product_id: int, lot_id: int|null, lot_code: string, expiry_date: string|null, snapshot_quantity: int} $line */
    private function insertLine(int $countId, array $line, string $origin): int
    {
        $key = $line['lot_id'] === null ? 'F:' . strtoupper($line['lot_code']) . '|' . ($line['expiry_date'] ?? '-') : 'L:' . $line['lot_id'];
        foreach ($this->lines as $existing) {
            if ($existing['count_id'] === $countId && $existing['product_id'] === $line['product_id'] && $existing['line_key'] === $key) {
                throw new ValidationException('A record with that unique value already exists.');
            }
        }
        $id = $this->nextLine++;
        $this->lines[$id] = $line + ['id' => $id, 'count_id' => $countId, 'origin' => $origin, 'line_key' => $key,
            'counted_quantity' => null, 'counted_unit_code' => null, 'counted_unit_quantity' => null, 'counted_by' => null, 'counted_at' => null, 'count_ledger_id' => null, 'expected_quantity' => null,
            'recount_requested' => 0, 'recounted_quantity' => null, 'recounted_unit_code' => null, 'recounted_unit_quantity' => null, 'recounted_by' => null, 'recounted_at' => null, 'recount_ledger_id' => null, 'recount_expected_quantity' => null,
            'approved_variance' => null, 'posted_lot_id' => null, 'posted_delta' => null, 'posted_unit_cost' => null, 'pair_quantity_after' => null, 'pair_reserved_at_post' => null];

        return $id;
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    private function decorate(array $line): array
    {
        $product = $this->products->findById((int) $line['product_id']);
        $lot = $this->stock->findLot((int) ($line['lot_id'] ?? $line['posted_lot_id'] ?? 0));

        return $line + [
            'variance' => self::variance($line),
            'sku' => $product?->sku() ?? '', 'product_name' => $product?->name() ?? '', 'base_unit' => $product?->unit() ?? 'unit', 'tracks_expiry' => $product?->tracksExpiry() ? 1 : 0,
            'lot_unit_cost' => $lot?->unitCost(), 'lot_quantity' => $lot?->quantity(), 'counted_by_name' => null, 'recounted_by_name' => null,
        ];
    }

    /** Mirrors the generated column: COALESCE(recount, count) − COALESCE(recount expected, expected); null until counted. @param array<string, mixed> $line */
    private static function variance(array $line): ?int
    {
        $counted = $line['recounted_quantity'] ?? $line['counted_quantity'];
        $expected = $line['recount_expected_quantity'] ?? $line['expected_quantity'];

        return $counted === null || $expected === null ? null : (int) $counted - (int) $expected;
    }

    /** @param list<int> $lotIds @param callable(StockLedgerEntry): bool $range @return array<int, int> */
    private function sums(array $lotIds, callable $range): array
    {
        $sums = array_fill_keys(array_map('intval', $lotIds), 0);
        foreach ($this->ledger->entries() as $entry) {
            $lotId = $entry->lotId();
            if ($lotId !== null && isset($sums[$lotId]) && $range($entry)) {
                $sums[$lotId] += $entry->delta();
            }
        }

        return $sums;
    }

    /** @param list<string> $from @param array<string, mixed> $changes */
    private function transition(int $id, array $from, array $changes): void
    {
        if (!isset($this->counts[$id]) || !in_array($this->counts[$id]['status'], $from, true)) {
            throw new ValidationException('The count changed meanwhile; reload the page.');
        }
        $this->counts[$id] = $changes + $this->counts[$id];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
