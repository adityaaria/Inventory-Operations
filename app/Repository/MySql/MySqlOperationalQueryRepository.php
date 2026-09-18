<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\OperationalQueryRepositoryInterface;
use PDO;

final class MySqlOperationalQueryRepository implements OperationalQueryRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function adminDashboard(): array
    {
        return [
            'inventory_value' => $this->scalarFloat('SELECT COALESCE(SUM(ps.quantity * p.purchase_price), 0) FROM product_stocks ps INNER JOIN products p ON p.id = ps.product_id'),
            'low_stock_count' => count($this->lowStockRows()),
            'purchase_orders_by_status' => $this->countsByStatus('purchase_orders', null),
            'sales_orders_by_status' => $this->countsByStatus('sales_orders', null),
        ];
    }

    public function salesDashboard(int $salesUserId): array
    {
        return [
            'sales_user_id' => $salesUserId,
            'sales_orders_by_status' => $this->countsByStatus('sales_orders', $salesUserId),
            'sales_order_value' => $this->scalarFloat(
                'SELECT COALESCE(SUM(soi.quantity * soi.selling_price), 0)
                 FROM sales_order_items soi INNER JOIN sales_orders so ON so.id = soi.sales_order_id
                 WHERE so.created_by = :created_by',
                ['created_by' => $salesUserId],
            ),
        ];
    }

    public function warehouseDashboard(): array
    {
        return [
            'po_receipt_queue' => $this->scalarInt("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Ordered', 'PartiallyReceived')"),
            'so_issue_queue' => $this->scalarInt("SELECT COUNT(*) FROM sales_orders WHERE status = 'Approved'"),
            'low_stock_rows' => $this->lowStockRows(),
        ];
    }

    public function lowStockRows(): array
    {
        $statement = $this->pdo->query(
            'SELECT p.sku, p.name AS product_name, w.name AS warehouse_name, ps.quantity, p.reorder_point
             FROM product_stocks ps
             INNER JOIN products p ON p.id = ps.product_id
             INNER JOIN warehouses w ON w.id = ps.warehouse_id
             WHERE ps.quantity < p.reorder_point AND p.is_active = 1 AND w.is_active = 1
             ORDER BY p.sku ASC, w.name ASC'
        );

        return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function stockLedgerRows(?string $from, ?string $to): array
    {
        [$where, $params] = $this->dateWhere('created_at', $from, $to);
        $statement = $this->pdo->prepare(
            "SELECT DATE(sl.created_at) AS Date, sl.movement_type AS Movement, p.sku AS SKU, w.name AS Warehouse,
                    sl.quantity AS Quantity, sl.reference_type AS ReferenceType, sl.reference_id AS ReferenceId
             FROM stock_ledger sl
             INNER JOIN products p ON p.id = sl.product_id
             INNER JOIN warehouses w ON w.id = sl.warehouse_id
             {$where}
             ORDER BY sl.created_at DESC, sl.id DESC"
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function orderRows(?string $from, ?string $to, ?int $salesUserId): array
    {
        [$where, $params] = $this->dateWhere('order_date', $from, $to);
        if ($salesUserId !== null) {
            $where .= $where === '' ? 'WHERE so.created_by = :created_by' : ' AND so.created_by = :created_by';
            $params['created_by'] = $salesUserId;
            $statement = $this->pdo->prepare(
                "SELECT 'SO' AS Type, so.order_number AS OrderNumber, c.name AS Party, so.status AS Status, so.order_date AS Date
                 FROM sales_orders so INNER JOIN customers c ON c.id = so.customer_id
                 {$where}
                 ORDER BY so.order_date DESC, so.id DESC"
            );
            $statement->execute($params);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $statement = $this->pdo->prepare(
            "SELECT Type, OrderNumber, Party, Status, Date FROM (
                SELECT 'PO' AS Type, po.order_number AS OrderNumber, s.name AS Party, po.status AS Status, po.order_date AS Date, po.id AS SortId
                FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id
                {$where}
                UNION ALL
                SELECT 'SO' AS Type, so.order_number AS OrderNumber, c.name AS Party, so.status AS Status, so.order_date AS Date, so.id AS SortId
                FROM sales_orders so INNER JOIN customers c ON c.id = so.customer_id
                {$where}
            ) orders_report
            ORDER BY Date DESC, SortId DESC"
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function productAvailability(string $sku): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.sku, p.name, COALESCE(SUM(ps.quantity), 0) AS total_quantity
             FROM products p LEFT JOIN product_stocks ps ON ps.product_id = p.id
             WHERE p.sku = :sku AND p.is_active = 1
             GROUP BY p.id, p.sku, p.name'
        );
        $statement->execute(['sku' => $sku]);
        $product = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($product)) {
            return null;
        }

        $stocks = $this->pdo->prepare(
            'SELECT w.name AS warehouse, ps.quantity
             FROM product_stocks ps INNER JOIN warehouses w ON w.id = ps.warehouse_id
             WHERE ps.product_id = :product_id ORDER BY w.name ASC'
        );
        $stocks->execute(['product_id' => (int) $product['id']]);

        return ['sku' => (string) $product['sku'], 'name' => (string) $product['name'], 'total_quantity' => (int) $product['total_quantity'], 'warehouses' => $stocks->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** @param array<string, int|string> $params */
    private function scalarInt(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, int|string> $params */
    private function scalarFloat(string $sql, array $params = []): float
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (float) $statement->fetchColumn();
    }

    /** @return array<string, int> */
    private function countsByStatus(string $table, ?int $createdBy): array
    {
        $where = $createdBy === null ? '' : ' WHERE created_by = :created_by';
        $statement = $this->pdo->prepare("SELECT status, COUNT(*) AS total FROM {$table}{$where} GROUP BY status");
        $statement->execute($createdBy === null ? [] : ['created_by' => $createdBy]);
        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function dateWhere(string $column, ?string $from, ?string $to): array
    {
        $parts = [];
        $params = [];
        if ($from !== null) {
            $parts[] = "{$column} >= :from_date";
            $params['from_date'] = $from;
        }
        if ($to !== null) {
            $parts[] = "{$column} <= :to_date";
            $params['to_date'] = $to;
        }

        return [$parts === [] ? '' : 'WHERE ' . implode(' AND ', $parts), $params];
    }
}
