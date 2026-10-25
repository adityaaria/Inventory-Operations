<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Support\PaginatedResult;

/**
 * Delivery notes (surat jalan) per SO shipment (D-19, ADR-025).
 *
 * Header rows: id, delivery_number, sales_order_id, order_number, sales_created_by, shipment_number, warehouse_id,
 * warehouse_name, customer_id, ship_to_name, ship_to_address, vehicle, driver, prepared_by, prepared_by_name, shipped_at,
 * origin, units (Σ base quantity), invoice_id and invoice_number (the live, non-void invoice, if any).
 * Lines: id, sales_order_item_id, product_id, sku, product_name, unit_code, unit_factor, unit_quantity, quantity,
 * base_unit, lots (lot_id, lot_code, expiry_date, quantity, ledger_id).
 */
interface DeliveryNoteRepositoryInterface
{
    /** Next shipment number of the order (existing notes + 1); the caller holds the order lock. */
    public function nextShipmentNumber(int $salesOrderId): int;

    /**
     * Inserts the note, its lines and lot rows inside the caller's transaction. A lot row without `ledger_id` is linked
     * to the matching Issue ledger row of this shipment that is not yet on any note (MySQL); `ledger_id` UNIQUE.
     *
     * @param array{delivery_number: string, sales_order_id: int, shipment_number: int, warehouse_id: int, customer_id: int, ship_to_name: string, ship_to_address: string, vehicle: ?string, driver: ?string, prepared_by: int, shipped_at?: ?string, origin?: string} $note
     * @param list<array{sales_order_item_id: int, product_id: int, unit_code: string, unit_factor: int, unit_quantity: int, quantity: int, lots: list<array{lot_id: ?int, lot_code: ?string, expiry_date: ?string, quantity: int, ledger_id?: int}>}> $lines
     */
    public function create(array $note, array $lines): int;

    /** @return array<string, mixed>|null header + `lines` */
    public function find(int $id): ?array;

    /** Header locked FOR UPDATE inside the caller's transaction (invoice creation). @return array<string, mixed>|null */
    public function lock(int $id): ?array;

    /** @return list<array<string, mixed>> headers of the order's notes by shipment number */
    public function forSalesOrder(int $salesOrderId): array;

    /**
     * @param array{q?: string, invoiced?: string} $filters invoiced: 'yes' | 'no' | ''
     * @return PaginatedResult<array<string, mixed>>
     */
    public function search(array $filters, int $page, int $perPage, ?int $salesCreatedBy = null): PaginatedResult;
}
