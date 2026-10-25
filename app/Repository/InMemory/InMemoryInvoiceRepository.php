<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Exception\PersistenceException;
use App\Exception\ValidationException;
use App\Repository\Contract\InvoiceRepositoryInterface;
use App\Repository\Contract\SalesOrderRepositoryInterface;
use App\Support\BusinessDate;
use App\Support\PaginatedResult;
use App\Support\TaxSettings;

/** Invoices for unit tests (D-19, ADR-025); mirrors the MySQL guards (one live invoice per note, paid ≤ total, void without payment). */
final class InMemoryInvoiceRepository implements InvoiceRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $invoices = [];
    private int $nextId = 1;
    private int $nextLine = 1;
    private int $nextPayment = 1;

    public function __construct(private readonly ?SalesOrderRepositoryInterface $orders = null, private ?string $today = null)
    {
    }

    public function setToday(string $today): void
    {
        $this->today = $today;
    }

    public function activeForDeliveryNote(int $deliveryNoteId): ?array
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice['delivery_note_id'] === $deliveryNoteId && $invoice['voided_at'] === null) {
                return ['id' => $invoice['id'], 'invoice_number' => $invoice['invoice_number']];
            }
        }

        return null;
    }

    public function create(array $invoice, array $lines): int
    {
        if ($this->activeForDeliveryNote((int) $invoice['delivery_note_id']) !== null) {
            throw new ValidationException('A record with that unique value already exists.');
        }
        $totals = TaxSettings::compute(TaxSettings::cents((string) $invoice['subtotal']), (int) $invoice['vat_rate_percent'], (int) $invoice['vat_dpp_numerator'], (int) $invoice['vat_dpp_denominator']);
        if (TaxSettings::cents((string) $invoice['total_amount']) !== $totals['total_cents'] || TaxSettings::cents((string) $invoice['vat_amount']) !== $totals['vat_cents']) {
            throw new ValidationException('A value violates a required data constraint.');
        }
        $id = $this->nextId++;
        $order = $this->orders?->findById((int) $invoice['sales_order_id']);
        $this->invoices[$id] = $invoice + [
            'id' => $id, 'delivery_number' => 'DN #' . $invoice['delivery_note_id'], 'order_number' => $order?->orderNumber() ?? ('SO #' . $invoice['sales_order_id']),
            'sales_created_by' => $order?->createdBy() ?? 0, 'paid_amount' => '0.00', 'created_by_name' => 'User #' . $invoice['created_by'], 'created_at' => date('Y-m-d H:i:s'),
            'voided_by' => null, 'voided_at' => null, 'void_reason' => null, 'payments' => [],
            'lines' => array_map(fn (array $line): array => ['id' => $this->nextLine++, 'sku' => '#' . $line['product_id'], 'product_name' => ''] + $line, $lines),
        ];

        return $id;
    }

    public function find(int $id): ?array
    {
        return $this->invoices[$id] ?? null;
    }

    public function lock(int $id): ?array
    {
        return $this->find($id);
    }

    public function addPayment(int $invoiceId, string $paidOn, string $amount, string $method, string $reference, int $recordedBy): int
    {
        $invoice = $this->invoices[$invoiceId] ?? throw new PersistenceException('Invoice not found.');
        $paid = TaxSettings::cents((string) $invoice['paid_amount']) + TaxSettings::cents($amount);
        if ($invoice['voided_at'] !== null || $paid > TaxSettings::cents((string) $invoice['total_amount'])) {
            throw new PersistenceException('The payment could not be applied to the invoice.');
        }
        $id = $this->nextPayment++;
        $this->invoices[$invoiceId]['paid_amount'] = TaxSettings::decimal($paid);
        $this->invoices[$invoiceId]['payments'][] = ['id' => $id, 'paid_on' => $paidOn, 'amount' => $amount, 'method' => $method, 'reference' => $reference,
            'recorded_by' => $recordedBy, 'recorded_by_name' => 'User #' . $recordedBy, 'created_at' => date('Y-m-d H:i:s')];

        return $id;
    }

    public function void(int $invoiceId, int $voidedBy, string $reason): void
    {
        $invoice = $this->invoices[$invoiceId] ?? throw new PersistenceException('Invoice not found.');
        if ($invoice['voided_at'] !== null || TaxSettings::cents((string) $invoice['paid_amount']) !== 0) {
            throw new PersistenceException('The invoice could not be voided.');
        }
        $this->invoices[$invoiceId]['voided_at'] = date('Y-m-d H:i:s');
        $this->invoices[$invoiceId]['voided_by'] = $voidedBy;
        $this->invoices[$invoiceId]['void_reason'] = $reason;
    }

    public function search(array $filters, int $page, int $perPage, ?int $salesCreatedBy = null): PaginatedResult
    {
        $today = (string) ($filters['today'] ?? $this->today());
        $rows = array_values(array_filter($this->invoices, static function (array $invoice) use ($filters, $salesCreatedBy, $today): bool {
            $state = \App\Support\InvoiceStatus::of($invoice, $today);
            $wanted = (string) ($filters['state'] ?? '');

            return ($salesCreatedBy === null || $invoice['sales_created_by'] === $salesCreatedBy)
                && ($wanted === '' || $state['status'] === $wanted || ($wanted === 'Overdue' && $state['overdue']))
                && (trim((string) ($filters['q'] ?? '')) === '' || stripos($invoice['invoice_number'] . ' ' . $invoice['customer_name'], trim((string) $filters['q'])) !== false);
        }));

        return new PaginatedResult(array_slice(array_reverse($rows), ($page - 1) * $perPage, $perPage), count($rows), $page, $perPage);
    }

    public function openInvoices(): array
    {
        return array_values(array_filter($this->invoices, static fn (array $invoice): bool => $invoice['voided_at'] === null
            && TaxSettings::cents((string) $invoice['paid_amount']) < TaxSettings::cents((string) $invoice['total_amount'])));
    }

    public function salesByCustomer(string $from, string $to): array
    {
        $groups = [];
        foreach ($this->invoices as $invoice) {
            if ($invoice['voided_at'] !== null || $invoice['invoice_date'] < $from || $invoice['invoice_date'] > $to) {
                continue;
            }
            $key = (int) $invoice['customer_id'];
            $groups[$key] ??= ['customer_id' => $key, 'customer_name' => (string) $invoice['customer_name'], 'invoices' => 0, 'net' => 0, 'vat' => 0, 'total' => 0, 'paid' => 0];
            $groups[$key]['invoices']++;
            foreach (['net' => 'subtotal', 'vat' => 'vat_amount', 'total' => 'total_amount', 'paid' => 'paid_amount'] as $target => $source) {
                $groups[$key][$target] += TaxSettings::cents((string) $invoice[$source]);
            }
        }

        return array_values(array_map(static fn (array $group): array => ['net' => TaxSettings::decimal($group['net']), 'vat' => TaxSettings::decimal($group['vat']),
            'total' => TaxSettings::decimal($group['total']), 'paid' => TaxSettings::decimal($group['paid'])] + $group, $groups));
    }

    public function today(): string
    {
        return $this->today ?? BusinessDate::today();
    }
}
