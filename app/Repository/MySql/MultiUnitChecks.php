<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use PDO;

/**
 * Read-only invariant checks for units of measure (D-16 / ADR-022), shared by the migration runner, the seed summary and
 * the integration tests. Every count must be 0.
 *
 * - line units: on every PO/SO/stock-operation line, base quantity = unit_quantity × unit_factor;
 * - whole units: received (PO) and issued (SO) base quantities are whole line units (receipts and shipments are entered
 *   in the line unit);
 * - base unit: every product has exactly one base unit row, with factor 1 and unit_code = products.unit.
 */
final class MultiUnitChecks
{
    public const LINE_UNIT_MISMATCHES = 'SELECT (SELECT COUNT(*) FROM purchase_order_items WHERE unit_quantity IS NULL OR unit_factor IS NULL OR quantity <> unit_quantity * unit_factor)
        + (SELECT COUNT(*) FROM sales_order_items WHERE unit_quantity IS NULL OR unit_factor IS NULL OR quantity <> unit_quantity * unit_factor)
        + (SELECT COUNT(*) FROM inventory_operation_items WHERE unit_quantity IS NULL OR unit_factor IS NULL OR quantity <> unit_quantity * unit_factor)';

    public const WHOLE_UNIT_MISMATCHES = 'SELECT (SELECT COUNT(*) FROM purchase_order_items WHERE MOD(received_quantity, unit_factor) <> 0)
        + (SELECT COUNT(*) FROM sales_order_items WHERE MOD(issued_quantity, unit_factor) <> 0)';

    public const BASE_UNIT_MISMATCHES = 'SELECT COUNT(*) FROM products p
        LEFT JOIN (SELECT product_id, COUNT(*) AS bases, MIN(unit_code) AS code, MAX(factor_to_base) AS factor FROM product_units WHERE is_base = 1 GROUP BY product_id) b ON b.product_id = p.id
        WHERE b.bases IS NULL OR b.bases <> 1 OR b.factor <> 1 OR b.code <> p.unit';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{line_unit_mismatches: int, whole_unit_mismatches: int, base_unit_mismatches: int} */
    public function report(): array
    {
        return [
            'line_unit_mismatches' => $this->count(self::LINE_UNIT_MISMATCHES),
            'whole_unit_mismatches' => $this->count(self::WHOLE_UNIT_MISMATCHES),
            'base_unit_mismatches' => $this->count(self::BASE_UNIT_MISMATCHES),
        ];
    }

    private function count(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
