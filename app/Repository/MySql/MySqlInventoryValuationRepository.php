<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\InventoryValuationRepositoryInterface;
use PDO;

/**
 * Prepared read queries of the FIFO-per-batch valuation (D-20, ADR-026). Every amount read from the ledger is converted
 * to integer cents in SQL (cost_amount has exactly two decimals, so ROUND(x * 100) is exact).
 */
final class MySqlInventoryValuationRepository implements InventoryValuationRepositoryInterface
{
    /** Signed base quantity of a ledger row (Receipt +, Issue −, Adjustment = quantity_delta). */
    public const SIGNED = "CASE s.movement_type WHEN 'Receipt' THEN CAST(s.quantity AS SIGNED) WHEN 'Issue' THEN -CAST(s.quantity AS SIGNED) ELSE s.quantity_delta END";
    public const CENTS = 'CAST(ROUND(s.cost_amount * 100) AS SIGNED)';
    /** "Now" for the as-of formulas: later than any stored TIMESTAMP. */
    private const FOREVER = '2999-12-31 23:59:59';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Business date (APP_TIMEZONE) of the database session clock, as the other reports use. */
    public function today(): string
    {
        $statement = $this->pdo->query('SELECT ' . ExpirySql::today());

        return $statement === false ? \App\Support\BusinessDate::today() : (string) $statement->fetchColumn();
    }

    public function valuationStart(): array
    {
        $statement = $this->pdo->query('SELECT w.legacy_ledger_max_id AS watermark,
                CASE WHEN w.legacy_ledger_max_id = 0 THEN NULL
                     ELSE GREATEST(w.created_at, COALESCE((SELECT MAX(x.created_at) FROM stock_ledger x WHERE x.id <= w.legacy_ledger_max_id), w.created_at)) END AS started_at
            FROM stock_lot_watermark w WHERE w.id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['watermark' => 0, 'started_at' => null];
        }

        return ['watermark' => (int) $row['watermark'], 'started_at' => $row['started_at'] === null ? null : (string) $row['started_at']];
    }

    public function lots(?string $atUtc, array $filters): array
    {
        $params = [];
        $where = $this->filterWhere($filters, $params, 'l.warehouse_id', 'l.lot_code');
        if ($atUtc === null) {
            $join = '';
            $after = '0 AS units_after, 0 AS cents_after';
            $where[] = 'l.quantity > 0';
        } else {
            $join = 'LEFT JOIN (SELECT s.lot_id, SUM(' . self::SIGNED . ') AS units, SUM(' . self::CENTS . ') AS cents FROM stock_ledger s
                WHERE s.lot_id IS NOT NULL AND s.created_at >= :at GROUP BY s.lot_id) a ON a.lot_id = l.id';
            $after = 'COALESCE(a.units, 0) AS units_after, COALESCE(a.cents, 0) AS cents_after';
            $params['at'] = $atUtc;
        }
        $statement = $this->pdo->prepare('SELECT l.id AS lot_id, l.lot_code, l.expiry_date, l.product_id, p.sku, p.name AS product_name, p.unit AS base_unit,
                p.category_id, c.name AS category_name, l.warehouse_id, w.name AS warehouse_name, l.unit_cost, l.quantity, l.opening_quantity, ' . $after . '
            FROM stock_lots l INNER JOIN products p ON p.id = l.product_id INNER JOIN categories c ON c.id = p.category_id INNER JOIN warehouses w ON w.id = l.warehouse_id
            ' . $join . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . '
            ORDER BY w.name, p.sku, l.lot_seq');
        $statement->execute($params);

        return array_map(static fn (array $row): array => [
            'lot_id' => (int) $row['lot_id'], 'lot_code' => (string) $row['lot_code'], 'expiry_date' => $row['expiry_date'] === null ? null : (string) $row['expiry_date'],
            'product_id' => (int) $row['product_id'], 'sku' => (string) $row['sku'], 'product_name' => (string) $row['product_name'], 'base_unit' => (string) $row['base_unit'],
            'category_id' => (int) $row['category_id'], 'category_name' => (string) $row['category_name'], 'warehouse_id' => (int) $row['warehouse_id'], 'warehouse_name' => (string) $row['warehouse_name'],
            'unit_cost' => (string) $row['unit_cost'], 'quantity' => (int) $row['quantity'], 'opening_quantity' => (int) $row['opening_quantity'],
            'units_after' => (int) $row['units_after'], 'cents_after' => (int) $row['cents_after'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function inTransit(?string $atUtc, array $filters): array
    {
        $at = $atUtc ?? self::FOREVER;
        $params = ['at1' => $at, 'at2' => $at, 'at3' => $at];
        // No filter on dispatched_at: the source ledger row decides (the same timestamps as the movement report).
        $where = ["o.kind = 'Transfer'", 'o.dispatched_at IS NOT NULL'];
        if (($filters['warehouse_id'] ?? 0) > 0) {
            $where[] = '(o.warehouse_id = :warehouse_a OR o.destination_id = :warehouse_b)';
            $params['warehouse_a'] = $params['warehouse_b'] = (int) $filters['warehouse_id'];
        }
        $where = array_merge($where, $this->productWhere($filters, $params, 't.lot_code'));
        $statement = $this->pdo->prepare("SELECT * FROM (SELECT t.operation_id, t.product_id, p.sku, p.name AS product_name, p.category_id, c.name AS category_name,
                o.warehouse_id AS source_warehouse_id, ws.name AS source_name, o.destination_id AS destination_warehouse_id, wd.name AS destination_name,
                t.lot_code, t.expiry_date, t.unit_cost, o.dispatched_at,
                (CASE WHEN EXISTS (SELECT 1 FROM stock_ledger s WHERE s.reference_type = 'TRANSFER' AND s.reference_id = t.operation_id AND s.lot_id = t.source_lot_id
                        AND s.quantity_delta < 0 AND s.created_at < :at1) THEN CAST(t.dispatched_quantity AS SIGNED) ELSE 0 END)
                - (CASE WHEN t.destination_lot_id IS NOT NULL AND EXISTS (SELECT 1 FROM stock_ledger s WHERE s.reference_type = 'TRANSFER' AND s.reference_id = t.operation_id
                        AND s.lot_id = t.destination_lot_id AND s.quantity_delta > 0 AND s.created_at < :at2) THEN CAST(COALESCE(t.received_quantity, 0) AS SIGNED) ELSE 0 END)
                - (CASE WHEN o.received_at IS NOT NULL AND o.received_at < :at3 THEN CAST(COALESCE(t.damaged_quantity, 0) + COALESCE(t.short_quantity, 0) AS SIGNED) ELSE 0 END) AS units
            FROM inventory_operation_lots t INNER JOIN inventory_operations o ON o.id = t.operation_id
            INNER JOIN products p ON p.id = t.product_id INNER JOIN categories c ON c.id = p.category_id
            INNER JOIN warehouses ws ON ws.id = o.warehouse_id INNER JOIN warehouses wd ON wd.id = o.destination_id
            WHERE " . implode(' AND ', $where) . ") x WHERE x.units <> 0 ORDER BY x.dispatched_at, x.operation_id, x.sku, x.lot_code");
        $statement->execute($params);

        return array_map(static fn (array $row): array => [
            'operation_id' => (int) $row['operation_id'], 'product_id' => (int) $row['product_id'], 'sku' => (string) $row['sku'], 'product_name' => (string) $row['product_name'],
            'category_id' => (int) $row['category_id'], 'category_name' => (string) $row['category_name'],
            'source_warehouse_id' => (int) $row['source_warehouse_id'], 'source_name' => (string) $row['source_name'],
            'destination_warehouse_id' => (int) $row['destination_warehouse_id'], 'destination_name' => (string) $row['destination_name'],
            'lot_code' => (string) $row['lot_code'], 'expiry_date' => $row['expiry_date'] === null ? null : (string) $row['expiry_date'],
            'unit_cost' => (string) $row['unit_cost'], 'units' => (int) $row['units'], 'dispatched_at' => $row['dispatched_at'] === null ? null : (string) $row['dispatched_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function unvaluedInTransitUnits(?string $atUtc): int
    {
        $at = $atUtc ?? self::FOREVER;
        $statement = $this->pdo->prepare("SELECT COALESCE(SUM(i.dispatched_quantity), 0) FROM inventory_operation_items i INNER JOIN inventory_operations o ON o.id = i.operation_id
            WHERE o.kind = 'Transfer' AND o.dispatched_at IS NOT NULL AND o.dispatched_at < :at1 AND (o.received_at IS NULL OR o.received_at >= :at2)
              AND NOT EXISTS (SELECT 1 FROM inventory_operation_lots t WHERE t.operation_id = o.id)");
        $statement->execute(['at1' => $at, 'at2' => $at]);

        return (int) $statement->fetchColumn();
    }

    public function movementTotals(string $fromUtc, string $toUtc): array
    {
        $statement = $this->pdo->prepare("SELECT s.warehouse_id,
                CASE WHEN s.movement_type = 'Receipt' THEN 'receipt'
                     WHEN s.movement_type = 'Issue' THEN 'issue'
                     WHEN s.reference_type = 'TRANSFER' AND s.quantity_delta > 0 THEN
                        IF(EXISTS (SELECT 1 FROM inventory_operation_lots t WHERE t.operation_id = s.reference_id), 'transfer_in', 'transfer_in_unvalued')
                     WHEN s.reference_type = 'TRANSFER' THEN 'transfer_out'
                     WHEN s.reference_type = 'STOCK_COUNT' THEN 'count'
                     WHEN s.reference_type = 'SUPPLIER_RETURN' THEN 'supplier_return'
                     WHEN s.reference_type = 'CUSTOMER_RETURN' THEN 'customer_return'
                     WHEN s.reference_type = 'ADJUSTMENT' AND o.purpose = 'ExpiredWriteOff' THEN 'write_off'
                     WHEN s.reference_type = 'ADJUSTMENT' THEN 'adjustment'
                     ELSE 'other' END AS class,
                SUM(" . self::SIGNED . ') AS units, SUM(' . self::CENTS . ") AS cents
            FROM stock_ledger s LEFT JOIN inventory_operations o ON s.reference_type = 'ADJUSTMENT' AND o.id = s.reference_id
            WHERE s.cost_amount IS NOT NULL AND s.created_at >= :from_at AND s.created_at < :to_at
            GROUP BY s.warehouse_id, class ORDER BY s.warehouse_id, class");
        $statement->execute(['from_at' => $fromUtc, 'to_at' => $toUtc]);

        return array_map(static fn (array $row): array => ['warehouse_id' => (int) $row['warehouse_id'], 'class' => (string) $row['class'], 'units' => (int) $row['units'], 'cents' => (int) $row['cents']],
            $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function unvaluedMovements(string $fromUtc, string $toUtc): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM stock_ledger WHERE cost_amount IS NULL AND created_at >= :from_at AND created_at < :to_at');
        $statement->execute(['from_at' => $fromUtc, 'to_at' => $toUtc]);

        return (int) $statement->fetchColumn();
    }

    public function losses(string $fromUtc, string $toUtc): array
    {
        $ledger = static fn (string $kind, string $join, string $condition, string $n): string => "SELECT '{$kind}' AS kind, s.created_at AS occurred_at, s.reference_type AS document_type, s.reference_id AS document_id,
                s.warehouse_id, w.name AS warehouse_name, s.product_id, p.sku, p.name AS product_name, l.lot_code, -s.quantity_delta AS units, s.unit_cost, -" . self::CENTS . " AS ledger_cents
            FROM stock_ledger s {$join} INNER JOIN stock_lots l ON l.id = s.lot_id INNER JOIN warehouses w ON w.id = s.warehouse_id INNER JOIN products p ON p.id = s.product_id
            WHERE {$condition} AND s.cost_amount IS NOT NULL AND s.quantity_delta < 0 AND s.created_at >= :from_{$n} AND s.created_at < :to_{$n}";
        $transit = static fn (string $kind, string $column, string $n): string => "SELECT '{$kind}' AS kind, o.received_at AS occurred_at, 'TRANSFER' AS document_type, o.id AS document_id,
                o.destination_id AS warehouse_id, w.name AS warehouse_name, t.product_id, p.sku, p.name AS product_name, t.lot_code, t.{$column} AS units, t.unit_cost, NULL AS ledger_cents
            FROM inventory_operation_lots t INNER JOIN inventory_operations o ON o.id = t.operation_id INNER JOIN warehouses w ON w.id = o.destination_id INNER JOIN products p ON p.id = t.product_id
            WHERE o.status = 'Received' AND t.{$column} > 0 AND o.received_at >= :from_{$n} AND o.received_at < :to_{$n}";
        $statement = $this->pdo->prepare('SELECT * FROM (' . implode(' UNION ALL ', [
            $ledger('ExpiredWriteOff', "INNER JOIN inventory_operations o ON o.id = s.reference_id AND o.purpose = 'ExpiredWriteOff'", "s.reference_type = 'ADJUSTMENT'", 'a'),
            $transit('TransitDamage', 'damaged_quantity', 'b'),
            $transit('TransitShortage', 'short_quantity', 'c'),
            $ledger('CountShortfall', '', "s.reference_type = 'STOCK_COUNT'", 'd'),
        ]) . ') x ORDER BY x.occurred_at, x.kind, x.document_id, x.sku, x.lot_code');
        $params = [];
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $params['from_' . $n] = $fromUtc;
            $params['to_' . $n] = $toUtc;
        }
        $statement->execute($params);

        return array_map(static fn (array $row): array => [
            'kind' => (string) $row['kind'], 'occurred_at' => (string) $row['occurred_at'], 'document_type' => (string) $row['document_type'], 'document_id' => (int) $row['document_id'],
            'warehouse_id' => (int) $row['warehouse_id'], 'warehouse_name' => (string) $row['warehouse_name'], 'product_id' => (int) $row['product_id'], 'sku' => (string) $row['sku'],
            'product_name' => (string) $row['product_name'], 'lot_code' => (string) $row['lot_code'], 'units' => (int) $row['units'], 'unit_cost' => (string) $row['unit_cost'],
            'ledger_cents' => $row['ledger_cents'] === null ? null : (int) $row['ledger_cents'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** COGS (cents) and unvalued units of a delivery-note line, from its lot rows' Issue ledger rows. */
    private const LINE_COGS = '(SELECT COALESCE(SUM(-CAST(ROUND(s.cost_amount * 100) AS SIGNED)), 0) FROM delivery_note_lots x INNER JOIN stock_ledger s ON s.id = x.ledger_id
            WHERE x.delivery_note_line_id = dnl.id AND s.cost_amount IS NOT NULL) AS cogs_cents,
        (SELECT COALESCE(SUM(x.quantity), 0) FROM delivery_note_lots x INNER JOIN stock_ledger s ON s.id = x.ledger_id
            WHERE x.delivery_note_line_id = dnl.id AND s.cost_amount IS NULL) AS unvalued_units';

    public function marginLines(string $fromDate, string $toDate, array $filters): array
    {
        $params = ['from_date' => $fromDate, 'to_date' => $toDate];
        $where = ['i.voided_at IS NULL', 'i.invoice_date >= :from_date', 'i.invoice_date <= :to_date'];
        $where = array_merge($where, $this->partyWhere($filters, $params, 'i.customer_id'));
        $statement = $this->pdo->prepare('SELECT i.id AS invoice_id, i.invoice_number, i.invoice_date, i.customer_id, i.customer_name, i.sales_order_id, so.order_number,
                i.delivery_note_id, dn.delivery_number, il.id AS invoice_line_id, il.delivery_note_line_id, il.product_id, p.sku, p.name AS product_name, c.name AS category_name,
                il.unit_code, il.unit_quantity, dnl.quantity AS base_quantity, il.unit_price, CAST(ROUND(il.amount * 100) AS SIGNED) AS revenue_cents, ' . self::LINE_COGS . '
            FROM invoices i INNER JOIN invoice_lines il ON il.invoice_id = i.id INNER JOIN delivery_note_lines dnl ON dnl.id = il.delivery_note_line_id
            INNER JOIN delivery_notes dn ON dn.id = i.delivery_note_id INNER JOIN sales_orders so ON so.id = i.sales_order_id
            INNER JOIN products p ON p.id = il.product_id INNER JOIN categories c ON c.id = p.category_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY i.invoice_date, i.id, il.id');
        $statement->execute($params);

        return array_map(static fn (array $row): array => self::documentRow($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function shippedNotInvoiced(string $fromUtc, string $toUtc, array $filters): array
    {
        $params = ['from_at' => $fromUtc, 'to_at' => $toUtc];
        $where = ['inv.id IS NULL', 'dn.shipped_at >= :from_at', 'dn.shipped_at < :to_at'];
        $where = array_merge($where, $this->partyWhere($filters, $params, 'dn.customer_id'));
        $statement = $this->pdo->prepare('SELECT dn.id AS delivery_note_id, dn.delivery_number, dn.shipped_at, dn.customer_id, cu.name AS customer_name, dn.sales_order_id, so.order_number,
                dnl.id AS delivery_note_line_id, dnl.product_id, p.sku, p.name AS product_name, c.name AS category_name, dnl.unit_code, dnl.unit_quantity, dnl.quantity AS base_quantity,
                soi.selling_price AS unit_price, CAST(ROUND(dnl.unit_quantity * soi.selling_price * 100) AS SIGNED) AS revenue_cents, ' . self::LINE_COGS . '
            FROM delivery_note_lines dnl INNER JOIN delivery_notes dn ON dn.id = dnl.delivery_note_id INNER JOIN sales_orders so ON so.id = dn.sales_order_id
            INNER JOIN sales_order_items soi ON soi.id = dnl.sales_order_item_id INNER JOIN customers cu ON cu.id = dn.customer_id
            INNER JOIN products p ON p.id = dnl.product_id INNER JOIN categories c ON c.id = p.category_id
            LEFT JOIN invoices inv ON inv.active_delivery_note_id = dn.id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY dn.shipped_at, dn.id, dnl.id');
        $statement->execute($params);

        return array_map(static fn (array $row): array => self::documentRow($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function deliveryNoteIdsForOrder(int $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT id FROM delivery_notes WHERE sales_order_id = :id ORDER BY shipment_number');
        $statement->execute(['id' => $orderId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function documentLines(array $deliveryNoteIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $deliveryNoteIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare('SELECT dn.id AS delivery_note_id, dn.delivery_number, dn.shipment_number, dn.shipped_at, dn.sales_order_id, dnl.id AS delivery_note_line_id,
                dnl.sales_order_item_id, dnl.product_id, p.sku, p.name AS product_name, dnl.unit_code, dnl.unit_quantity, dnl.quantity AS base_quantity, soi.selling_price AS unit_price,
                inv.id AS invoice_id, inv.invoice_number,
                (SELECT CAST(ROUND(il.amount * 100) AS SIGNED) FROM invoice_lines il WHERE il.invoice_id = inv.id AND il.delivery_note_line_id = dnl.id) AS invoice_cents,
                CAST(ROUND(dnl.unit_quantity * soi.selling_price * 100) AS SIGNED) AS revenue_cents, ' . self::LINE_COGS . '
            FROM delivery_note_lines dnl INNER JOIN delivery_notes dn ON dn.id = dnl.delivery_note_id INNER JOIN sales_order_items soi ON soi.id = dnl.sales_order_item_id
            INNER JOIN products p ON p.id = dnl.product_id LEFT JOIN invoices inv ON inv.active_delivery_note_id = dn.id
            WHERE dn.id IN (' . $placeholders . ') ORDER BY dn.shipment_number, dn.id, dnl.id');
        $statement->execute($ids);
        $lines = $statement->fetchAll(PDO::FETCH_ASSOC);
        $lots = $this->pdo->prepare('SELECT x.delivery_note_line_id, x.lot_code, x.expiry_date, x.quantity, s.unit_cost,
                CASE WHEN s.cost_amount IS NULL THEN NULL ELSE -CAST(ROUND(s.cost_amount * 100) AS SIGNED) END AS cost_cents
            FROM delivery_note_lots x INNER JOIN stock_ledger s ON s.id = x.ledger_id WHERE x.delivery_note_id IN (' . $placeholders . ') ORDER BY x.id');
        $lots->execute($ids);
        $byLine = [];
        foreach ($lots->fetchAll(PDO::FETCH_ASSOC) as $lot) {
            $byLine[(int) $lot['delivery_note_line_id']][] = [
                'lot_code' => $lot['lot_code'] === null ? null : (string) $lot['lot_code'], 'expiry_date' => $lot['expiry_date'] === null ? null : (string) $lot['expiry_date'],
                'quantity' => (int) $lot['quantity'], 'unit_cost' => $lot['unit_cost'] === null ? null : (string) $lot['unit_cost'], 'cost_cents' => $lot['cost_cents'] === null ? null : (int) $lot['cost_cents'],
            ];
        }

        return array_map(static fn (array $row): array => self::documentRow($row) + [
            'delivery_note_id' => (int) $row['delivery_note_id'], 'shipment_number' => (int) $row['shipment_number'],
            'invoice_id' => $row['invoice_id'] === null ? null : (int) $row['invoice_id'], 'invoice_number' => $row['invoice_number'] === null ? null : (string) $row['invoice_number'],
            'invoice_cents' => $row['invoice_cents'] === null ? null : (int) $row['invoice_cents'], 'lots' => $byLine[(int) $row['delivery_note_line_id']] ?? [],
        ], $lines);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function documentRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = match (true) {
                $value === null => null,
                in_array($key, ['invoice_id', 'customer_id', 'sales_order_id', 'delivery_note_id', 'invoice_line_id', 'delivery_note_line_id', 'sales_order_item_id', 'product_id',
                    'unit_quantity', 'base_quantity', 'revenue_cents', 'cogs_cents', 'unvalued_units', 'shipment_number', 'invoice_cents'], true) => (int) $value,
                default => (string) $value,
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function filterWhere(array $filters, array &$params, string $warehouseColumn, string $lotColumn): array
    {
        $where = [];
        if (($filters['warehouse_id'] ?? 0) > 0) {
            $where[] = $warehouseColumn . ' = :warehouse_id';
            $params['warehouse_id'] = (int) $filters['warehouse_id'];
        }

        return array_merge($where, $this->productWhere($filters, $params, $lotColumn));
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function productWhere(array $filters, array &$params, string $lotColumn): array
    {
        $where = [];
        if (($filters['category_id'] ?? 0) > 0) {
            $where[] = 'p.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.sku LIKE :q_sku OR p.name LIKE :q_name OR ' . $lotColumn . ' LIKE :q_lot)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params['q_sku'] = $params['q_name'] = $params['q_lot'] = $like;
        }

        return $where;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function partyWhere(array $filters, array &$params, string $customerColumn): array
    {
        $where = [];
        if (($filters['customer_id'] ?? 0) > 0) {
            $where[] = $customerColumn . ' = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.sku LIKE :q_sku OR p.name LIKE :q_name)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params['q_sku'] = $params['q_name'] = $like;
        }

        return $where;
    }
}
