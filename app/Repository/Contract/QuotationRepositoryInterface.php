<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Support\PaginatedResult;

/**
 * Quotations (D-19, ADR-025). Header rows: id, quotation_number, customer_id, customer_name, source_warehouse_id,
 * warehouse_name, status (stored), quote_date, valid_until, notes, created_by, created_by_name, sent_at, decided_by,
 * decided_at, rejection_reason, cancelled_by, cancelled_at, created_at, sales_order_id, sales_order_number, total
 * (decimal string) and line_count. Lines: id, product_id, sku, product_name, unit_code, unit_factor, unit_quantity,
 * quantity, unit_price, list_price, price_reason, price_source, price_list_id, price_list_name.
 */
interface QuotationRepositoryInterface
{
    /**
     * @param list<array{product_id: int, quantity: int, unit_code: string, unit_factor: int, unit_quantity: int, selling_price: float, list_price: float, price_reason: string|null, price_source: string, price_list_id: int|null}> $lines
     */
    public function create(string $number, int $customerId, int $warehouseId, int $createdBy, string $quoteDate, string $validUntil, string $notes, array $lines): int;

    /** @return array<string, mixed>|null header + `lines` */
    public function find(int $id): ?array;

    /** Header locked FOR UPDATE inside the caller's transaction. @return array<string, mixed>|null */
    public function lock(int $id): ?array;

    /**
     * @param array{q?: string, status?: string, today: string} $filters status is the effective status (Expired, Converted included)
     * @return PaginatedResult<array<string, mixed>>
     */
    public function search(array $filters, int $page, int $perPage, ?int $createdBy = null): PaginatedResult;

    public function markSent(int $id): void;

    public function markAccepted(int $id, int $decidedBy): void;

    public function markRejected(int $id, int $decidedBy, string $reason): void;

    public function markCancelled(int $id, int $cancelledBy): void;

    /** Business date (APP_TIMEZONE) on the session clock: quote date and validity. */
    public function today(): string;
}
