<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Exception\PersistenceException;
use App\Repository\Contract\QuotationRepositoryInterface;
use App\Support\PaginatedResult;
use PDO;

/** Quotations (D-19, ADR-025). Prepared statements only. */
final class MySqlQuotationRepository implements QuotationRepositoryInterface
{
    private const HEADER = 'SELECT q.id, q.quotation_number, q.customer_id, c.name AS customer_name, q.source_warehouse_id, w.name AS warehouse_name, q.status, q.quote_date,
            q.valid_until, q.notes, q.created_by, u.name AS created_by_name, q.sent_at, q.decided_by, q.decided_at, q.rejection_reason, q.cancelled_by, q.cancelled_at,
            q.created_at, so.id AS sales_order_id, so.order_number AS sales_order_number,
            (SELECT COALESCE(SUM(i.unit_quantity * i.unit_price), 0) FROM quotation_items i WHERE i.quotation_id = q.id) AS total,
            (SELECT COUNT(*) FROM quotation_items i WHERE i.quotation_id = q.id) AS line_count
        FROM quotations q
        JOIN customers c ON c.id = q.customer_id
        JOIN warehouses w ON w.id = q.source_warehouse_id
        JOIN users u ON u.id = q.created_by
        LEFT JOIN sales_orders so ON so.quotation_id = q.id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $number, int $customerId, int $warehouseId, int $createdBy, string $quoteDate, string $validUntil, string $notes, array $lines): int
    {
        return PersistenceErrors::write(fn (): int => (new MySqlTransactionManager($this->pdo))->run(function () use ($number, $customerId, $warehouseId, $createdBy, $quoteDate, $validUntil, $notes, $lines): int {
            $statement = $this->pdo->prepare(
                'INSERT INTO quotations (quotation_number, customer_id, source_warehouse_id, status, quote_date, valid_until, notes, created_by)
                 VALUES (:number, :customer, :warehouse, \'Draft\', :quote_date, :valid_until, :notes, :created_by)'
            );
            $statement->execute(['number' => $number, 'customer' => $customerId, 'warehouse' => $warehouseId, 'quote_date' => $quoteDate, 'valid_until' => $validUntil, 'notes' => $notes, 'created_by' => $createdBy]);
            $id = (int) $this->pdo->lastInsertId();
            $item = $this->pdo->prepare(
                'INSERT INTO quotation_items (quotation_id, product_id, quantity, unit_code, unit_factor, unit_quantity, unit_price, list_price, price_reason, price_source, price_list_id)
                 VALUES (:quotation, :product, :quantity, :unit, :factor, :unit_quantity, :price, :list_price, :reason, :source, :list)'
            );
            foreach ($lines as $line) {
                $item->execute(['quotation' => $id, 'product' => $line['product_id'], 'quantity' => $line['quantity'], 'unit' => $line['unit_code'], 'factor' => $line['unit_factor'],
                    'unit_quantity' => $line['unit_quantity'], 'price' => number_format($line['selling_price'], 2, '.', ''), 'list_price' => number_format($line['list_price'], 2, '.', ''),
                    'reason' => $line['price_reason'], 'source' => $line['price_source'], 'list' => $line['price_list_id']]);
            }

            return $id;
        }));
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::HEADER . ' WHERE q.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $lines = $this->pdo->prepare(
            'SELECT i.id, i.product_id, p.sku, p.name AS product_name, i.unit_code, i.unit_factor, i.unit_quantity, i.quantity, i.unit_price, i.list_price, i.price_reason,
                    i.price_source, i.price_list_id, pl.name AS price_list_name
             FROM quotation_items i JOIN products p ON p.id = i.product_id LEFT JOIN price_lists pl ON pl.id = i.price_list_id
             WHERE i.quotation_id = :id ORDER BY i.id ASC'
        );
        $lines->execute(['id' => $id]);

        return self::header($row) + ['lines' => array_map(static fn (array $line): array => [
            'id' => (int) $line['id'], 'product_id' => (int) $line['product_id'], 'sku' => (string) $line['sku'], 'product_name' => (string) $line['product_name'],
            'unit_code' => (string) $line['unit_code'], 'unit_factor' => (int) $line['unit_factor'], 'unit_quantity' => (int) $line['unit_quantity'], 'quantity' => (int) $line['quantity'],
            'unit_price' => (string) $line['unit_price'], 'list_price' => (string) $line['list_price'], 'price_reason' => $line['price_reason'] === null ? null : (string) $line['price_reason'],
            'price_source' => (string) $line['price_source'], 'price_list_id' => $line['price_list_id'] === null ? null : (int) $line['price_list_id'],
            'price_list_name' => $line['price_list_name'] === null ? null : (string) $line['price_list_name'],
        ], $lines->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function lock(int $id): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new PersistenceException('Quotation locks require a transaction.');
        }
        $statement = $this->pdo->prepare('SELECT id FROM quotations WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() === false ? null : $this->find($id);
    }

    public function search(array $filters, int $page, int $perPage, ?int $createdBy = null): PaginatedResult
    {
        $where = [];
        $params = [];
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $where[] = '(q.quotation_number LIKE :t1 OR c.name LIKE :t2)';
            $params += ['t1' => '%' . $term . '%', 't2' => '%' . $term . '%'];
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $where[] = match ($status) {
                'Expired' => "q.status IN ('Draft', 'Sent') AND q.valid_until < :today",
                'Draft', 'Sent' => 'q.status = :status AND q.valid_until >= :today',
                'Converted' => 'so.id IS NOT NULL',
                default => 'q.status = :status',
            };
            if (in_array($status, ['Expired', 'Draft', 'Sent'], true)) {
                $params['today'] = $filters['today'];
            }
            if (!in_array($status, ['Expired', 'Converted'], true)) {
                $params['status'] = $status;
            }
        }
        if ($createdBy !== null) {
            $where[] = 'q.created_by = :creator';
            $params['creator'] = $createdBy;
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM quotations q JOIN customers c ON c.id = q.customer_id LEFT JOIN sales_orders so ON so.quotation_id = q.id' . $clause);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));
        $statement = $this->pdo->prepare(self::HEADER . $clause . ' ORDER BY q.quote_date DESC, q.id DESC LIMIT :limit OFFSET :offset');
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

    public function markSent(int $id): void
    {
        $this->transition("UPDATE quotations SET status = 'Sent', sent_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'Draft'", ['id' => $id]);
    }

    public function markAccepted(int $id, int $decidedBy): void
    {
        $this->transition("UPDATE quotations SET status = 'Accepted', decided_by = :by, decided_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'Sent'", ['id' => $id, 'by' => $decidedBy]);
    }

    public function markRejected(int $id, int $decidedBy, string $reason): void
    {
        $this->transition("UPDATE quotations SET status = 'Rejected', decided_by = :by, decided_at = CURRENT_TIMESTAMP, rejection_reason = :reason WHERE id = :id AND status = 'Sent'", ['id' => $id, 'by' => $decidedBy, 'reason' => $reason]);
    }

    public function markCancelled(int $id, int $cancelledBy): void
    {
        $this->transition("UPDATE quotations SET status = 'Cancelled', cancelled_by = :by, cancelled_at = CURRENT_TIMESTAMP WHERE id = :id AND status IN ('Draft', 'Sent', 'Accepted')
            AND NOT EXISTS (SELECT 1 FROM sales_orders so WHERE so.quotation_id = :converted_id)", ['id' => $id, 'by' => $cancelledBy, 'converted_id' => $id]);
    }

    public function today(): string
    {
        $statement = $this->pdo->query('SELECT ' . ExpirySql::today());

        return $statement === false ? \App\Support\BusinessDate::today() : (string) $statement->fetchColumn();
    }

    /** @param array<string, int|string> $params */
    private function transition(string $sql, array $params): void
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        if ($statement->rowCount() !== 1) {
            throw new PersistenceException('The quotation changed meanwhile; reload and try again.');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function header(array $row): array
    {
        $text = static fn (string $key): ?string => $row[$key] === null ? null : (string) $row[$key];
        $int = static fn (string $key): ?int => $row[$key] === null ? null : (int) $row[$key];

        return [
            'id' => (int) $row['id'], 'quotation_number' => (string) $row['quotation_number'], 'customer_id' => (int) $row['customer_id'], 'customer_name' => (string) $row['customer_name'],
            'source_warehouse_id' => (int) $row['source_warehouse_id'], 'warehouse_name' => (string) $row['warehouse_name'], 'status' => (string) $row['status'],
            'quote_date' => (string) $row['quote_date'], 'valid_until' => (string) $row['valid_until'], 'notes' => (string) $row['notes'], 'created_by' => (int) $row['created_by'],
            'created_by_name' => (string) $row['created_by_name'], 'sent_at' => $text('sent_at'), 'decided_by' => $int('decided_by'), 'decided_at' => $text('decided_at'),
            'rejection_reason' => $text('rejection_reason'), 'cancelled_by' => $int('cancelled_by'), 'cancelled_at' => $text('cancelled_at'), 'created_at' => (string) $row['created_at'],
            'sales_order_id' => $int('sales_order_id'), 'sales_order_number' => $text('sales_order_number'), 'total' => (string) $row['total'], 'line_count' => (int) $row['line_count'],
        ];
    }
}
