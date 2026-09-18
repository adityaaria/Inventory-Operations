<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Repository\Contract\PurchaseOrderRepositoryInterface;
use App\Support\OrderSearchCriteria;
use App\Support\PaginatedResult;
use PDO;
use RuntimeException;

final class MySqlPurchaseOrderRepository implements PurchaseOrderRepositoryInterface
{
    private const SORT_COLUMNS = ['order_number' => 'po.order_number', 'order_date' => 'po.order_date', 'status' => 'po.status'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, order_number, supplier_id, destination_warehouse_id, status, order_date, created_by
             FROM purchase_orders ORDER BY order_date DESC, id DESC'
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to query purchase orders.');
        }

        return array_map(fn (array $row): PurchaseOrder => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function search(OrderSearchCriteria $criteria): PaginatedResult
    {
        [$where, $params] = $this->where($criteria);
        $sort = self::SORT_COLUMNS[$criteria->sortBy()] ?? self::SORT_COLUMNS['order_date'];
        $direction = $criteria->direction() === 'asc' ? 'ASC' : 'DESC';
        $statement = $this->pdo->prepare(
            "SELECT po.id, po.order_number, po.supplier_id, po.destination_warehouse_id, po.status, po.order_date, po.created_by
             FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id
             {$where}
             ORDER BY {$sort} {$direction}, po.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $criteria->perPage(), PDO::PARAM_INT);
        $statement->bindValue('offset', $criteria->offset(), PDO::PARAM_INT);
        $statement->execute();
        $count = $this->countSearch($where, $params);

        return new PaginatedResult(array_map(fn (array $row): PurchaseOrder => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC)), $count, $criteria->page(), $criteria->perPage());
    }

    public function findById(int $id): ?PurchaseOrder
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_number, supplier_id, destination_warehouse_id, status, order_date, created_by
             FROM purchase_orders WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function createDraft(string $orderNumber, int $supplierId, int $warehouseId, int $createdBy, array $items): PurchaseOrder
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO purchase_orders (order_number, supplier_id, destination_warehouse_id, status, order_date, created_by)
                 VALUES (:order_number, :supplier_id, :warehouse_id, :status, CURRENT_DATE, :created_by)'
            );
            $statement->execute([
                'order_number' => $orderNumber,
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'created_by' => $createdBy,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();

            $itemStatement = $this->pdo->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, received_quantity, purchase_price)
                 VALUES (:purchase_order_id, :product_id, :quantity, 0, :purchase_price)'
            );
            foreach ($items as $item) {
                $itemStatement->execute([
                    'purchase_order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'purchase_price' => $item['purchase_price'],
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
    }

    public function markOrdered(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id');
        $statement->execute(['id' => $id, 'status' => PurchaseOrder::STATUS_ORDERED]);
    }

    public function recordReceipt(int $id, array $receivedQuantitiesByItemId, string $status): void
    {
        $itemStatement = $this->pdo->prepare(
            'UPDATE purchase_order_items
             SET received_quantity = received_quantity + :quantity
             WHERE id = :id AND purchase_order_id = :purchase_order_id'
        );
        foreach ($receivedQuantitiesByItemId as $itemId => $quantity) {
            $itemStatement->execute(['id' => $itemId, 'purchase_order_id' => $id, 'quantity' => $quantity]);
        }

        $orderStatement = $this->pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id');
        $orderStatement->execute(['id' => $id, 'status' => $status]);
    }

    public function cancel(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id');
        $statement->execute(['id' => $id, 'status' => PurchaseOrder::STATUS_CANCELLED]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PurchaseOrder
    {
        $orderId = (int) $row['id'];

        return new PurchaseOrder(
            $orderId,
            (string) $row['order_number'],
            (int) $row['supplier_id'],
            (int) $row['destination_warehouse_id'],
            (string) $row['status'],
            (string) $row['order_date'],
            (int) $row['created_by'],
            $this->itemsForOrder($orderId),
        );
    }

    /** @return list<PurchaseOrderItem> */
    private function itemsForOrder(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, purchase_order_id, product_id, quantity, received_quantity, purchase_price
             FROM purchase_order_items WHERE purchase_order_id = :purchase_order_id ORDER BY id ASC'
        );
        $statement->execute(['purchase_order_id' => $orderId]);

        return array_map(static fn (array $row): PurchaseOrderItem => new PurchaseOrderItem(
            (int) $row['id'],
            (int) $row['purchase_order_id'],
            (int) $row['product_id'],
            (int) $row['quantity'],
            (int) $row['received_quantity'],
            (float) $row['purchase_price'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function findRequired(int $id): PurchaseOrder
    {
        return $this->findById($id) ?? throw new RuntimeException("Purchase order not found after write: {$id}");
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function where(OrderSearchCriteria $criteria): array
    {
        $parts = [];
        $params = [];
        if ($criteria->term() !== '') {
            $parts[] = '(po.order_number LIKE :term OR s.name LIKE :term)';
            $params['term'] = '%' . $criteria->term() . '%';
        }
        if ($criteria->status() !== null) {
            $parts[] = 'po.status = :status';
            $params['status'] = $criteria->status();
        }

        return [$parts === [] ? '' : 'WHERE ' . implode(' AND ', $parts), $params];
    }

    /** @param array<string, string> $params */
    private function countSearch(string $where, array $params): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
