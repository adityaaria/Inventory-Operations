<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\StockLedgerEntry;

/**
 * Groups a sales order's Issue ledger rows into shipments for the SO detail page (D-14, ADR-020). One issue call writes
 * one Issue row per line in one transaction with the same performer and time, and the order lock serializes issues of
 * one order, so rows are consecutive by id. A new shipment starts when the time or performer changes, or when a product
 * repeats with the same lot (one shipment ships each product lot at most once). Display only: the ledger rows themselves are the record.
 */
final class ShipmentHistory
{
    /**
     * @param list<StockLedgerEntry> $entries
     * @return list<array{number: int, at: string, performed_by: int, units: int, lines: list<StockLedgerEntry>}>
     */
    public static function group(array $entries): array
    {
        $shipments = [];
        $current = null;
        foreach ($entries as $entry) {
            if ($entry->movementType() !== 'Issue') {
                continue;
            }
            $at = $entry->createdAt() ?? '';
            // Since D-17 (ADR-023) one line may ship from several lots: one row per lot. A product only starts a new shipment
            // when it repeats with a lot already seen in the current shipment (or without lots, as before).
            $seen = $current === null ? [] : array_map(static fn (StockLedgerEntry $line): string => $line->productId() . ':' . ($line->lotId() ?? 'none'), $current['lines']);
            if ($current === null || $current['at'] !== $at || $current['performed_by'] !== $entry->performedBy() || in_array($entry->productId() . ':' . ($entry->lotId() ?? 'none'), $seen, true)) {
                if ($current !== null) {
                    $shipments[] = $current;
                }
                $current = ['number' => count($shipments) + 1, 'at' => $at, 'performed_by' => $entry->performedBy(), 'units' => 0, 'lines' => []];
            }
            $current['lines'][] = $entry;
            $current['units'] += $entry->quantity();
        }
        if ($current !== null) {
            $shipments[] = $current;
        }

        return $shipments;
    }
}
