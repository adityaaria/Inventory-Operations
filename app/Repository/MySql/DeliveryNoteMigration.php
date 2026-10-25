<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\StockLedgerEntry;
use App\Support\OrderNumber;
use App\Support\ShipmentHistory;
use PDO;
use Throwable;

/**
 * Data step of the sales-documents migration (D-19, ADR-025): one delivery note per historical SO shipment, so that every
 * SO Issue ledger row is on exactly one note. Shipments are the Issue rows that are not yet on a note, grouped per order
 * exactly as the SO detail page groups them (ShipmentHistory: same time and performer, consecutive, a repeated product
 * lot starts a new shipment). Notes are created in shipment time order, numbered DO-YYMM-NNNN from the sequence of the
 * shipment's month (the ADR-017 clock), origin = Migration, shipped_at/prepared_by from the ledger, ship-to = the
 * customer's current name and address (history does not record the address of that time). No vehicle or driver.
 * No invoice is created. One transaction: any conflict or failed invariant rolls everything back.
 */
final class DeliveryNoteMigration
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Read-only preview. @return array<string, mixed> */
    public function dryRun(): array
    {
        $plan = $this->plan();

        return ['mode' => 'dry-run', 'orders' => $plan['orders'], 'shipments_to_create' => count($plan['shipments']), 'issue_rows_to_link' => $plan['rows'],
            'conflicts' => $plan['conflicts'], 'first_shipments' => array_map(static fn (array $shipment): array => ['sales_order_id' => $shipment['sales_order_id'],
                'shipped_at' => $shipment['at'], 'lines' => count($shipment['lines'])], array_slice($plan['shipments'], 0, 5))];
    }

    /** @return array<string, mixed> report with status ready|failed */
    public function apply(): array
    {
        $this->pdo->beginTransaction();
        try {
            // Serialize with shipments: lock every order that has an unlinked Issue row, in id order (ADR-004 document lock).
            $locked = $this->pdo->query("SELECT DISTINCT l.reference_id FROM stock_ledger l LEFT JOIN delivery_note_lots x ON x.ledger_id = l.id
                WHERE l.movement_type = 'Issue' AND l.reference_type = 'SO' AND x.id IS NULL ORDER BY l.reference_id");
            $lock = $this->pdo->prepare('SELECT id FROM sales_orders WHERE id = :id FOR UPDATE');
            foreach ($locked === false ? [] : $locked->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
                $lock->execute(['id' => (int) $orderId]);
            }
            $plan = $this->plan();
            if ($plan['conflicts'] !== []) {
                $this->pdo->rollBack();

                return ['status' => 'failed', 'reason' => 'conflicts', 'conflicts' => $plan['conflicts'], 'shipments_to_create' => count($plan['shipments'])];
            }
            $notes = new MySqlDeliveryNoteRepository($this->pdo);
            $sequences = $this->pdo->prepare(
                'INSERT INTO document_sequences (prefix, period, last_number) VALUES (:prefix, :period, LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
            );
            $created = 0;
            foreach ($plan['shipments'] as $shipment) {
                $sequences->execute(['prefix' => OrderNumber::DELIVERY, 'period' => $shipment['period']]);
                $number = OrderNumber::format(OrderNumber::DELIVERY, $shipment['period'], (int) $this->pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn());
                $notes->create([
                    'delivery_number' => $number,
                    'sales_order_id' => $shipment['sales_order_id'],
                    'shipment_number' => $notes->nextShipmentNumber($shipment['sales_order_id']),
                    'warehouse_id' => $shipment['warehouse_id'],
                    'customer_id' => $shipment['customer_id'],
                    'ship_to_name' => $shipment['customer_name'],
                    'ship_to_address' => $shipment['customer_address'],
                    'vehicle' => null,
                    'driver' => null,
                    'prepared_by' => $shipment['performed_by'],
                    'shipped_at' => $shipment['at'],
                    'origin' => 'Migration',
                ], $shipment['lines']);
                $created++;
            }
            $checks = (new SalesDocumentChecks($this->pdo))->report();
            $partial = (new PartialFulfilmentChecks($this->pdo))->report();
            $failed = array_filter($checks + $partial, static fn (int $count): bool => $count !== 0);
            if ($failed !== []) {
                $this->pdo->rollBack();

                return ['status' => 'failed', 'reason' => 'invariants', 'checks' => $checks, 'partial_fulfilment_checks' => $partial, 'shipments_to_create' => count($plan['shipments'])];
            }
            $this->pdo->commit();

            return ['status' => 'ready', 'delivery_notes_created' => $created, 'issue_rows_linked' => $plan['rows'], 'orders' => $plan['orders'], 'checks' => $checks];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @return array{orders: int, rows: int, conflicts: list<string>, shipments: list<array<string, mixed>>}
     */
    private function plan(): array
    {
        // The dry run also works before the DDL: without the delivery note tables no Issue row is linked yet.
        $tables = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'delivery_note_lots'");
        $linked = $tables !== false && (int) $tables->fetchColumn() === 1;
        $statement = $this->pdo->query(
            "SELECT l.id, l.product_id, l.warehouse_id, l.quantity, l.reference_id, l.performed_by, l.created_at, l.lot_id, sl.lot_code, sl.expiry_date,
                    so.customer_id, c.name AS customer_name, c.address AS customer_address
             FROM stock_ledger l
             JOIN sales_orders so ON so.id = l.reference_id
             JOIN customers c ON c.id = so.customer_id
             LEFT JOIN stock_lots sl ON sl.id = l.lot_id
             " . ($linked ? 'LEFT JOIN delivery_note_lots x ON x.ledger_id = l.id' : '') . "
             WHERE l.movement_type = 'Issue' AND l.reference_type = 'SO'" . ($linked ? ' AND x.id IS NULL' : '') . "
             ORDER BY l.reference_id ASC, l.id ASC"
        );
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        $byOrder = [];
        foreach ($rows as $row) {
            $byOrder[(int) $row['reference_id']][] = $row;
        }
        $items = $this->pdo->prepare('SELECT id, product_id, unit_code, unit_factor FROM sales_order_items WHERE sales_order_id = :id');
        $shipments = [];
        $conflicts = [];
        foreach ($byOrder as $orderId => $orderRows) {
            $items->execute(['id' => $orderId]);
            $itemsByProduct = [];
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $itemsByProduct[(int) $item['product_id']] = $item;
            }
            $byId = [];
            $entries = [];
            foreach ($orderRows as $row) {
                $byId[(int) $row['id']] = $row;
                $entries[] = new StockLedgerEntry((int) $row['id'], (int) $row['product_id'], (int) $row['warehouse_id'], 'Issue', (int) $row['quantity'], 'SO', $orderId,
                    (int) $row['performed_by'], null, (string) $row['created_at'], $row['lot_id'] === null ? null : (int) $row['lot_id']);
            }
            foreach (ShipmentHistory::group($entries) as $group) {
                $first = $byId[$group['lines'][0]->id()];
                $lines = [];
                foreach ($group['lines'] as $entry) {
                    $row = $byId[$entry->id()];
                    $item = $itemsByProduct[$entry->productId()] ?? null;
                    if ($item === null) {
                        $conflicts[] = 'SO #' . $orderId . ' Issue row #' . $entry->id() . ': product #' . $entry->productId() . ' is not on the order';
                        continue;
                    }
                    $key = (int) $item['id'];
                    $lines[$key] ??= ['sales_order_item_id' => $key, 'product_id' => $entry->productId(), 'unit_code' => (string) $item['unit_code'],
                        'unit_factor' => max(1, (int) $item['unit_factor']), 'unit_quantity' => 0, 'quantity' => 0, 'lots' => []];
                    $lines[$key]['quantity'] += $entry->quantity();
                    $lines[$key]['lots'][] = ['ledger_id' => $entry->id(), 'lot_id' => $entry->lotId(), 'lot_code' => $row['lot_code'] === null ? null : (string) $row['lot_code'],
                        'expiry_date' => $row['expiry_date'] === null ? null : (string) $row['expiry_date'], 'quantity' => $entry->quantity()];
                }
                foreach ($lines as $key => $line) {
                    if ($line['quantity'] % $line['unit_factor'] !== 0) {
                        $conflicts[] = 'SO #' . $orderId . ' shipment at ' . $group['at'] . ': ' . $line['quantity'] . ' base units is not a whole number of ' . $line['unit_code'] . ' (factor ' . $line['unit_factor'] . ')';
                    }
                    $lines[$key]['unit_quantity'] = intdiv($line['quantity'], $line['unit_factor']);
                }
                $shipments[] = [
                    'sales_order_id' => $orderId, 'at' => $group['at'], 'first_ledger_id' => $group['lines'][0]->id(), 'performed_by' => $group['performed_by'],
                    'warehouse_id' => (int) $first['warehouse_id'], 'customer_id' => (int) $first['customer_id'], 'customer_name' => (string) $first['customer_name'],
                    'customer_address' => (string) $first['customer_address'], 'period' => substr(str_replace('-', '', $group['at']), 2, 4), 'lines' => array_values($lines),
                ];
            }
        }
        // Chronological numbering: DO numbers rise with the shipment time.
        usort($shipments, static fn (array $a, array $b): int => [$a['at'], $a['first_ledger_id']] <=> [$b['at'], $b['first_ledger_id']]);

        return ['orders' => count($byOrder), 'rows' => count($rows), 'conflicts' => $conflicts, 'shipments' => $shipments];
    }
}
