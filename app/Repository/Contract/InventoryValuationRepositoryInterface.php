<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Read models of the FIFO-per-batch valuation (D-20, ADR-026). Read-only: costs are written only by the stock ledger
 * (StockService). Timestamps are UTC 'Y-m-d H:i:s' (ADR-017); business dates are converted by the service. Amounts
 * from the ledger are integer cents; lot values are computed by the service from quantity × unit cost (CostAmount).
 *
 * Filters accepted where noted: warehouse_id (int > 0), category_id (int > 0), q (SKU or name search), customer_id.
 */
interface InventoryValuationRepositoryInterface
{
    /** Today's business date (APP_TIMEZONE) for default periods. */
    public function today(): string;

    /**
     * The valuation start: ledger rows up to the lot-migration watermark have no lot and no cost. started_at is the
     * time from which every movement is valued (null when the watermark is 0: all history is valued).
     *
     * @return array{watermark: int, started_at: string|null}
     */
    public function valuationStart(): array;

    /**
     * Lots with their current quantity and unit cost; with $atUtc also the signed units and cents of the ledger rows
     * written at or after that moment (so quantity/value at $atUtc = current − after). Without $atUtc only lots with stock.
     *
     * @param array<string, mixed> $filters
     * @return list<array{lot_id: int, lot_code: string, expiry_date: string|null, product_id: int, sku: string, product_name: string, base_unit: string, category_id: int, category_name: string, warehouse_id: int, warehouse_name: string, unit_cost: string, quantity: int, opening_quantity: int, units_after: int, cents_after: int}>
     */
    public function lots(?string $atUtc, array $filters): array;

    /**
     * Transfer lot rows (ADR-021/023) with units in transit at $atUtc (null = now): dispatched once the source row exists,
     * minus received once the destination row exists, minus damaged/short once the transfer was received.
     *
     * @param array<string, mixed> $filters
     * @return list<array{operation_id: int, product_id: int, sku: string, product_name: string, category_id: int, category_name: string, source_warehouse_id: int, source_name: string, destination_warehouse_id: int, destination_name: string, lot_code: string, expiry_date: string|null, unit_cost: string, units: int, dispatched_at: string|null}>
     */
    public function inTransit(?string $atUtc, array $filters): array;

    /** Units in transit at $atUtc (null = now) of transfers dispatched before the valuation start (no lot rows, no cost). */
    public function unvaluedInTransitUnits(?string $atUtc): int;

    /**
     * Valued ledger rows in [$fromUtc, $toUtc) per warehouse and movement class: receipt, issue, transfer_in,
     * transfer_in_unvalued (arrival of a transfer dispatched before the valuation start), transfer_out, adjustment,
     * supplier_return, customer_return, count, write_off, other.
     *
     * @return list<array{warehouse_id: int, class: string, units: int, cents: int}>
     */
    public function movementTotals(string $fromUtc, string $toUtc): array;

    /** Ledger rows in [$fromUtc, $toUtc) without cost (written before the valuation start). */
    public function unvaluedMovements(string $fromUtc, string $toUtc): int;

    /**
     * Loss events in [$fromUtc, $toUtc): expired write-offs and negative count variances (valued ledger rows) and transit
     * damage/shortage (transfer lot rows; no ledger row, ADR-021) with their lot unit cost.
     *
     * @return list<array{kind: string, occurred_at: string, document_type: string, document_id: int, warehouse_id: int, warehouse_name: string, product_id: int, sku: string, product_name: string, lot_code: string, units: int, unit_cost: string, ledger_cents: int|null}>
     */
    public function losses(string $fromUtc, string $toUtc): array;

    /**
     * Lines of non-void invoices dated in [$fromDate, $toDate] with net revenue (excl. PPN) and the COGS of the lots the
     * invoiced delivery-note line shipped (−Σ Issue cost_amount); unvalued_units = units shipped before the valuation start.
     *
     * @param array<string, mixed> $filters customer_id, q
     * @return list<array<string, mixed>>
     */
    public function marginLines(string $fromDate, string $toDate, array $filters): array;

    /**
     * Delivery-note lines shipped in [$fromUtc, $toUtc) without a live invoice, with COGS and the SO line value.
     *
     * @param array<string, mixed> $filters customer_id, q
     * @return list<array<string, mixed>>
     */
    public function shippedNotInvoiced(string $fromUtc, string $toUtc, array $filters): array;

    /** @return list<int> delivery notes of a sales order, in shipment order */
    public function deliveryNoteIdsForOrder(int $orderId): array;

    /**
     * Delivery-note lines with COGS, the lots used (unit cost, cost) and the live invoice line amount, for the costing
     * panels of the SO, delivery note and invoice detail pages.
     *
     * @param list<int> $deliveryNoteIds
     * @return list<array<string, mixed>>
     */
    public function documentLines(array $deliveryNoteIds): array;
}
