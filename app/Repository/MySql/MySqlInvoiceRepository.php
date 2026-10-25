<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Exception\PersistenceException;
use App\Repository\Contract\InvoiceRepositoryInterface;
use App\Support\PaginatedResult;
use PDO;

/** Invoices with PPN (D-19, ADR-025). Prepared statements only; writes join the caller's transaction. */
final class MySqlInvoiceRepository implements InvoiceRepositoryInterface
{
    private const HEADER = 'SELECT i.id, i.invoice_number, i.delivery_note_id, dn.delivery_number, i.sales_order_id, so.order_number, so.created_by AS sales_created_by,
            i.customer_id, i.invoice_date, i.due_date, i.customer_name, i.customer_address, i.customer_npwp, i.customer_nik, i.customer_tax_name, i.customer_tax_address,
            i.subtotal, i.dpp_amount, i.vat_amount, i.total_amount, i.vat_rate_percent, i.vat_dpp_numerator, i.vat_dpp_denominator, i.paid_amount,
            i.created_by, u.name AS created_by_name, i.created_at, i.voided_by, i.voided_at, i.void_reason
        FROM invoices i
        JOIN delivery_notes dn ON dn.id = i.delivery_note_id
        JOIN sales_orders so ON so.id = i.sales_order_id
        JOIN users u ON u.id = i.created_by';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function activeForDeliveryNote(int $deliveryNoteId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, invoice_number FROM invoices WHERE active_delivery_note_id = :id');
        $statement->execute(['id' => $deliveryNoteId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['id' => (int) $row['id'], 'invoice_number' => (string) $row['invoice_number']] : null;
    }

    public function create(array $invoice, array $lines): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new PersistenceException('Invoices are created inside a transaction.');
        }

        return PersistenceErrors::write(function () use ($invoice, $lines): int {
            $columns = ['invoice_number', 'delivery_note_id', 'sales_order_id', 'customer_id', 'invoice_date', 'due_date', 'customer_name', 'customer_address', 'customer_npwp',
                'customer_nik', 'customer_tax_name', 'customer_tax_address', 'subtotal', 'dpp_amount', 'vat_amount', 'total_amount', 'vat_rate_percent', 'vat_dpp_numerator',
                'vat_dpp_denominator', 'created_by'];
            $values = [];
            foreach ($columns as $column) {
                $values[$column] = $invoice[$column] ?? null;
            }
            $statement = $this->pdo->prepare('INSERT INTO invoices (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
            $statement->execute($values);
            $id = (int) $this->pdo->lastInsertId();
            $line = $this->pdo->prepare(
                'INSERT INTO invoice_lines (invoice_id, delivery_note_line_id, sales_order_item_id, product_id, unit_code, unit_quantity, unit_price, amount)
                 VALUES (:invoice, :note_line, :item, :product, :unit, :quantity, :price, :amount)'
            );
            foreach ($lines as $row) {
                $line->execute(['invoice' => $id, 'note_line' => $row['delivery_note_line_id'], 'item' => $row['sales_order_item_id'], 'product' => $row['product_id'],
                    'unit' => $row['unit_code'], 'quantity' => $row['unit_quantity'], 'price' => $row['unit_price'], 'amount' => $row['amount']]);
            }

            return $id;
        });
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::HEADER . ' WHERE i.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $lines = $this->pdo->prepare(
            'SELECT l.id, l.delivery_note_line_id, l.sales_order_item_id, l.product_id, p.sku, p.name AS product_name, l.unit_code, l.unit_quantity, l.unit_price, l.amount
             FROM invoice_lines l JOIN products p ON p.id = l.product_id WHERE l.invoice_id = :id ORDER BY l.id ASC'
        );
        $lines->execute(['id' => $id]);
        $payments = $this->pdo->prepare(
            'SELECT pay.id, pay.paid_on, pay.amount, pay.method, pay.reference, pay.recorded_by, u.name AS recorded_by_name, pay.created_at
             FROM invoice_payments pay JOIN users u ON u.id = pay.recorded_by WHERE pay.invoice_id = :id ORDER BY pay.paid_on ASC, pay.id ASC'
        );
        $payments->execute(['id' => $id]);

        return self::header($row) + [
            'lines' => array_map(static fn (array $line): array => [
                'id' => (int) $line['id'], 'delivery_note_line_id' => (int) $line['delivery_note_line_id'], 'sales_order_item_id' => (int) $line['sales_order_item_id'],
                'product_id' => (int) $line['product_id'], 'sku' => (string) $line['sku'], 'product_name' => (string) $line['product_name'], 'unit_code' => (string) $line['unit_code'],
                'unit_quantity' => (int) $line['unit_quantity'], 'unit_price' => (string) $line['unit_price'], 'amount' => (string) $line['amount'],
            ], $lines->fetchAll(PDO::FETCH_ASSOC)),
            'payments' => array_map(static fn (array $payment): array => [
                'id' => (int) $payment['id'], 'paid_on' => (string) $payment['paid_on'], 'amount' => (string) $payment['amount'], 'method' => (string) $payment['method'],
                'reference' => (string) $payment['reference'], 'recorded_by' => (int) $payment['recorded_by'], 'recorded_by_name' => (string) $payment['recorded_by_name'],
                'created_at' => (string) $payment['created_at'],
            ], $payments->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    public function lock(int $id): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new PersistenceException('Invoice locks require a transaction.');
        }
        $statement = $this->pdo->prepare('SELECT id FROM invoices WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() === false ? null : $this->find($id);
    }

    public function addPayment(int $invoiceId, string $paidOn, string $amount, string $method, string $reference, int $recordedBy): int
    {
        return PersistenceErrors::write(function () use ($invoiceId, $paidOn, $amount, $method, $reference, $recordedBy): int {
            $statement = $this->pdo->prepare('INSERT INTO invoice_payments (invoice_id, paid_on, amount, method, reference, recorded_by) VALUES (:invoice, :paid_on, :amount, :method, :reference, :by)');
            $statement->execute(['invoice' => $invoiceId, 'paid_on' => $paidOn, 'amount' => $amount, 'method' => $method, 'reference' => $reference, 'by' => $recordedBy]);
            $id = (int) $this->pdo->lastInsertId();
            $update = $this->pdo->prepare('UPDATE invoices SET paid_amount = paid_amount + CAST(:amount AS DECIMAL(16,2)) WHERE id = :id AND voided_at IS NULL AND paid_amount + CAST(:check_amount AS DECIMAL(16,2)) <= total_amount');
            $update->execute(['id' => $invoiceId, 'amount' => $amount, 'check_amount' => $amount]);
            if ($update->rowCount() !== 1) {
                throw new PersistenceException('The payment could not be applied to the invoice.');
            }

            return $id;
        });
    }

    public function void(int $invoiceId, int $voidedBy, string $reason): void
    {
        $statement = $this->pdo->prepare('UPDATE invoices SET voided_at = CURRENT_TIMESTAMP, voided_by = :by, void_reason = :reason WHERE id = :id AND voided_at IS NULL AND paid_amount = 0');
        $statement->execute(['id' => $invoiceId, 'by' => $voidedBy, 'reason' => $reason]);
        if ($statement->rowCount() !== 1) {
            throw new PersistenceException('The invoice could not be voided.');
        }
    }

    public function search(array $filters, int $page, int $perPage, ?int $salesCreatedBy = null): PaginatedResult
    {
        $where = [];
        $params = [];
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $where[] = '(i.invoice_number LIKE :t1 OR so.order_number LIKE :t2 OR i.customer_name LIKE :t3 OR dn.delivery_number LIKE :t4)';
            $params += ['t1' => '%' . $term . '%', 't2' => '%' . $term . '%', 't3' => '%' . $term . '%', 't4' => '%' . $term . '%'];
        }
        $state = (string) ($filters['state'] ?? '');
        $where = array_merge($where, match ($state) {
            'Void' => ['i.voided_at IS NOT NULL'],
            'Paid' => ['i.voided_at IS NULL', 'i.paid_amount = i.total_amount'],
            'PartiallyPaid' => ['i.voided_at IS NULL', 'i.paid_amount > 0', 'i.paid_amount < i.total_amount'],
            'Unpaid' => ['i.voided_at IS NULL', 'i.paid_amount = 0', 'i.total_amount > 0'],
            'Overdue' => ['i.voided_at IS NULL', 'i.paid_amount < i.total_amount', 'i.due_date < :today'],
            default => [],
        });
        if ($state === 'Overdue') {
            $params['today'] = (string) ($filters['today'] ?? $this->today());
        }
        if ($salesCreatedBy !== null) {
            $where[] = 'so.created_by = :creator';
            $params['creator'] = $salesCreatedBy;
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM invoices i JOIN delivery_notes dn ON dn.id = i.delivery_note_id JOIN sales_orders so ON so.id = i.sales_order_id' . $clause);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));
        $statement = $this->pdo->prepare(self::HEADER . $clause . ' ORDER BY i.invoice_date DESC, i.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = array_map(self::header(...), $statement->fetchAll(PDO::FETCH_ASSOC));

        return new PaginatedResult($rows, $total, $page, $perPage);
    }

    public function openInvoices(): array
    {
        $statement = $this->pdo->prepare(self::HEADER . ' WHERE i.voided_at IS NULL AND i.paid_amount < i.total_amount ORDER BY i.customer_name ASC, i.invoice_date ASC, i.id ASC');
        $statement->execute();

        return array_map(self::header(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function salesByCustomer(string $from, string $to): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.customer_id, c.name AS customer_name, COUNT(*) AS invoices, SUM(i.subtotal) AS net, SUM(i.vat_amount) AS vat, SUM(i.total_amount) AS total, SUM(i.paid_amount) AS paid
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN :from AND :to
             GROUP BY i.customer_id, c.name ORDER BY total DESC, c.name ASC'
        );
        $statement->execute(['from' => $from, 'to' => $to]);

        return array_map(static fn (array $row): array => [
            'customer_id' => (int) $row['customer_id'], 'customer_name' => (string) $row['customer_name'], 'invoices' => (int) $row['invoices'],
            'net' => (string) $row['net'], 'vat' => (string) $row['vat'], 'total' => (string) $row['total'], 'paid' => (string) $row['paid'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function today(): string
    {
        $statement = $this->pdo->query('SELECT ' . ExpirySql::today());

        return $statement === false ? \App\Support\BusinessDate::today() : (string) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function header(array $row): array
    {
        $text = static fn (string $key): ?string => $row[$key] === null ? null : (string) $row[$key];

        return [
            'id' => (int) $row['id'], 'invoice_number' => (string) $row['invoice_number'], 'delivery_note_id' => (int) $row['delivery_note_id'],
            'delivery_number' => (string) $row['delivery_number'], 'sales_order_id' => (int) $row['sales_order_id'], 'order_number' => (string) $row['order_number'],
            'sales_created_by' => (int) $row['sales_created_by'], 'customer_id' => (int) $row['customer_id'], 'invoice_date' => (string) $row['invoice_date'],
            'due_date' => (string) $row['due_date'], 'customer_name' => (string) $row['customer_name'], 'customer_address' => (string) $row['customer_address'],
            'customer_npwp' => $text('customer_npwp'), 'customer_nik' => $text('customer_nik'), 'customer_tax_name' => $text('customer_tax_name'),
            'customer_tax_address' => $text('customer_tax_address'), 'subtotal' => (string) $row['subtotal'], 'dpp_amount' => (string) $row['dpp_amount'],
            'vat_amount' => (string) $row['vat_amount'], 'total_amount' => (string) $row['total_amount'], 'vat_rate_percent' => (int) $row['vat_rate_percent'],
            'vat_dpp_numerator' => (int) $row['vat_dpp_numerator'], 'vat_dpp_denominator' => (int) $row['vat_dpp_denominator'], 'paid_amount' => (string) $row['paid_amount'],
            'created_by' => (int) $row['created_by'], 'created_by_name' => (string) $row['created_by_name'], 'created_at' => (string) $row['created_at'],
            'voided_by' => $row['voided_by'] === null ? null : (int) $row['voided_by'], 'voided_at' => $text('voided_at'), 'void_reason' => $text('void_reason'),
        ];
    }
}
