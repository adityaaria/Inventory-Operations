<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Repository\Contract\SalesOrderRepositoryInterface;
use App\Support\OrderSearchCriteria;
use App\Support\PaginatedResult;
use PDO;
use RuntimeException;

final class MySqlSalesOrderRepository implements SalesOrderRepositoryInterface
{
    private const SORT_COLUMNS = ['order_number' => 'so.order_number', 'order_date' => 'so.order_date', 'status' => 'so.status', 'party' => 'c.name', 'warehouse' => 'w.name'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, order_number, customer_id, source_warehouse_id, status, order_date, created_by, approved_by, approved_at
             FROM sales_orders ORDER BY order_date DESC, id DESC'
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to query sales orders.');
        }

        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function search(OrderSearchCriteria $criteria, ?int $createdBy = null): PaginatedResult
    {
        [$where, $params] = $this->where($criteria, $createdBy);
        $sort = self::SORT_COLUMNS[$criteria->sortBy()] ?? self::SORT_COLUMNS['order_date'];
        $direction = $criteria->direction() === 'asc' ? 'ASC' : 'DESC';
        $statement = $this->pdo->prepare(
            "SELECT so.id, so.order_number, so.customer_id, so.source_warehouse_id, so.status, so.order_date, so.created_by, so.approved_by, so.approved_at
             FROM sales_orders so INNER JOIN customers c ON c.id = so.customer_id
             INNER JOIN warehouses w ON w.id = so.source_warehouse_id
             {$where}
             ORDER BY {$sort} {$direction}, so.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $criteria->perPage(), PDO::PARAM_INT);
        $statement->bindValue('offset', $criteria->offset(), PDO::PARAM_INT);
        $statement->execute();
        $count = $this->countSearch($where, $params);

        return new PaginatedResult($this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)), $count, $criteria->page(), $criteria->perPage());
    }

    public function forCreator(int $createdBy): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_number, customer_id, source_warehouse_id, status, order_date, created_by, approved_by, approved_at
             FROM sales_orders WHERE created_by = :created_by ORDER BY order_date DESC, id DESC'
        );
        $statement->execute(['created_by' => $createdBy]);

        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?SalesOrder
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_number, customer_id, source_warehouse_id, status, order_date, created_by, approved_by, approved_at
             FROM sales_orders WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function lockById(int $id): ?SalesOrder
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Source order locks require a transaction.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, order_number, customer_id, source_warehouse_id, status, order_date, created_by, approved_by, approved_at
             FROM sales_orders WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function createDraft(string $orderNumber, int $customerId, int $warehouseId, int $createdBy, array $items): SalesOrder
    {
        return PersistenceErrors::write(function () use ($orderNumber, $customerId, $warehouseId, $createdBy, $items): SalesOrder {
            $this->pdo->beginTransaction();
            try {
                $statement = $this->pdo->prepare(
                    'INSERT INTO sales_orders (order_number, customer_id, source_warehouse_id, status, order_date, created_by)
                     VALUES (:order_number, :customer_id, :warehouse_id, :status, CURRENT_DATE, :created_by)'
                );
                $statement->execute([
                    'order_number' => $orderNumber,
                    'customer_id' => $customerId,
                    'warehouse_id' => $warehouseId,
                    'status' => SalesOrder::STATUS_DRAFT,
                    'created_by' => $createdBy,
                ]);
                $orderId = (int) $this->pdo->lastInsertId();

                $itemStatement = $this->pdo->prepare(
                    'INSERT INTO sales_order_items (sales_order_id, product_id, quantity, selling_price)
                     VALUES (:sales_order_id, :product_id, :quantity, :selling_price)'
                );
                foreach ($items as $item) {
                    $itemStatement->execute([
                        'sales_order_id' => $orderId,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'selling_price' => $item['selling_price'],
                    ]);
                }

                $this->pdo->commit();
                return $this->findRequired($orderId);
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }

        });
    }

    public function submit(int $id): void
    {
        $statement = $this->pdo->prepare("UPDATE sales_orders SET status = :status WHERE id = :id AND status = 'Draft'");
        $statement->execute(['id' => $id, 'status' => SalesOrder::STATUS_PENDING_APPROVAL]);
    }

    public function approve(int $id, int $approvedBy): void
    {
        $statement = $this->pdo->prepare("UPDATE sales_orders SET status = :status, approved_by = :approved_by, approved_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'PendingApproval'");
        $statement->execute(['id' => $id, 'status' => SalesOrder::STATUS_APPROVED, 'approved_by' => $approvedBy]);
    }

    public function cancel(int $id): void
    {
        $statement = $this->pdo->prepare("UPDATE sales_orders SET status = :status WHERE id = :id AND status IN ('Draft','PendingApproval','Approved','Cancelled')");
        $statement->execute(['id' => $id, 'status' => SalesOrder::STATUS_CANCELLED]);
    }

    public function markFulfilled(int $id): void
    {
        $statement = $this->pdo->prepare("UPDATE sales_orders SET status = :status WHERE id = :id AND status = 'Approved'");
        $statement->execute(['id' => $id, 'status' => SalesOrder::STATUS_FULFILLED]);
    }

    /** @param array<string, mixed> $row @param list<SalesOrderItem>|null $items */
    private function hydrate(array $row, ?array $items = null): SalesOrder
    {
        $orderId = (int) $row['id'];

        return new SalesOrder(
            $orderId,
            (string) $row['order_number'],
            (int) $row['customer_id'],
            (int) $row['source_warehouse_id'],
            (string) $row['status'],
            (string) $row['order_date'],
            (int) $row['created_by'],
            $row['approved_by'] === null ? null : (int) $row['approved_by'],
            $row['approved_at'] === null ? null : (string) $row['approved_at'],
            $items ?? $this->itemsForOrder($orderId),
        );
    }

    /** @param list<array<string, mixed>> $rows @return list<SalesOrder> */
    private function hydrateRows(array $rows): array
    {
        if ($rows === []) { return []; }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare("SELECT id, sales_order_id, product_id, quantity, selling_price FROM sales_order_items WHERE sales_order_id IN ({$placeholders}) ORDER BY id ASC");
        $statement->execute($ids);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[(int) $row['sales_order_id']][] = new SalesOrderItem((int) $row['id'], (int) $row['sales_order_id'], (int) $row['product_id'], (int) $row['quantity'], (float) $row['selling_price']);
        }
        return array_map(fn (array $row): SalesOrder => $this->hydrate($row, $items[(int) $row['id']] ?? []), $rows);
    }

    /** @return list<SalesOrderItem> */
    private function itemsForOrder(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, sales_order_id, product_id, quantity, selling_price
             FROM sales_order_items WHERE sales_order_id = :sales_order_id ORDER BY id ASC'
        );
        $statement->execute(['sales_order_id' => $orderId]);

        return array_map(static fn (array $row): SalesOrderItem => new SalesOrderItem(
            (int) $row['id'],
            (int) $row['sales_order_id'],
            (int) $row['product_id'],
            (int) $row['quantity'],
            (float) $row['selling_price'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function findRequired(int $id): SalesOrder
    {
        return $this->findById($id) ?? throw new RuntimeException("Sales order not found after write: {$id}");
    }

    /** @return array{0: string, 1: array<string, int|string>} */
    private function where(OrderSearchCriteria $criteria, ?int $createdBy): array
    {
        $parts = [];
        $params = [];
        if ($criteria->term() !== '') {
            $parts[] = '(so.order_number LIKE :term OR c.name LIKE :party_term)';
            $params['term'] = '%' . $criteria->term() . '%';
            $params['party_term'] = $params['term'];
        }
        if ($criteria->status() !== null) {
            $parts[] = 'so.status = :status';
            $params['status'] = $criteria->status();
        }
        if ($createdBy !== null) {
            $parts[] = 'so.created_by = :created_by';
            $params['created_by'] = $createdBy;
        }

        return [$parts === [] ? '' : 'WHERE ' . implode(' AND ', $parts), $params];
    }

    /** @param array<string, int|string> $params */
    private function countSearch(string $where, array $params): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM sales_orders so INNER JOIN customers c ON c.id = so.customer_id {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
