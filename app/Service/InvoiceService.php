<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\DeliveryNoteRepositoryInterface;
use App\Repository\Contract\DocumentSequenceRepositoryInterface;
use App\Repository\Contract\InvoiceRepositoryInterface;
use App\Repository\Contract\SalesOrderRepositoryInterface;
use App\Repository\Contract\TransactionManagerInterface;
use App\Security\AuthContext;
use App\Support\BusinessDate;
use App\Support\InvoiceStatus;
use App\Support\OrderNumber;
use App\Support\PaginatedResult;
use App\Support\RequestOrigin;
use App\Support\TaxSettings;

/**
 * Invoices with PPN (D-19, ADR-025): one invoice per delivery note (bill what was shipped), lines = shipped unit quantity
 * × the SO line's effective unit price, totals from TaxSettings (assumptions in ADR-025) with the settings snapshotted.
 * Payments are recorded under the invoice row lock, so concurrent payments never exceed the total; the status is
 * computed (InvoiceStatus). Void (with a reason) only while no payment exists; voiding never touches stock.
 * Access: Admin creates, pays, voids and sees all; Sales sees invoices of their own sales orders; Warehouse 403.
 */
final class InvoiceService
{
    public const PER_PAGE = 20;
    public const METHODS = ['Transfer', 'Cash', 'Giro', 'Other'];
    /** Ageing buckets by days since the invoice date: label => [from, to|null]. */
    public const AGE_BUCKETS = ['0-30' => [0, 30], '31-60' => [31, 60], '61-90' => [61, 90], '>90' => [91, null]];

    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly DeliveryNoteRepositoryInterface $notes,
        private readonly SalesOrderRepositoryInterface $orders,
        private readonly CustomerRepositoryInterface $customers,
        private readonly DocumentSequenceRepositoryInterface $sequences,
        private readonly TransactionManagerInterface $transactions,
        private readonly TaxSettings $tax = new TaxSettings(),
        private readonly ?AuditLogRepositoryInterface $audit = null,
    ) {
    }

    public function taxSettings(): TaxSettings
    {
        return $this->tax;
    }

    /**
     * Admin creates the invoice of an un-invoiced delivery note. One transaction: lock the note (two concurrent creations
     * serialize here and the second sees the first invoice), compute lines and totals, claim INV-YYMM-NNNN, insert. The
     * UNIQUE key on the live delivery note is the final guard.
     */
    public function createFromDeliveryNote(AuthContext $actor, int $deliveryNoteId): int
    {
        $this->assertAdmin($actor);

        return $this->transactions->run(function () use ($actor, $deliveryNoteId): int {
            $note = $this->notes->lock($deliveryNoteId) ?? throw new ValidationException('Delivery note not found.');
            $existing = $this->invoices->activeForDeliveryNote($deliveryNoteId);
            if ($existing !== null) {
                throw new ValidationException('Delivery note ' . $note['delivery_number'] . ' is already invoiced (' . $existing['invoice_number'] . '). Void that invoice first to bill it again.');
            }
            $order = $this->orders->findById((int) $note['sales_order_id']) ?? throw new ValidationException('Sales order not found.');
            $prices = [];
            foreach ($order->items() as $item) {
                $prices[$item->id()] = TaxSettings::cents(number_format($item->sellingPrice(), 2, '.', ''));
            }
            $lines = [];
            $subtotal = 0;
            foreach ($note['lines'] as $line) {
                $price = $prices[(int) $line['sales_order_item_id']] ?? throw new ValidationException('The delivery note names a line that is not on its sales order.');
                $amount = (int) $line['unit_quantity'] * $price;
                $subtotal += $amount;
                $lines[] = [
                    'delivery_note_line_id' => (int) $line['id'],
                    'sales_order_item_id' => (int) $line['sales_order_item_id'],
                    'product_id' => (int) $line['product_id'],
                    'unit_code' => (string) $line['unit_code'],
                    'unit_quantity' => (int) $line['unit_quantity'],
                    'unit_price' => TaxSettings::decimal($price),
                    'amount' => TaxSettings::decimal($amount),
                ];
            }
            if ($lines === []) {
                throw new ValidationException('The delivery note has no lines to invoice.');
            }
            $totals = $this->tax->totals($subtotal);
            $customer = $this->customers->findById((int) $note['customer_id']);
            $today = $this->invoices->today();
            $period = $this->sequences->currentPeriod();
            $number = OrderNumber::format(OrderNumber::INVOICE, $period, $this->sequences->next(OrderNumber::INVOICE, $period));
            $id = $this->invoices->create([
                'invoice_number' => $number,
                'delivery_note_id' => $deliveryNoteId,
                'sales_order_id' => $order->id(),
                'customer_id' => (int) $note['customer_id'],
                'invoice_date' => $today,
                'due_date' => (new \DateTimeImmutable($today))->modify('+' . $this->tax->paymentTermsDays() . ' day')->format('Y-m-d'),
                'customer_name' => $customer?->name() ?? (string) $note['ship_to_name'],
                'customer_address' => $customer?->address() ?? (string) $note['ship_to_address'],
                'customer_npwp' => $customer?->npwp(),
                'customer_nik' => $customer?->nik(),
                'customer_tax_name' => $customer?->taxName(),
                'customer_tax_address' => $customer?->taxAddress(),
                'subtotal' => TaxSettings::decimal($totals['subtotal_cents']),
                'dpp_amount' => TaxSettings::decimal($totals['dpp_cents']),
                'vat_amount' => TaxSettings::decimal($totals['vat_cents']),
                'total_amount' => TaxSettings::decimal($totals['total_cents']),
                'vat_rate_percent' => $this->tax->ratePercent(),
                'vat_dpp_numerator' => $this->tax->dppNumerator(),
                'vat_dpp_denominator' => $this->tax->dppDenominator(),
                'created_by' => $actor->userId(),
            ], $lines);
            $this->audit?->append($actor->userId(), 'invoices.create', 'invoices', $id, 'success', RequestOrigin::none(), [
                'invoice_number' => $number, 'delivery_note_id' => $deliveryNoteId, 'subtotal' => TaxSettings::decimal($totals['subtotal_cents']),
                'vat_amount' => TaxSettings::decimal($totals['vat_cents']), 'total_amount' => TaxSettings::decimal($totals['total_cents']),
                'vat_rate_percent' => $this->tax->ratePercent(), 'vat_dpp' => $this->tax->dppNumerator() . '/' . $this->tax->dppDenominator(),
            ]);

            return $id;
        });
    }

    /**
     * Records a payment (Admin). Input is validated first; then, under the invoice row lock, a void invoice or a payment
     * above the outstanding amount is refused (no overpayment), and the payment and paid_amount are written together.
     *
     * @param array<string, mixed> $input paid_on, amount, method, reference
     */
    public function recordPayment(AuthContext $actor, int $invoiceId, array $input): int
    {
        $this->assertAdmin($actor);
        $paidOn = is_string($input['paid_on'] ?? null) ? trim((string) $input['paid_on']) : '';
        if (!BusinessDate::isValidDate($paidOn)) {
            throw new ValidationException('Payment date must be a valid date (YYYY-MM-DD).');
        }
        $amount = self::amountCents($input['amount'] ?? null);
        $method = is_string($input['method'] ?? null) ? (string) $input['method'] : '';
        if (!in_array($method, self::METHODS, true)) {
            throw new ValidationException('Payment method must be one of ' . implode(', ', self::METHODS) . '.');
        }
        $reference = is_string($input['reference'] ?? null) ? trim((string) $input['reference']) : '';
        if (mb_strlen($reference) > 100) {
            throw new ValidationException('Payment reference may have at most 100 characters.');
        }

        return $this->transactions->run(function () use ($actor, $invoiceId, $paidOn, $amount, $method, $reference): int {
            $invoice = $this->invoices->lock($invoiceId) ?? throw new ValidationException('Invoice not found.');
            $state = InvoiceStatus::of($invoice, $this->invoices->today());
            if ($state['status'] === InvoiceStatus::VOID) {
                throw new ValidationException('Invoice ' . $invoice['invoice_number'] . ' is void; payments cannot be recorded.');
            }
            if ($paidOn < (string) $invoice['invoice_date']) {
                throw new ValidationException('Payment date cannot be before the invoice date (' . $invoice['invoice_date'] . ').');
            }
            if ($paidOn > $this->invoices->today()) {
                throw new ValidationException('Payment date cannot be in the future.');
            }
            if ($amount > $state['outstanding_cents']) {
                throw new ValidationException(sprintf('Overpayment refused: %s outstanding on %s, payment %s.',
                    \App\Support\Money::rupiah($state['outstanding_cents'] / 100), $invoice['invoice_number'], \App\Support\Money::rupiah($amount / 100)));
            }
            $id = $this->invoices->addPayment($invoiceId, $paidOn, TaxSettings::decimal($amount), $method, $reference, $actor->userId());
            $after = TaxSettings::cents((string) $invoice['paid_amount']) + $amount;
            $this->audit?->append($actor->userId(), 'invoices.payment', 'invoices', $invoiceId, 'success', RequestOrigin::none(), [
                'payment_id' => $id, 'paid_on' => $paidOn, 'amount' => TaxSettings::decimal($amount), 'method' => $method,
                'paid_after' => TaxSettings::decimal($after), 'status_after' => $after >= TaxSettings::cents((string) $invoice['total_amount']) ? InvoiceStatus::PAID : InvoiceStatus::PARTIALLY_PAID,
            ]);

            return $id;
        });
    }

    /** Admin voids an invoice with a reason while it has no payment. Stock, ledger and the delivery note are untouched. */
    public function void(AuthContext $actor, int $invoiceId, mixed $reason): void
    {
        $this->assertAdmin($actor);
        $reason = BusinessOperationInput::reason($reason);
        $this->transactions->run(function () use ($actor, $invoiceId, $reason): void {
            $invoice = $this->invoices->lock($invoiceId) ?? throw new ValidationException('Invoice not found.');
            if ($invoice['voided_at'] !== null) {
                throw new ValidationException('Invoice ' . $invoice['invoice_number'] . ' is already void.');
            }
            if (TaxSettings::cents((string) $invoice['paid_amount']) > 0 || $invoice['payments'] !== []) {
                throw new ValidationException('Invoice ' . $invoice['invoice_number'] . ' has payments and cannot be voided.');
            }
            $this->invoices->void($invoiceId, $actor->userId(), $reason);
            $this->audit?->append($actor->userId(), 'invoices.void', 'invoices', $invoiceId, 'success', RequestOrigin::none(), [
                'invoice_number' => $invoice['invoice_number'], 'delivery_note_id' => $invoice['delivery_note_id'], 'reason' => $reason, 'total_amount' => $invoice['total_amount'],
            ]);
        });
    }

    /** @return array<string, mixed> invoice + `state` */
    public function show(AuthContext $actor, int $invoiceId): array
    {
        $this->assertCanRead($actor);
        $invoice = $this->invoices->find($invoiceId) ?? throw new HttpException(404, 'Invoice not found.');
        if ($actor->role() === User::ROLE_SALES && (int) $invoice['sales_created_by'] !== $actor->userId()) {
            throw new HttpException(403, 'Forbidden');
        }

        return $invoice + ['state' => InvoiceStatus::of($invoice, $this->invoices->today())];
    }

    /** The live invoice of a note the actor may view (delivery note and SO pages). @return array{id: int, invoice_number: string}|null */
    public function activeForDeliveryNote(AuthContext $actor, int $deliveryNoteId): ?array
    {
        $this->assertCanRead($actor);

        return $this->invoices->activeForDeliveryNote($deliveryNoteId);
    }

    /**
     * @param array<string, mixed> $query
     * @return PaginatedResult<array<string, mixed>>
     */
    public function search(AuthContext $actor, array $query): PaginatedResult
    {
        $this->assertCanRead($actor);
        $state = is_string($query['state'] ?? null) ? (string) $query['state'] : '';
        if ($state !== '' && !in_array($state, InvoiceStatus::FILTERS, true)) {
            throw new ValidationException('Unknown invoice status filter.');
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $term = is_string($query['q'] ?? null) ? mb_substr(trim((string) $query['q']), 0, 80) : '';

        return $this->invoices->search(['q' => $term, 'state' => $state, 'today' => $this->invoices->today()], is_int($page) ? $page : 1, self::PER_PAGE,
            $actor->role() === User::ROLE_SALES ? $actor->userId() : null);
    }

    public function today(): string
    {
        return $this->invoices->today();
    }

    /**
     * Accounts receivable ageing (Admin): open invoices by customer, outstanding per bucket of days since the invoice date,
     * and the overdue amount (due date passed).
     *
     * @return array{today: string, rows: list<array<string, mixed>>, totals: array<string, int>, invoices: list<array<string, mixed>>}
     */
    public function agingReport(AuthContext $actor): array
    {
        $this->assertAdmin($actor);
        $today = $this->invoices->today();
        /** @var array<string, int> $empty */
        $empty = array_fill_keys(array_keys(self::AGE_BUCKETS), 0) + ['outstanding' => 0, 'overdue' => 0, 'invoices' => 0];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = [];
        $invoices = [];
        foreach ($this->invoices->openInvoices() as $invoice) {
            $state = InvoiceStatus::of($invoice, $today);
            if ($state['outstanding_cents'] <= 0) {
                continue;
            }
            $age = max(0, BusinessDate::daysBetween((string) $invoice['invoice_date'], $today));
            $bucket = self::bucket($age);
            $key = (int) $invoice['customer_id'];
            $rows[$key] ??= ['customer_id' => $key, 'customer_name' => (string) $invoice['customer_name']] + $empty;
            $rows[$key][$bucket] += $state['outstanding_cents'];
            $rows[$key]['outstanding'] += $state['outstanding_cents'];
            $rows[$key]['overdue'] += $state['overdue'] ? $state['outstanding_cents'] : 0;
            $rows[$key]['invoices']++;
            $invoices[] = $invoice + ['age_days' => $age, 'bucket' => $bucket, 'state' => $state];
        }
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => [$b['outstanding'], $a['customer_name']] <=> [$a['outstanding'], $b['customer_name']]);
        $totals = $empty;
        foreach ($rows as $row) {
            foreach (array_keys($empty) as $column) {
                $totals[$column] += $row[$column];
            }
        }

        return ['today' => $today, 'rows' => $rows, 'totals' => $totals, 'invoices' => $invoices];
    }

    /** Sales by customer (Admin): net (subtotal), PPN and total of non-void invoices dated in the range. @param array<string, mixed> $query @return array{from: string, to: string, rows: list<array<string, mixed>>, totals: array<string, int>} */
    public function salesByCustomer(AuthContext $actor, array $query): array
    {
        $this->assertAdmin($actor);
        $today = $this->invoices->today();
        $from = is_string($query['from'] ?? null) && $query['from'] !== '' ? (string) $query['from'] : substr($today, 0, 4) . '-01-01';
        $to = is_string($query['to'] ?? null) && $query['to'] !== '' ? (string) $query['to'] : $today;
        if (!BusinessDate::isValidDate($from) || !BusinessDate::isValidDate($to) || $from > $to) {
            throw new ValidationException('Enter a valid date range (from ≤ to, YYYY-MM-DD).');
        }
        $rows = $this->invoices->salesByCustomer($from, $to);
        $totals = ['invoices' => 0, 'net' => 0, 'vat' => 0, 'total' => 0, 'paid' => 0];
        foreach ($rows as $row) {
            $totals['invoices'] += $row['invoices'];
            foreach (['net', 'vat', 'total', 'paid'] as $column) {
                $totals[$column] += TaxSettings::cents($row[$column]);
            }
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals];
    }

    /** CSV of the ageing report: one row per customer plus a total row. */
    public function agingCsv(AuthContext $actor): string
    {
        $report = $this->agingReport($actor);
        $lines = [['customer', ...array_map(static fn (string $bucket): string => 'days_' . $bucket, array_keys(self::AGE_BUCKETS)), 'outstanding', 'overdue', 'open_invoices']];
        foreach ([...$report['rows'], ['customer_name' => 'TOTAL'] + $report['totals']] as $row) {
            $lines[] = [(string) $row['customer_name'], ...array_map(static fn (string $bucket): string => TaxSettings::decimal((int) $row[$bucket]), array_keys(self::AGE_BUCKETS)),
                TaxSettings::decimal((int) $row['outstanding']), TaxSettings::decimal((int) $row['overdue']), (string) $row['invoices']];
        }

        return self::csv($lines);
    }

    /** @param array<string, mixed> $query */
    public function salesByCustomerCsv(AuthContext $actor, array $query): string
    {
        $report = $this->salesByCustomer($actor, $query);
        $lines = [['customer', 'invoices', 'net', 'ppn', 'total', 'paid', 'from', 'to']];
        foreach ($report['rows'] as $row) {
            $lines[] = [$row['customer_name'], (string) $row['invoices'], $row['net'], $row['vat'], $row['total'], $row['paid'], $report['from'], $report['to']];
        }
        $lines[] = ['TOTAL', (string) $report['totals']['invoices'], TaxSettings::decimal($report['totals']['net']), TaxSettings::decimal($report['totals']['vat']),
            TaxSettings::decimal($report['totals']['total']), TaxSettings::decimal($report['totals']['paid']), $report['from'], $report['to']];

        return self::csv($lines);
    }

    /** Admin dashboard card: outstanding receivables and the overdue part. @return array{outstanding_cents: int, overdue_cents: int, open_invoices: int} */
    public function receivablesSummary(): array
    {
        $today = $this->invoices->today();
        $summary = ['outstanding_cents' => 0, 'overdue_cents' => 0, 'open_invoices' => 0];
        foreach ($this->invoices->openInvoices() as $invoice) {
            $state = InvoiceStatus::of($invoice, $today);
            $summary['outstanding_cents'] += $state['outstanding_cents'];
            $summary['overdue_cents'] += $state['overdue'] ? $state['outstanding_cents'] : 0;
            $summary['open_invoices'] += $state['outstanding_cents'] > 0 ? 1 : 0;
        }

        return $summary;
    }

    public static function bucket(int $ageDays): string
    {
        foreach (self::AGE_BUCKETS as $label => [$from, $to]) {
            if ($ageDays >= $from && ($to === null || $ageDays <= $to)) {
                return $label;
            }
        }

        return '0-30';
    }

    /** Positive amount with at most 2 decimals, in cents. */
    public static function amountCents(mixed $value): int
    {
        $text = is_string($value) ? trim($value) : (is_int($value) ? (string) $value : '');
        if (preg_match('/^\d{1,14}(?:\.\d{1,2})?$/D', $text) !== 1 || TaxSettings::cents($text) <= 0) {
            throw new ValidationException('Payment amount must be greater than zero with at most 2 decimals.');
        }

        return TaxSettings::cents($text);
    }

    /** @param list<list<string>> $rows */
    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to build CSV.');
        }
        foreach ($rows as $row) {
            // Neutralize spreadsheet formulas in text cells (same rule as the other exports).
            fputcsv($handle, array_map(static fn (string $cell): string => preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell, $row), ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function assertAdmin(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertCanRead(AuthContext $actor): void
    {
        if (!in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_SALES], true)) {
            throw new HttpException(403, 'Forbidden');
        }
    }
}
