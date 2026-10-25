<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contract\InventoryValuationRepositoryInterface;

/**
 * In-memory valuation read models (D-20, ADR-026) for unit tests: lots, valued ledger rows, transfer lot rows, losses
 * and document lines are supplied as fixtures and filtered/rolled back exactly as the MySQL queries do.
 *
 * Ledger fixture rows: lot_id, warehouse_id, class (movementTotals class), units (signed), cents (signed, null =
 * before the valuation start), created_at (UTC). Transit fixture rows: the inTransit() fields plus dispatched, received,
 * lost (units), out_at, in_at, received_at.
 */
final class InMemoryInventoryValuationRepository implements InventoryValuationRepositoryInterface
{
    /**
     * @param list<array<string, mixed>> $lots
     * @param list<array<string, mixed>> $ledger
     * @param list<array<string, mixed>> $transit
     * @param list<array<string, mixed>> $losses
     * @param list<array<string, mixed>> $marginLines
     * @param list<array<string, mixed>> $notInvoiced
     * @param list<array<string, mixed>> $documentLines
     */
    public function __construct(
        private readonly array $lots = [],
        private readonly array $ledger = [],
        private readonly array $transit = [],
        private readonly array $losses = [],
        private readonly array $marginLines = [],
        private readonly array $notInvoiced = [],
        private readonly array $documentLines = [],
        private readonly ?string $startedAt = null,
        private readonly string $today = '2026-10-12',
        private readonly int $unvaluedTransitUnits = 0,
    ) {
    }

    public function today(): string
    {
        return $this->today;
    }

    public function valuationStart(): array
    {
        return ['watermark' => $this->startedAt === null ? 0 : 1, 'started_at' => $this->startedAt];
    }

    public function lots(?string $atUtc, array $filters): array
    {
        $rows = [];
        foreach ($this->lots as $lot) {
            if (!$this->matches($lot, $filters, 'warehouse_id', 'lot_code') || ($atUtc === null && (int) $lot['quantity'] <= 0)) {
                continue;
            }
            $units = $cents = 0;
            foreach ($atUtc === null ? [] : $this->ledger as $row) {
                if ((int) $row['lot_id'] === (int) $lot['lot_id'] && $row['cents'] !== null && (string) $row['created_at'] >= $atUtc) {
                    $units += (int) $row['units'];
                    $cents += (int) $row['cents'];
                }
            }
            $rows[] = $lot + ['opening_quantity' => 0, 'base_unit' => 'pcs', 'units_after' => $units, 'cents_after' => $cents];
        }

        return $rows;
    }

    public function inTransit(?string $atUtc, array $filters): array
    {
        $at = $atUtc ?? '2999-12-31 23:59:59';
        $rows = [];
        foreach ($this->transit as $row) {
            if (($filters['warehouse_id'] ?? 0) > 0 && !in_array((int) $filters['warehouse_id'], [(int) $row['source_warehouse_id'], (int) $row['destination_warehouse_id']], true)) {
                continue;
            }
            if (!$this->matches($row, $filters, null, 'lot_code')) {
                continue;
            }
            $units = ((string) $row['out_at'] < $at ? (int) $row['dispatched'] : 0)
                - ($row['in_at'] !== null && (string) $row['in_at'] < $at ? (int) $row['received'] : 0)
                - ($row['received_at'] !== null && (string) $row['received_at'] < $at ? (int) $row['lost'] : 0);
            if ($units !== 0) {
                $rows[] = array_diff_key($row, array_flip(['dispatched', 'received', 'lost', 'out_at', 'in_at', 'received_at'])) + ['units' => $units, 'dispatched_at' => $row['out_at']];
            }
        }

        return $rows;
    }

    public function unvaluedInTransitUnits(?string $atUtc): int
    {
        return $this->unvaluedTransitUnits;
    }

    public function movementTotals(string $fromUtc, string $toUtc): array
    {
        $totals = [];
        foreach ($this->ledger as $row) {
            if ($row['cents'] === null || (string) $row['created_at'] < $fromUtc || (string) $row['created_at'] >= $toUtc) {
                continue;
            }
            $key = $row['warehouse_id'] . '|' . $row['class'];
            $totals[$key] ??= ['warehouse_id' => (int) $row['warehouse_id'], 'class' => (string) $row['class'], 'units' => 0, 'cents' => 0];
            $totals[$key]['units'] += (int) $row['units'];
            $totals[$key]['cents'] += (int) $row['cents'];
        }

        return array_values($totals);
    }

    public function unvaluedMovements(string $fromUtc, string $toUtc): int
    {
        return count(array_filter($this->ledger, static fn (array $row): bool => $row['cents'] === null && (string) $row['created_at'] >= $fromUtc && (string) $row['created_at'] < $toUtc));
    }

    public function losses(string $fromUtc, string $toUtc): array
    {
        return array_values(array_filter($this->losses, static fn (array $row): bool => (string) $row['occurred_at'] >= $fromUtc && (string) $row['occurred_at'] < $toUtc));
    }

    public function marginLines(string $fromDate, string $toDate, array $filters): array
    {
        return array_values(array_filter($this->marginLines, fn (array $row): bool => (string) $row['invoice_date'] >= $fromDate && (string) $row['invoice_date'] <= $toDate
            && (($filters['customer_id'] ?? 0) === 0 || (int) $row['customer_id'] === (int) $filters['customer_id']) && $this->matches($row, ['q' => $filters['q'] ?? ''], null, null)));
    }

    public function shippedNotInvoiced(string $fromUtc, string $toUtc, array $filters): array
    {
        return array_values(array_filter($this->notInvoiced, static fn (array $row): bool => (string) $row['shipped_at'] >= $fromUtc && (string) $row['shipped_at'] < $toUtc
            && (($filters['customer_id'] ?? 0) === 0 || (int) $row['customer_id'] === (int) $filters['customer_id'])));
    }

    public function deliveryNoteIdsForOrder(int $orderId): array
    {
        $ids = [];
        foreach ($this->documentLines as $line) {
            if ((int) $line['sales_order_id'] === $orderId) {
                $ids[(int) $line['delivery_note_id']] = (int) $line['delivery_note_id'];
            }
        }

        return array_values($ids);
    }

    public function documentLines(array $deliveryNoteIds): array
    {
        return array_values(array_filter($this->documentLines, static fn (array $line): bool => in_array((int) $line['delivery_note_id'], $deliveryNoteIds, true)));
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $filters */
    private function matches(array $row, array $filters, ?string $warehouseKey, ?string $lotKey): bool
    {
        if ($warehouseKey !== null && ($filters['warehouse_id'] ?? 0) > 0 && (int) $row[$warehouseKey] !== (int) $filters['warehouse_id']) {
            return false;
        }
        if (($filters['category_id'] ?? 0) > 0 && (int) ($row['category_id'] ?? 0) !== (int) $filters['category_id']) {
            return false;
        }
        $q = strtolower(trim((string) ($filters['q'] ?? '')));
        if ($q === '') {
            return true;
        }
        $haystack = strtolower((string) ($row['sku'] ?? '') . ' ' . (string) ($row['product_name'] ?? '') . ' ' . ($lotKey === null ? '' : (string) ($row[$lotKey] ?? '')));

        return str_contains($haystack, $q);
    }
}
