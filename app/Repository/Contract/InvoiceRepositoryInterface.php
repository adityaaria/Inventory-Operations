<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Support\PaginatedResult;

/**
 * Invoices with PPN, their lines and payments (D-19, ADR-025). Amounts are plain decimal strings ("1234.50").
 *
 * Header rows: id, invoice_number, delivery_note_id, delivery_number, sales_order_id, order_number, sales_created_by,
 * customer_id, invoice_date, due_date, customer_name, customer_address, customer_npwp, customer_nik, customer_tax_name,
 * customer_tax_address, subtotal, dpp_amount, vat_amount, total_amount, vat_rate_percent, vat_dpp_numerator,
 * vat_dpp_denominator, paid_amount, created_by, created_by_name, created_at, voided_by, voided_at, void_reason.
 */
interface InvoiceRepositoryInterface
{
    /** The live (non-void) invoice of a delivery note. @return array{id: int, invoice_number: string}|null */
    public function activeForDeliveryNote(int $deliveryNoteId): ?array;

    /**
     * Inserts header and lines inside the caller's transaction.
     *
     * @param array<string, mixed> $invoice header columns (amounts as decimal strings)
     * @param list<array{delivery_note_line_id: int, sales_order_item_id: int, product_id: int, unit_code: string, unit_quantity: int, unit_price: string, amount: string}> $lines
     */
    public function create(array $invoice, array $lines): int;

    /** @return array<string, mixed>|null header + `lines` + `payments` */
    public function find(int $id): ?array;

    /** Header locked FOR UPDATE inside the caller's transaction (payments, void). @return array<string, mixed>|null */
    public function lock(int $id): ?array;

    /** Inserts the payment and raises paid_amount in the caller's transaction (CHECK paid ≤ total is the final guard). */
    public function addPayment(int $invoiceId, string $paidOn, string $amount, string $method, string $reference, int $recordedBy): int;

    public function void(int $invoiceId, int $voidedBy, string $reason): void;

    /**
     * @param array{q?: string, state?: string, today?: string} $filters state: '' | Unpaid | PartiallyPaid | Paid | Void | Overdue
     * @return PaginatedResult<array<string, mixed>>
     */
    public function search(array $filters, int $page, int $perPage, ?int $salesCreatedBy = null): PaginatedResult;

    /** Non-void invoices with an outstanding amount. @return list<array<string, mixed>> */
    public function openInvoices(): array;

    /**
     * Non-void invoices dated $from..$to grouped by customer.
     *
     * @return list<array{customer_id: int, customer_name: string, invoices: int, net: string, vat: string, total: string, paid: string}>
     */
    public function salesByCustomer(string $from, string $to): array;

    /** Business date (APP_TIMEZONE) on the session clock (invoice date, ageing, overdue). */
    public function today(): string;
}
