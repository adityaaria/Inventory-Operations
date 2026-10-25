<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use PDO;

/**
 * Read-only invariant checks for partial sales order fulfilment (D-14 / ADR-020), shared by the migration runner, the
 * seed summary and the integration tests.
 *
 * - issued ledger: every sales order line's issued_quantity equals the sum of its Issue ledger rows
 *   (reference SO/order id, same product, source warehouse); customer returns are separate Adjustment rows.
 * - status: Fulfilled ⇔ every line complete; PartiallyFulfilled ⇒ something issued and something left;
 *   any other status ⇒ nothing issued.
 * - closures: only PartiallyFulfilled orders have a closure, and closed_units equals their remaining quantity.
 */
final class PartialFulfilmentChecks
{
    public const ISSUED_LEDGER_MISMATCHES = "SELECT COUNT(*) FROM sales_order_items soi
        INNER JOIN sales_orders so ON so.id = soi.sales_order_id
        LEFT JOIN (SELECT reference_id, product_id, warehouse_id, SUM(quantity) AS issued FROM stock_ledger
                   WHERE movement_type = 'Issue' AND reference_type = 'SO' GROUP BY reference_id, product_id, warehouse_id) l
            ON l.reference_id = so.id AND l.product_id = soi.product_id AND l.warehouse_id = so.source_warehouse_id
        WHERE soi.issued_quantity <> COALESCE(l.issued, 0)";

    public const STATUS_MISMATCHES = "SELECT COUNT(*) FROM (SELECT so.status, SUM(soi.issued_quantity) AS issued, SUM(soi.quantity - soi.issued_quantity) AS remaining
            FROM sales_orders so INNER JOIN sales_order_items soi ON soi.sales_order_id = so.id GROUP BY so.id, so.status) o
        WHERE (o.status = 'Fulfilled' AND o.remaining <> 0)
           OR (o.status = 'PartiallyFulfilled' AND (o.issued = 0 OR o.remaining = 0))
           OR (o.status NOT IN ('Fulfilled', 'PartiallyFulfilled') AND o.issued <> 0)";

    public const CLOSURE_MISMATCHES = "SELECT COUNT(*) FROM sales_order_closures c
        INNER JOIN sales_orders so ON so.id = c.sales_order_id
        WHERE so.status <> 'PartiallyFulfilled'
           OR c.closed_units <> (SELECT SUM(soi.quantity - soi.issued_quantity) FROM sales_order_items soi WHERE soi.sales_order_id = so.id)";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{issued_ledger_mismatches: int, fulfilment_status_mismatches: int, closure_mismatches: int} */
    public function report(): array
    {
        return [
            'issued_ledger_mismatches' => $this->count(self::ISSUED_LEDGER_MISMATCHES),
            'fulfilment_status_mismatches' => $this->count(self::STATUS_MISMATCHES),
            'closure_mismatches' => $this->count(self::CLOSURE_MISMATCHES),
        ];
    }

    private function count(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
