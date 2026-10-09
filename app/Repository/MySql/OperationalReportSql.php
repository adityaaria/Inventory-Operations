<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\OperationalQueryRepositoryInterface;
use App\Support\OutstandingCriteria;

/** Builds the report and outstanding SQL sources (no I/O); MySqlOperationalQueryRepository executes them. */
final class OperationalReportSql
{
    /**
     * Open documents per role scope. Age counts whole days since creation using the database clock;
     * closed PO remainders and terminal statuses are excluded. DaysSinceApproval is filled only where an approval
     * time is recorded and still current (Approved SO and stock proposals); POs record no ordered-at time. Stock proposals carry no unit total
     * because adjustment counts, transfers and returns do not share one direction.
     *
     * @return array{0: string, 1: array<string, int|string>}
     */
    public function outstanding(OutstandingCriteria $criteria): array
    {
        $statuses = match ($criteria->scope) {
            OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_ALL => ['PO' => ['Draft', 'Ordered', 'PartiallyReceived'], 'SO' => ['Draft', 'PendingApproval', 'Approved'], 'OP' => ['PendingApproval', 'Approved']],
            OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_FULFILMENT => ['PO' => ['Ordered', 'PartiallyReceived'], 'SO' => ['Approved'], 'OP' => ['Approved']],
            OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_SALES => ['SO' => ['Draft', 'PendingApproval', 'Approved']],
            default => throw new \LogicException('Unknown outstanding scope.'),
        };
        if ($criteria->scope === OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_SALES && $criteria->owner === null) { throw new \LogicException('Sales outstanding scope requires an owner.'); }
        if ($criteria->document !== '') { $statuses = array_intersect_key($statuses, [$criteria->document => true]); }
        $list = static fn (array $values): string => "'" . implode("', '", $values) . "'";
        $parts = [];
        if (isset($statuses['PO'])) {
            $parts[] = "SELECT 'PO' AS Type, po.id AS SortId, po.order_number AS OrderNumber, s.name AS Party, po.status AS Status, po.created_at, NULL AS ApprovedAt,
                COALESCE((SELECT SUM(CAST(poi.quantity AS SIGNED) - CAST(poi.received_quantity AS SIGNED)) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id), 0) AS OutstandingQty
                FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id
                LEFT JOIN purchase_order_closures c ON c.purchase_order_id = po.id
                WHERE po.status IN ({$list($statuses['PO'])}) AND c.purchase_order_id IS NULL";
        }
        if (isset($statuses['SO'])) {
            $owner = $criteria->owner === null ? '' : ' AND so.created_by = :created_by';
            $parts[] = "SELECT 'SO' AS Type, so.id AS SortId, so.order_number AS OrderNumber, cu.name AS Party, so.status AS Status, so.created_at, CASE WHEN so.status = 'Approved' THEN so.approved_at END AS ApprovedAt,
                COALESCE((SELECT SUM(soi.quantity) FROM sales_order_items soi WHERE soi.sales_order_id = so.id), 0) AS OutstandingQty
                FROM sales_orders so INNER JOIN customers cu ON cu.id = so.customer_id
                WHERE so.status IN ({$list($statuses['SO'])}){$owner}";
        }
        if (isset($statuses['OP'])) {
            $parts[] = "SELECT 'OP' AS Type, io.id AS SortId, CONCAT(io.kind, ' #', io.id) AS OrderNumber,
                CASE WHEN d.id IS NULL THEN w.name ELSE CONCAT(w.name, ' → ', d.name) END AS Party, io.status AS Status, io.created_at, CASE WHEN io.status = 'Approved' THEN io.approved_at END AS ApprovedAt, NULL AS OutstandingQty
                FROM inventory_operations io INNER JOIN warehouses w ON w.id = io.warehouse_id LEFT JOIN warehouses d ON d.id = io.destination_id
                WHERE io.status IN ({$list($statuses['OP'])})";
        }
        [$where, $params] = $this->dateWhere('created_at', $criteria->from, $criteria->to);
        if ($criteria->owner !== null && isset($statuses['SO'])) { $params['created_by'] = $criteria->owner; }
        if ($parts === []) { throw new \LogicException('Document type is outside the outstanding scope.'); }
        $bucket = '';
        if ($criteria->bucket !== '') {
            $bucket = ' WHERE AgeBucket = :age_bucket';
            $params['age_bucket'] = $criteria->bucket;
        }
        $open = implode(' UNION ALL ', $parts);
        return ["SELECT * FROM (SELECT Type, OrderNumber, Party, Status, DATE(created_at) AS Created, AgeDays,
            CASE WHEN AgeDays <= 2 THEN '0-2 days' WHEN AgeDays <= 7 THEN '3-7 days' WHEN AgeDays <= 30 THEN '8-30 days' ELSE '31+ days' END AS AgeBucket,
            CASE WHEN ApprovedAt IS NULL THEN NULL ELSE GREATEST(0, TIMESTAMPDIFF(DAY, ApprovedAt, NOW())) END AS DaysSinceApproval,
            CAST(OutstandingQty AS SIGNED) AS OutstandingQty, created_at AS SortDate, SortId
            FROM (SELECT open_orders.*, GREATEST(0, TIMESTAMPDIFF(DAY, open_orders.created_at, NOW())) AS AgeDays FROM ({$open}) open_orders {$where}) aged) bucketed{$bucket}", $params];
    }

    /** @return array{0: string, 1: array<string, int|string>, 2: string} */
    public function report(string $type, ?string $from, ?string $to, ?int $salesUserId): array
    {
        if ($type === 'stock-ledger') {
            [$where, $params] = $this->dateWhere('sl.created_at', $from, $to);
            return ["SELECT DATE(sl.created_at) AS Date, sl.created_at AS SortDate, sl.id AS SortId,
                sl.movement_type AS Movement, p.sku AS SKU, w.name AS Warehouse, CASE WHEN sl.movement_type='Adjustment' THEN sl.quantity_delta ELSE sl.quantity END AS Quantity,
                sl.reference_type AS ReferenceType, sl.reference_id AS ReferenceId
                FROM stock_ledger sl INNER JOIN products p ON p.id = sl.product_id
                INNER JOIN warehouses w ON w.id = sl.warehouse_id {$where}", $params, 'SortDate DESC, SortId DESC'];
        }
        [$where, $params] = $this->dateWhere('Date', $from, $to);
        $owner = $salesUserId === null ? '' : ' WHERE so.created_by = :created_by';
        if ($salesUserId !== null) { $params['created_by'] = $salesUserId; }
        $sales = "SELECT 'SO' AS Type, so.order_number AS OrderNumber, c.name AS Party, so.status AS Status, so.order_date AS Date, so.id AS SortId
            FROM sales_orders so INNER JOIN customers c ON c.id = so.customer_id {$owner}";
        $orders = $salesUserId !== null ? $sales : "SELECT 'PO' AS Type, po.order_number AS OrderNumber, s.name AS Party, po.status AS Status, po.order_date AS Date, po.id AS SortId
            FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id UNION ALL {$sales}";
        return ["SELECT * FROM ({$orders}) orders_report {$where}", $params, 'Date DESC, Type ASC, SortId DESC'];
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
