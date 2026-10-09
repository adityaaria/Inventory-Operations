<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\OperationalQueryRepositoryInterface;
use App\Support\OutstandingCriteria;
use PDO;

final class MySqlOperationalQueryRepository implements OperationalQueryRepositoryInterface
{
    private const OUTSTANDING_COLUMNS = 'Type, OrderNumber, Party, Status, Created, AgeDays, AgeBucket, DaysSinceApproval, OutstandingQty';
    private const OUTSTANDING_SORT = 'SortDate ASC, Type ASC, SortId ASC';

    private readonly OperationalReportSql $sql;

    public function __construct(private readonly PDO $pdo)
    {
        $this->sql = new OperationalReportSql();
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
            'po_receipt_queue' => $this->scalarInt("SELECT COUNT(*) FROM purchase_orders po LEFT JOIN purchase_order_closures c ON c.purchase_order_id=po.id WHERE po.status IN ('Ordered', 'PartiallyReceived') AND c.purchase_order_id IS NULL"),
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
        [$source, $params] = $this->sql->report($type, $from, $to, $salesUserId);
        $group = $type === 'orders' ? 'Status' : 'Movement';
        $bucket = $type === 'orders' ? 'Type' : 'Warehouse';
        $quantity = $type === 'orders' ? '0' : 'SUM(Quantity)';
        $statement = $this->pdo->prepare("SELECT {$group} AS label, COUNT(*) AS total, {$quantity} AS quantity FROM ({$source}) report GROUP BY {$group} ORDER BY {$group}");
        $statement->execute($params);
        $counts = [];
        $received = $issued = $adjusted = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['label']] = (int) $row['total'];
            $received += $row['label'] === 'Receipt' ? (int) $row['quantity'] : 0;
            $issued += $row['label'] === 'Issue' ? (int) $row['quantity'] : 0;
            $adjusted += $row['label'] === 'Adjustment' ? (int) $row['quantity'] : 0;
        }
        $statement = $this->pdo->prepare("SELECT {$bucket} AS label, COUNT(*) AS total FROM ({$source}) report GROUP BY {$bucket} ORDER BY {$bucket}");
        $statement->execute($params);
        $distribution = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) { $distribution[(string) $row['label']] = (int) $row['total']; }
        return ['total' => array_sum($counts), 'counts' => $counts, 'distribution' => $distribution, 'received' => $received, 'issued' => $issued, 'adjusted' => $adjusted];
    }

    public function reportPage(string $type, ?string $from, ?string $to, ?int $salesUserId, int $limit, int $offset): array
    {
        [$source, $params, $sort] = $this->sql->report($type, $from, $to, $salesUserId);
        $columns = $type === 'orders' ? 'Type, OrderNumber, Party, Status, Date' : 'Date, Movement, SKU, Warehouse, Quantity, ReferenceType, ReferenceId';
        $statement = $this->pdo->prepare("SELECT {$columns} FROM ({$source}) report ORDER BY {$sort} LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) { $statement->bindValue($key, $value); }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function iterateReportRows(string $type, ?string $from, ?string $to, ?int $salesUserId): iterable
    {
        [$source, $params, $sort] = $this->sql->report($type, $from, $to, $salesUserId);
        $columns = $type === 'orders' ? 'Type, OrderNumber, Party, Status, Date' : 'Date, Movement, SKU, Warehouse, Quantity, ReferenceType, ReferenceId';
        yield from $this->streamRows("SELECT {$columns} FROM ({$source}) report ORDER BY {$sort}", $params);
    }

    public function outstandingSummary(OutstandingCriteria $criteria): array
    {
        [$source, $params] = $this->sql->outstanding($criteria);
        $statement = $this->pdo->prepare("SELECT Type, Status, AgeBucket, COUNT(*) AS total, COALESCE(SUM(OutstandingQty), 0) AS units, MAX(AgeDays) AS oldest
            FROM ({$source}) outstanding GROUP BY Type, Status, AgeBucket");
        $statement->execute($params);
        $buckets = array_fill_keys(self::AGE_BUCKETS, 0);
        $statuses = [];
        $inbound = $outbound = $oldest = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total = (int) $row['total'];
            $buckets[(string) $row['AgeBucket']] += $total;
            $status = $row['Type'] . ' ' . $row['Status'];
            $statuses[$status] = ($statuses[$status] ?? 0) + $total;
            $inbound += $row['Type'] === 'PO' ? (int) $row['units'] : 0;
            $outbound += $row['Type'] === 'SO' ? (int) $row['units'] : 0;
            $oldest = max($oldest, (int) $row['oldest']);
        }
        ksort($statuses);
        return ['total' => array_sum($buckets), 'buckets' => $buckets, 'statuses' => $statuses, 'inbound_units' => $inbound, 'outbound_units' => $outbound, 'oldest_days' => $oldest];
    }

    public function outstandingPage(OutstandingCriteria $criteria, int $limit, int $offset): array
    {
        [$source, $params] = $this->sql->outstanding($criteria);
        $statement = $this->pdo->prepare('SELECT ' . self::OUTSTANDING_COLUMNS . " FROM ({$source}) outstanding ORDER BY " . self::OUTSTANDING_SORT . ' LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) { $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR); }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function iterateOutstandingRows(OutstandingCriteria $criteria): iterable
    {
        [$source, $params] = $this->sql->outstanding($criteria);
        yield from $this->streamRows('SELECT ' . self::OUTSTANDING_COLUMNS . " FROM ({$source}) outstanding ORDER BY " . self::OUTSTANDING_SORT, $params);
    }

    /**
     * @param array<string, int|string> $params
     * @return iterable<array<string, string|int|float|null>>
     */
    private function streamRows(string $sql, array $params): iterable
    {
        $buffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = null;
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) { yield $row; }
        } finally {
            $statement?->closeCursor();
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
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
}
