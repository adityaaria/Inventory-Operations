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
            'low_stock_count' => $this->scalarInt('SELECT COUNT(*) FROM product_stocks ps INNER JOIN products p ON p.id=ps.product_id INNER JOIN warehouses w ON w.id=ps.warehouse_id WHERE ps.quantity < p.reorder_point AND p.is_active=1 AND w.is_active=1'),
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
        return iterator_to_array($this->iterateReportRows('stock-ledger', $from, $to, null), false);
    }

    public function orderRows(?string $from, ?string $to, ?int $salesUserId): array
    {
        return iterator_to_array($this->iterateReportRows('orders', $from, $to, $salesUserId), false);
    }

    public function reportSummary(string $type, ?string $from, ?string $to, ?int $salesUserId): array
    {
        [$source, $params] = $this->reportSource($type, $from, $to, $salesUserId);
        $group = $type === 'orders' ? 'Status' : 'Movement';
        $bucket = $type === 'orders' ? 'Type' : 'Warehouse';
        $quantity = $type === 'orders' ? '0' : 'SUM(Quantity)';
        $statement = $this->pdo->prepare("SELECT {$group} AS label, COUNT(*) AS total, {$quantity} AS quantity FROM ({$source}) report GROUP BY {$group} ORDER BY {$group}");
        $statement->execute($params);
        $counts = [];
        $received = $issued = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['label']] = (int) $row['total'];
            $received += $row['label'] === 'Receipt' ? (int) $row['quantity'] : 0;
            $issued += $row['label'] === 'Issue' ? (int) $row['quantity'] : 0;
        }
        $statement = $this->pdo->prepare("SELECT {$bucket} AS label, COUNT(*) AS total FROM ({$source}) report GROUP BY {$bucket} ORDER BY {$bucket}");
        $statement->execute($params);
        $distribution = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $distribution[(string) $row['label']] = (int) $row['total'];
        return ['total' => array_sum($counts), 'counts' => $counts, 'distribution' => $distribution, 'received' => $received, 'issued' => $issued];
    }

    public function reportPage(string $type, ?string $from, ?string $to, ?int $salesUserId, int $limit, int $offset): array
    {
        [$source, $params, $sort] = $this->reportSource($type, $from, $to, $salesUserId);
        $columns = $type === 'orders' ? 'Type, OrderNumber, Party, Status, Date' : 'Date, Movement, SKU, Warehouse, Quantity, ReferenceType, ReferenceId';
        $statement = $this->pdo->prepare("SELECT {$columns} FROM ({$source}) report ORDER BY {$sort} LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $statement->bindValue($key, $value);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function iterateReportRows(string $type, ?string $from, ?string $to, ?int $salesUserId): iterable
    {
        [$source, $params, $sort] = $this->reportSource($type, $from, $to, $salesUserId);
        $columns = $type === 'orders' ? 'Type, OrderNumber, Party, Status, Date' : 'Date, Movement, SKU, Warehouse, Quantity, ReferenceType, ReferenceId';
        $buffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = null;
        try {
            $statement = $this->pdo->prepare("SELECT {$columns} FROM ({$source}) report ORDER BY {$sort}");
            $statement->execute($params);
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) yield $row;
        } finally {
            $statement?->closeCursor();
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    /** @return array{0: string, 1: array<string, int|string>, 2: string} */
    private function reportSource(string $type, ?string $from, ?string $to, ?int $salesUserId): array
    {
        if ($type === 'stock-ledger') {
            [$where, $params] = $this->dateWhere('sl.created_at', $from, $to);
            return ["SELECT DATE(sl.created_at) AS Date, sl.created_at AS SortDate, sl.id AS SortId,
                sl.movement_type AS Movement, p.sku AS SKU, w.name AS Warehouse, sl.quantity AS Quantity,
                sl.reference_type AS ReferenceType, sl.reference_id AS ReferenceId
                FROM stock_ledger sl INNER JOIN products p ON p.id = sl.product_id
                INNER JOIN warehouses w ON w.id = sl.warehouse_id {$where}", $params, 'SortDate DESC, SortId DESC'];
        }
        [$where, $params] = $this->dateWhere('Date', $from, $to);
        $owner = $salesUserId === null ? '' : ' WHERE so.created_by = :created_by';
        if ($salesUserId !== null) $params['created_by'] = $salesUserId;
        $sales = "SELECT 'SO' AS Type, so.order_number AS OrderNumber, c.name AS Party, so.status AS Status, so.order_date AS Date, so.id AS SortId
            FROM sales_orders so INNER JOIN customers c ON c.id = so.customer_id {$owner}";
        $orders = $salesUserId !== null ? $sales : "SELECT 'PO' AS Type, po.order_number AS OrderNumber, s.name AS Party, po.status AS Status, po.order_date AS Date, po.id AS SortId
            FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id UNION ALL {$sales}";
        return ["SELECT * FROM ({$orders}) orders_report {$where}", $params, 'Date DESC, Type ASC, SortId DESC'];
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
            $timestamp = str_ends_with($column, 'created_at');
            $parts[] = $timestamp ? "{$column} < :to_date" : "{$column} <= :to_date";
            $params['to_date'] = $timestamp ? (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') : $to;
        }

        return [$parts === [] ? '' : 'WHERE ' . implode(' AND ', $parts), $params];
    }
}
