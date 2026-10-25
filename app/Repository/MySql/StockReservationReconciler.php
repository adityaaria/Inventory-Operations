<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use PDO;
use Throwable;

/**
 * Reservation invariant and migration step for D-12 / ADR-018, extended by D-14 / ADR-020 (partial fulfilment).
 *
 * Invariant: for every product/warehouse pair, product_stocks.reserved_quantity = Σ (quantity − issued_quantity) of the
 * lines of Approved and PartiallyFulfilled sales orders sourced from that warehouse whose remaining fulfilment is not
 * closed (sales_order_closures). Independent of the ledger reconciliation (quantity = Σ ledger).
 *
 * reconcile() sets reserved_quantity from those orders in one transaction. If any pair would need more than its
 * physical quantity (an order that was already short), it changes nothing and returns the conflicting pairs with
 * their order numbers: the policy is to fail with a report, never to clamp silently.
 *
 * On a database that has not yet received the partial-fulfilment migration (no issued_quantity column), the D-12
 * formula (Σ quantity of Approved lines) is used, so the earlier stock-reservation runner keeps working in sequence.
 */
final class StockReservationReconciler
{
    /** Orders that hold a reservation: open remainder of Approved/PartiallyFulfilled orders (D-12, D-14). */
    public const RESERVING_ORDERS = "FROM sales_orders so INNER JOIN sales_order_items soi ON soi.sales_order_id = so.id
        LEFT JOIN sales_order_closures soc ON soc.sales_order_id = so.id
        WHERE so.status IN ('Approved', 'PartiallyFulfilled') AND soc.sales_order_id IS NULL AND soi.issued_quantity < soi.quantity";

    /** Expected reservation per pair: unissued, unclosed remainder of Approved and PartiallyFulfilled orders. */
    public const EXPECTED = 'SELECT so.source_warehouse_id AS warehouse_id, soi.product_id, SUM(soi.quantity - soi.issued_quantity) AS reserved
        ' . self::RESERVING_ORDERS . ' GROUP BY so.source_warehouse_id, soi.product_id';

    /** D-12 formula before the partial-fulfilment migration (no issued_quantity / closures yet). */
    public const LEGACY_EXPECTED = "SELECT so.source_warehouse_id AS warehouse_id, soi.product_id, SUM(soi.quantity) AS reserved
        FROM sales_orders so INNER JOIN sales_order_items soi ON soi.sales_order_id = so.id
        WHERE so.status = 'Approved' GROUP BY so.source_warehouse_id, soi.product_id";

    /** Pairs breaking the invariant: stored value differs, or a reserving line has no stock row at all. */
    public const MISMATCHES = 'SELECT
        (SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (' . self::EXPECTED . ') e
            ON e.product_id = ps.product_id AND e.warehouse_id = ps.warehouse_id
         WHERE ps.reserved_quantity <> COALESCE(e.reserved, 0))
      + (SELECT COUNT(*) FROM (' . self::EXPECTED . ') e LEFT JOIN product_stocks ps
            ON ps.product_id = e.product_id AND ps.warehouse_id = e.warehouse_id
         WHERE ps.product_id IS NULL)';

    private ?bool $partialSchema = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function mismatches(): int
    {
        $statement = $this->pdo->query(str_replace(self::EXPECTED, $this->expected(), self::MISMATCHES));

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    /**
     * @return array{status: 'ready'|'conflict', updated_pairs: int, reserved_units: int, conflicts: list<array{warehouse_id: int, warehouse: string, product_id: int, sku: string, quantity: int, required: int, orders: list<string>}>}
     */
    public function reconcile(): array
    {
        $this->pdo->beginTransaction();
        try {
            // Same lock order as the application (ADR-004): source orders first, then stock rows in a fixed order.
            $this->lockAll($this->partialSchema()
                ? "SELECT id FROM sales_orders WHERE status IN ('Approved', 'PartiallyFulfilled') ORDER BY id FOR UPDATE"
                : "SELECT id FROM sales_orders WHERE status = 'Approved' ORDER BY id FOR UPDATE");
            $this->lockAll('SELECT product_id FROM product_stocks ORDER BY product_id, warehouse_id FOR UPDATE');
            $conflicts = $this->conflicts();
            if ($conflicts !== []) {
                $this->pdo->rollBack();

                return ['status' => 'conflict', 'updated_pairs' => 0, 'reserved_units' => 0, 'conflicts' => $conflicts];
            }
            $updated = $this->pdo->exec('UPDATE product_stocks ps LEFT JOIN (' . $this->expected() . ') e
                ON e.product_id = ps.product_id AND e.warehouse_id = ps.warehouse_id
                SET ps.reserved_quantity = COALESCE(e.reserved, 0)
                WHERE ps.reserved_quantity <> COALESCE(e.reserved, 0)');
            $units = $this->pdo->query('SELECT COALESCE(SUM(reserved_quantity), 0) FROM product_stocks');
            $total = $units === false ? 0 : (int) $units->fetchColumn();
            $this->pdo->commit();

            return ['status' => 'ready', 'updated_pairs' => (int) $updated, 'reserved_units' => $total, 'conflicts' => []];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** True once the partial-fulfilment migration (D-14) added issued_quantity and sales_order_closures. */
    private function partialSchema(): bool
    {
        if ($this->partialSchema === null) {
            $statement = $this->pdo->query("SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_order_items' AND column_name = 'issued_quantity')
                + (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sales_order_closures')");
            $this->partialSchema = $statement !== false && (int) $statement->fetchColumn() === 2;
        }

        return $this->partialSchema;
    }

    private function expected(): string
    {
        return $this->partialSchema() ? self::EXPECTED : self::LEGACY_EXPECTED;
    }

    private function lockAll(string $sql): void
    {
        $statement = $this->pdo->query($sql);
        if ($statement !== false) {
            $statement->fetchAll();
        }
    }

    /** @return list<array{warehouse_id: int, warehouse: string, product_id: int, sku: string, quantity: int, required: int, orders: list<string>}> */
    private function conflicts(): array
    {
        $orders = $this->partialSchema()
            ? 'SELECT GROUP_CONCAT(DISTINCT so.order_number ORDER BY so.order_number SEPARATOR \',\') ' . self::RESERVING_ORDERS . ' AND so.source_warehouse_id = e.warehouse_id AND soi.product_id = e.product_id'
            : 'SELECT GROUP_CONCAT(so.order_number ORDER BY so.order_number SEPARATOR \',\') FROM sales_orders so
                 INNER JOIN sales_order_items soi ON soi.sales_order_id = so.id
                 WHERE so.status = \'Approved\' AND so.source_warehouse_id = e.warehouse_id AND soi.product_id = e.product_id';
        $statement = $this->pdo->query('SELECT e.warehouse_id, w.name AS warehouse, e.product_id, p.sku, COALESCE(ps.quantity, 0) AS quantity, e.reserved AS required,
                (' . $orders . ') AS orders
            FROM (' . $this->expected() . ') e
            INNER JOIN products p ON p.id = e.product_id INNER JOIN warehouses w ON w.id = e.warehouse_id
            LEFT JOIN product_stocks ps ON ps.product_id = e.product_id AND ps.warehouse_id = e.warehouse_id
            WHERE ps.product_id IS NULL OR e.reserved > ps.quantity
            ORDER BY e.warehouse_id, p.sku');
        if ($statement === false) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'warehouse_id' => (int) $row['warehouse_id'],
            'warehouse' => (string) $row['warehouse'],
            'product_id' => (int) $row['product_id'],
            'sku' => (string) $row['sku'],
            'quantity' => (int) $row['quantity'],
            'required' => (int) $row['required'],
            'orders' => explode(',', (string) $row['orders']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
