<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\LotQueryRepositoryInterface;
use PDO;

/** Prepared read queries for lots (D-17, ADR-023). "Today" is the business date (ExpirySql). */
final class MySqlLotQueryRepository implements LotQueryRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function today(): string
    {
        $statement = $this->pdo->query('SELECT ' . ExpirySql::today());

        return $statement === false ? \App\Support\BusinessDate::today() : (string) $statement->fetchColumn();
    }

    public function productLots(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.warehouse_id, w.name AS warehouse_name, l.lot_code, l.expiry_date, l.received_at, l.unit_cost, l.quantity,
                    DATEDIFF(l.expiry_date, ' . ExpirySql::today() . ') AS days_left
             FROM stock_lots l INNER JOIN warehouses w ON w.id = l.warehouse_id
             WHERE l.product_id = :product_id AND l.quantity > 0
             ORDER BY w.name, l.expiry_key, l.received_at, l.id'
        );
        $statement->execute(['product_id' => $productId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function expirySummary(array $filters, int $nearDays): array
    {
        [$where, $params] = $this->where(['status' => 'flagged'] + $filters, $nearDays);
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(days_left < 0), 0) AS expired_lots, COALESCE(SUM(days_left >= 0), 0) AS near_lots,
                    COALESCE(SUM(IF(days_left < 0, quantity, 0)), 0) AS expired_units, COALESCE(SUM(IF(days_left >= 0, quantity, 0)), 0) AS near_units
             FROM (' . $this->source() . ') lots' . $where
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['near_lots' => (int) ($row['near_lots'] ?? 0), 'expired_lots' => (int) ($row['expired_lots'] ?? 0), 'near_units' => (int) ($row['near_units'] ?? 0), 'expired_units' => (int) ($row['expired_units'] ?? 0)];
    }

    public function expiryCount(array $filters, int $nearDays): int
    {
        [$where, $params] = $this->where($filters, $nearDays);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM (' . $this->source() . ') lots' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function expiryPage(array $filters, int $nearDays, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters, $nearDays);
        $statement = $this->pdo->prepare('SELECT * FROM (' . $this->source() . ') lots' . $where . ' ORDER BY expiry_date, sku, warehouse_name, id LIMIT :row_limit OFFSET :row_offset');
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('row_limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('row_offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function iterateExpiry(array $filters, int $nearDays): iterable
    {
        [$where, $params] = $this->where($filters, $nearDays);
        $statement = $this->pdo->prepare('SELECT * FROM (' . $this->source() . ') lots' . $where . ' ORDER BY expiry_date, sku, warehouse_name, id');
        $statement->execute($params);
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
    }

    public function overReserved(): array
    {
        $statement = $this->pdo->query(
            'SELECT ps.product_id, ps.warehouse_id, p.sku, p.name AS product_name, w.name AS warehouse_name, ps.quantity, ps.reserved_quantity,
                    ' . ExpirySql::expiredQuantity('ps') . ' AS expired_quantity,
                    (SELECT GROUP_CONCAT(DISTINCT so.order_number ORDER BY so.order_number SEPARATOR \', \') FROM sales_orders so
                       INNER JOIN sales_order_items i ON i.sales_order_id = so.id
                       LEFT JOIN sales_order_closures c ON c.sales_order_id = so.id
                      WHERE so.source_warehouse_id = ps.warehouse_id AND i.product_id = ps.product_id AND so.status IN (\'Approved\', \'PartiallyFulfilled\')
                        AND c.sales_order_id IS NULL AND i.issued_quantity < i.quantity) AS orders
             FROM product_stocks ps INNER JOIN products p ON p.id = ps.product_id INNER JOIN warehouses w ON w.id = ps.warehouse_id
             WHERE ps.reserved_quantity > 0 AND CAST(ps.reserved_quantity AS SIGNED) > CAST(ps.quantity AS SIGNED) - ' . ExpirySql::expiredQuantity('ps') . '
             ORDER BY p.sku, w.name'
        );

        return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchLots(string $query, int $limit, int $offset): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.lot_code, l.expiry_date, l.received_at, l.quantity, l.source_type, p.sku, p.name AS product_name, w.name AS warehouse_name,
                    DATEDIFF(l.expiry_date, ' . ExpirySql::today() . ') AS days_left
             FROM stock_lots l INNER JOIN products p ON p.id = l.product_id INNER JOIN warehouses w ON w.id = l.warehouse_id
             WHERE l.lot_code LIKE :code ESCAPE \'!\' OR p.sku LIKE :sku ESCAPE \'!\'
             ORDER BY l.lot_code, p.sku, w.name, l.id LIMIT :row_limit OFFSET :row_offset'
        );
        $pattern = $this->pattern($query);
        $statement->bindValue('code', $pattern);
        $statement->bindValue('sku', $pattern);
        $statement->bindValue('row_limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('row_offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchCount(string $query): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM stock_lots l INNER JOIN products p ON p.id = l.product_id WHERE l.lot_code LIKE :code ESCAPE \'!\' OR p.sku LIKE :sku ESCAPE \'!\'');
        $pattern = $this->pattern($query);
        $statement->execute(['code' => $pattern, 'sku' => $pattern]);

        return (int) $statement->fetchColumn();
    }

    public function lot(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.*, p.sku, p.name AS product_name, p.unit AS base_unit, p.tracks_expiry, w.name AS warehouse_name, DATEDIFF(l.expiry_date, ' . ExpirySql::today() . ') AS days_left
             FROM stock_lots l INNER JOIN products p ON p.id = l.product_id INNER JOIN warehouses w ON w.id = l.warehouse_id WHERE l.id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function lotMovements(int $id): array
    {
        $statement = $this->pdo->prepare(
            "SELECT s.id, s.movement_type, s.quantity, s.quantity_delta, s.reference_type, s.reference_id, s.created_at, u.name AS performed_by,
                    po.order_number AS po_number, sup.name AS supplier_name, so.order_number AS so_number, cus.name AS customer_name,
                    op.kind AS operation_kind, op.purpose AS operation_purpose
             FROM stock_ledger s
             LEFT JOIN users u ON u.id = s.performed_by
             LEFT JOIN purchase_orders po ON s.reference_type = 'PO' AND po.id = s.reference_id
             LEFT JOIN suppliers sup ON sup.id = po.supplier_id
             LEFT JOIN sales_orders so ON s.reference_type = 'SO' AND so.id = s.reference_id
             LEFT JOIN customers cus ON cus.id = so.customer_id
             LEFT JOIN inventory_operations op ON s.reference_type IN ('ADJUSTMENT', 'TRANSFER', 'SUPPLIER_RETURN', 'CUSTOMER_RETURN') AND op.id = s.reference_id
             WHERE s.lot_id = :id ORDER BY s.id"
        );
        $statement->execute(['id' => $id]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function lotTransfers(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.*, o.status, o.dispatched_at, o.received_at, sw.name AS source_name, dw.name AS destination_name
             FROM inventory_operation_lots t INNER JOIN inventory_operations o ON o.id = t.operation_id
             INNER JOIN warehouses sw ON sw.id = o.warehouse_id INNER JOIN warehouses dw ON dw.id = o.destination_id
             WHERE t.source_lot_id = :source_id OR t.destination_lot_id = :destination_id ORDER BY t.id'
        );
        $statement->execute(['source_id' => $id, 'destination_id' => $id]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sameCodeLots(string $code, int $exceptId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.lot_code, l.expiry_date, l.quantity, p.sku, w.name AS warehouse_name FROM stock_lots l
             INNER JOIN products p ON p.id = l.product_id INNER JOIN warehouses w ON w.id = l.warehouse_id
             WHERE l.lot_code = :code AND l.id <> :id ORDER BY p.sku, w.name, l.id LIMIT 100'
        );
        $statement->execute(['code' => $code, 'id' => $exceptId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Every lot with stock and an expiry date, with days left and status. */
    private function source(): string
    {
        return 'SELECT l.id, l.product_id, l.warehouse_id, p.sku, p.name AS product_name, p.unit AS base_unit, w.name AS warehouse_name, l.lot_code, l.expiry_date,
                       l.received_at, l.unit_cost, l.quantity, DATEDIFF(l.expiry_date, ' . ExpirySql::today() . ') AS days_left
                FROM stock_lots l INNER JOIN products p ON p.id = l.product_id INNER JOIN warehouses w ON w.id = l.warehouse_id
                WHERE l.quantity > 0 AND l.expiry_date IS NOT NULL';
    }

    /** @param array{warehouse_id?: int, status?: string, q?: string} $filters @return array{0: string, 1: array<string, int|string>} */
    private function where(array $filters, int $nearDays): array
    {
        $clauses = [];
        $params = [];
        $status = $filters['status'] ?? 'flagged';
        if ($status === 'expired') {
            $clauses[] = 'days_left < 0';
        } elseif ($status === 'near') {
            $clauses[] = 'days_left BETWEEN 0 AND :near_days';
            $params['near_days'] = $nearDays;
        } elseif ($status === 'flagged') {
            $clauses[] = 'days_left <= :near_days';
            $params['near_days'] = $nearDays;
        }
        if (($filters['warehouse_id'] ?? 0) > 0) {
            $clauses[] = 'warehouse_id = :warehouse_id';
            $params['warehouse_id'] = (int) $filters['warehouse_id'];
        }
        if (($filters['q'] ?? '') !== '') {
            $clauses[] = "(sku LIKE :q_sku ESCAPE '!' OR product_name LIKE :q_name ESCAPE '!' OR lot_code LIKE :q_code ESCAPE '!')";
            $pattern = $this->pattern((string) $filters['q']);
            $params += ['q_sku' => $pattern, 'q_name' => $pattern, 'q_code' => $pattern];
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function pattern(string $query): string
    {
        return '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
