<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Exception\PersistenceException;
use App\Repository\Contract\DeliveryNoteRepositoryInterface;
use App\Support\PaginatedResult;
use PDO;

/** Delivery notes (D-19, ADR-025). Prepared statements only; writes join the caller's transaction. */
final class MySqlDeliveryNoteRepository implements DeliveryNoteRepositoryInterface
{
    private const HEADER = 'SELECT dn.id, dn.delivery_number, dn.sales_order_id, so.order_number, so.created_by AS sales_created_by, dn.shipment_number,
            dn.warehouse_id, w.name AS warehouse_name, w.location AS warehouse_location, dn.customer_id, dn.ship_to_name, dn.ship_to_address, dn.vehicle, dn.driver,
            dn.prepared_by, u.name AS prepared_by_name, dn.shipped_at, dn.origin,
            (SELECT COALESCE(SUM(l.quantity), 0) FROM delivery_note_lines l WHERE l.delivery_note_id = dn.id) AS units,
            inv.id AS invoice_id, inv.invoice_number
        FROM delivery_notes dn
        JOIN sales_orders so ON so.id = dn.sales_order_id
        JOIN warehouses w ON w.id = dn.warehouse_id
        JOIN users u ON u.id = dn.prepared_by
        LEFT JOIN invoices inv ON inv.active_delivery_note_id = dn.id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function nextShipmentNumber(int $salesOrderId): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(shipment_number), 0) + 1 FROM delivery_notes WHERE sales_order_id = :id');
        $statement->execute(['id' => $salesOrderId]);

        return (int) $statement->fetchColumn();
    }

    public function create(array $note, array $lines): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new PersistenceException('Delivery notes are created inside the shipment transaction.');
        }
        $header = $this->pdo->prepare(
            'INSERT INTO delivery_notes (delivery_number, sales_order_id, shipment_number, warehouse_id, customer_id, ship_to_name, ship_to_address, vehicle, driver, prepared_by, shipped_at, origin)
             VALUES (:number, :so, :shipment, :warehouse, :customer, :name, :address, :vehicle, :driver, :prepared_by, COALESCE(:shipped_at, CURRENT_TIMESTAMP), :origin)'
        );
        $header->execute([
            'number' => $note['delivery_number'], 'so' => $note['sales_order_id'], 'shipment' => $note['shipment_number'], 'warehouse' => $note['warehouse_id'],
            'customer' => $note['customer_id'], 'name' => $note['ship_to_name'], 'address' => $note['ship_to_address'], 'vehicle' => $note['vehicle'], 'driver' => $note['driver'],
            'prepared_by' => $note['prepared_by'], 'shipped_at' => $note['shipped_at'] ?? null, 'origin' => $note['origin'] ?? 'Issue',
        ]);
        $noteId = (int) $this->pdo->lastInsertId();
        $line = $this->pdo->prepare(
            'INSERT INTO delivery_note_lines (delivery_note_id, sales_order_item_id, product_id, unit_code, unit_factor, unit_quantity, quantity)
             VALUES (:note, :item, :product, :unit, :factor, :unit_quantity, :quantity)'
        );
        // Unlinked Issue rows of this order for the product and lot: inside the shipment transaction (order locked) these
        // are exactly the rows this shipment just wrote.
        $ledger = $this->pdo->prepare(
            "SELECT l.id, l.lot_id, l.quantity FROM stock_ledger l LEFT JOIN delivery_note_lots x ON x.ledger_id = l.id
             WHERE l.movement_type = 'Issue' AND l.reference_type = 'SO' AND l.reference_id = :so AND l.product_id = :product
               AND l.lot_id <=> :lot AND l.quantity = :quantity AND x.id IS NULL ORDER BY l.id DESC LIMIT 1"
        );
        $lot = $this->pdo->prepare(
            'INSERT INTO delivery_note_lots (delivery_note_id, delivery_note_line_id, ledger_id, lot_id, lot_code, expiry_date, quantity)
             VALUES (:note, :line, :ledger, :lot, :code, :expiry, :quantity)'
        );
        foreach ($lines as $row) {
            $line->execute(['note' => $noteId, 'item' => $row['sales_order_item_id'], 'product' => $row['product_id'], 'unit' => $row['unit_code'],
                'factor' => $row['unit_factor'], 'unit_quantity' => $row['unit_quantity'], 'quantity' => $row['quantity']]);
            $lineId = (int) $this->pdo->lastInsertId();
            $lotUnits = 0;
            foreach ($row['lots'] as $entry) {
                $ledgerId = $entry['ledger_id'] ?? null;
                if ($ledgerId === null) {
                    $ledger->execute(['so' => $note['sales_order_id'], 'product' => $row['product_id'], 'lot' => $entry['lot_id'], 'quantity' => $entry['quantity']]);
                    $found = $ledger->fetchColumn();
                    if ($found === false) {
                        throw new PersistenceException('The Issue ledger row of this shipment could not be found for the delivery note.');
                    }
                    $ledgerId = (int) $found;
                }
                $lot->execute(['note' => $noteId, 'line' => $lineId, 'ledger' => $ledgerId, 'lot' => $entry['lot_id'], 'code' => $entry['lot_code'], 'expiry' => $entry['expiry_date'], 'quantity' => $entry['quantity']]);
                $lotUnits += $entry['quantity'];
            }
            if ($lotUnits !== $row['quantity']) {
                throw new PersistenceException('Delivery note lots do not add up to the shipped quantity.');
            }
        }

        return $noteId;
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::HEADER . ' WHERE dn.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::header($row) + ['lines' => $this->lines($id)];
    }

    public function lock(int $id): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new PersistenceException('Delivery note locks require a transaction.');
        }
        $statement = $this->pdo->prepare('SELECT id FROM delivery_notes WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() === false ? null : $this->find($id);
    }

    public function forSalesOrder(int $salesOrderId): array
    {
        $statement = $this->pdo->prepare(self::HEADER . ' WHERE dn.sales_order_id = :id ORDER BY dn.shipment_number ASC');
        $statement->execute(['id' => $salesOrderId]);

        return array_map(self::header(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function search(array $filters, int $page, int $perPage, ?int $salesCreatedBy = null): PaginatedResult
    {
        $where = [];
        $params = [];
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $where[] = '(dn.delivery_number LIKE :t1 OR so.order_number LIKE :t2 OR dn.ship_to_name LIKE :t3)';
            $params += ['t1' => '%' . $term . '%', 't2' => '%' . $term . '%', 't3' => '%' . $term . '%'];
        }
        if (($filters['invoiced'] ?? '') === 'yes') {
            $where[] = 'inv.id IS NOT NULL';
        } elseif (($filters['invoiced'] ?? '') === 'no') {
            $where[] = 'inv.id IS NULL';
        }
        if ($salesCreatedBy !== null) {
            $where[] = 'so.created_by = :creator';
            $params['creator'] = $salesCreatedBy;
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM delivery_notes dn JOIN sales_orders so ON so.id = dn.sales_order_id LEFT JOIN invoices inv ON inv.active_delivery_note_id = dn.id' . $clause);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));
        $statement = $this->pdo->prepare(self::HEADER . $clause . ' ORDER BY dn.shipped_at DESC, dn.id DESC LIMIT :limit OFFSET :offset');
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

    /** @return list<array<string, mixed>> */
    private function lines(int $noteId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.sales_order_item_id, l.product_id, p.sku, p.name AS product_name, p.unit AS base_unit, l.unit_code, l.unit_factor, l.unit_quantity, l.quantity
             FROM delivery_note_lines l JOIN products p ON p.id = l.product_id WHERE l.delivery_note_id = :id ORDER BY l.id ASC'
        );
        $statement->execute(['id' => $noteId]);
        $lines = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lines[(int) $row['id']] = [
                'id' => (int) $row['id'], 'sales_order_item_id' => (int) $row['sales_order_item_id'], 'product_id' => (int) $row['product_id'], 'sku' => (string) $row['sku'],
                'product_name' => (string) $row['product_name'], 'base_unit' => (string) $row['base_unit'], 'unit_code' => (string) $row['unit_code'], 'unit_factor' => (int) $row['unit_factor'],
                'unit_quantity' => (int) $row['unit_quantity'], 'quantity' => (int) $row['quantity'], 'lots' => [],
            ];
        }
        $lots = $this->pdo->prepare('SELECT delivery_note_line_id, ledger_id, lot_id, lot_code, expiry_date, quantity FROM delivery_note_lots WHERE delivery_note_id = :id ORDER BY id ASC');
        $lots->execute(['id' => $noteId]);
        foreach ($lots->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lines[(int) $row['delivery_note_line_id']]['lots'][] = [
                'ledger_id' => (int) $row['ledger_id'], 'lot_id' => $row['lot_id'] === null ? null : (int) $row['lot_id'], 'lot_code' => $row['lot_code'] === null ? null : (string) $row['lot_code'],
                'expiry_date' => $row['expiry_date'] === null ? null : (string) $row['expiry_date'], 'quantity' => (int) $row['quantity'],
            ];
        }

        return array_values($lines);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function header(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'delivery_number' => (string) $row['delivery_number'], 'sales_order_id' => (int) $row['sales_order_id'],
            'order_number' => (string) $row['order_number'], 'sales_created_by' => (int) $row['sales_created_by'], 'shipment_number' => (int) $row['shipment_number'],
            'warehouse_id' => (int) $row['warehouse_id'], 'warehouse_name' => (string) $row['warehouse_name'], 'warehouse_location' => (string) $row['warehouse_location'],
            'customer_id' => (int) $row['customer_id'], 'ship_to_name' => (string) $row['ship_to_name'], 'ship_to_address' => (string) $row['ship_to_address'],
            'vehicle' => $row['vehicle'] === null ? null : (string) $row['vehicle'], 'driver' => $row['driver'] === null ? null : (string) $row['driver'],
            'prepared_by' => (int) $row['prepared_by'], 'prepared_by_name' => (string) $row['prepared_by_name'], 'shipped_at' => (string) $row['shipped_at'],
            'origin' => (string) $row['origin'], 'units' => (int) $row['units'],
            'invoice_id' => $row['invoice_id'] === null ? null : (int) $row['invoice_id'], 'invoice_number' => $row['invoice_number'] === null ? null : (string) $row['invoice_number'],
        ];
    }
}
