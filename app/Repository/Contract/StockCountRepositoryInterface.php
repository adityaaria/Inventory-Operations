<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Stock count sessions (D-18, ADR-024): header, assigned counters, scope products and count lines, plus the ledger reads
 * the variance formula needs. Rows are arrays with the column names of stock_counts / stock_count_lines. With $lock the
 * reads are locking reads (FOR UPDATE) so they never fix the REPEATABLE READ view before the stock locks.
 */
interface StockCountRepositoryInterface
{
    /** @param list<int> $productIds selection of a 'Products' count @param list<int> $counterIds */
    public function create(int $warehouseId, string $scope, ?int $categoryId, array $productIds, array $counterIds, string $reason, int $createdBy): int;

    /** Header with `counters` (list<int>) and `product_ids` (list<int>). @return array<string, mixed>|null */
    public function find(int $id, bool $lock = false): ?array;

    /** @return list<array<string, mixed>> lines ordered by product and lot */
    public function lines(int $id, bool $lock = false): array;

    /**
     * Active products of a scope that have a stock row in the warehouse, ascending: every product ('All'), the products of
     * a category ('Category') or the selection ('Products').
     *
     * @param list<int> $selected
     * @return list<int>
     */
    public function resolveScope(int $warehouseId, string $scope, ?int $categoryId, array $selected): array;

    /** Other started, not yet posted or cancelled counts of the warehouse that share a product. @param list<int> $productIds @return list<int> */
    public function overlapping(int $countId, int $warehouseId, array $productIds): array;

    /** Highest stock_ledger id visible now (0 for an empty ledger). */
    public function ledgerWatermark(): int;

    /** Σ signed ledger quantity per lot with $afterId < id ≤ $upToId. @param list<int> $lotIds @return array<int, int> */
    public function lotMovements(array $lotIds, int $afterId, int $upToId): array;

    /** opening + Σ signed ledger quantity per lot with id ≤ $upToId. @param list<int> $lotIds @return array<int, int> */
    public function lotBalances(array $lotIds, int $upToId): array;

    /** STOCK_COUNT ledger rows of other counts on these lots after the given id, per lot id. @param array<int, int> $afterIdByLot @return array<int, int> */
    public function laterCountCorrections(int $countId, array $afterIdByLot): array;

    /**
     * Draft → Counting with the snapshot: scope products and one line per lot with stock.
     *
     * @param list<int> $productIds
     * @param list<array{product_id: int, lot_id: int, lot_code: string, expiry_date: string|null, snapshot_quantity: int}> $lines
     */
    public function start(int $id, int $actor, int $watermark, array $productIds, array $lines): void;

    /** @param array{product_id: int, lot_id: int|null, lot_code: string, expiry_date: string|null, snapshot_quantity: int} $line */
    public function addFoundLine(int $id, array $line): int;

    public function recordCount(int $lineId, int $quantity, string $unitCode, int $unitQuantity, int $actor, int $watermark, int $expected): void;

    public function recordRecount(int $lineId, int $quantity, string $unitCode, int $unitQuantity, int $actor, int $watermark, int $expected): void;

    public function resumeCounting(int $id): void;

    public function submitted(int $id, int $actor): void;

    /** @param list<int> $lineIds @param list<int> $addCounterIds */
    public function recountRequested(int $id, int $actor, string $reason, array $lineIds, array $addCounterIds): void;

    /** Approved; every line's approved_variance = its variance. */
    public function approved(int $id, int $actor, ?string $note): void;

    /** @param array<int, array{lot_id: int|null, delta: int, unit_cost: string|null, pair_quantity_after: int, pair_reserved: int}> $posted */
    public function posted(int $id, int $actor, array $posted): void;

    public function cancelled(int $id, int $actor, string $reason): void;

    /** @param array{status?: string, warehouse_id?: int, counter_id?: int} $filters @return list<array<string, mixed>> */
    public function search(array $filters, int $limit, int $offset): array;

    /** @param array{status?: string, warehouse_id?: int, counter_id?: int} $filters */
    public function searchCount(array $filters): int;

    /**
     * Posted count lines with a variance for the report and CSV (session, warehouse, product, lot, expected, counted,
     * variance, unit cost). @param array{warehouse_id?: int, from?: string, to?: string} $filters @return list<array<string, mixed>>
     */
    public function postedLines(array $filters): array;

    /** Counts per session for the history report. @param array{warehouse_id?: int, from?: string, to?: string} $filters @return list<array<string, mixed>> */
    public function history(array $filters): array;
}
