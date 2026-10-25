<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Exception\InfrastructureException;
use PDO;

/**
 * Data step of the multiple-units migration (D-16 / ADR-022), used by scripts/migrate-multi-unit.php and its tests.
 *
 * Before: products.unit (e.g. "karton") was the unit of every stored quantity. After: every stored quantity is in the
 * product's base unit, the smallest unit. Where the pieces per unit are known from the product name ("… (48 pcs)",
 * "… (4 pak)", as the FMCG seed generator writes them), the product is converted:
 *   - base unit = pcs / pak (factor 1); the old unit becomes a unit with that factor and keeps the old master prices as
 *     its own per-unit prices (purchase and sales default);
 *   - products.unit/purchase_price/selling_price/price become the base unit and its derived price ROUND(old / factor, 2)
 *     (MySQL DECIMAL rounding, half away from zero);
 *   - every historical integer quantity of that product × factor: product_stocks.quantity/reserved_quantity/
 *     reorder_point_override, products.reorder_point, stock_ledger quantity/quantity_delta, PO/SO line quantity/received/
 *     issued, stock-operation line quantity/baseline/dispatched/received/damaged/short, inventory_return_totals;
 *     sales_order_closures.closed_units is recomputed from its lines;
 *   - PO/SO/operation lines get the unit snapshot (old unit, factor, old quantity), so line prices (per old unit) and
 *     line totals in Rupiah stay exactly the same.
 * Products without a known factor keep their unit as the base unit (factor 1) and their lines get (unit, 1, quantity).
 * Audit metadata keeps the numbers recorded at the time (history is never rewritten).
 *
 * One transaction: lock products and stock rows, convert, re-check every invariant and the value preservation, then
 * record schema version 'multi-unit-1' in the same transaction. Any failed check rolls everything back. Re-running after
 * success is a no-op (the version row is present).
 */
final class MultiUnitMigration
{
    public const VERSION = 'multi-unit-1';
    public const NAME_PATTERN = '/\((\d{1,6})\s+(pcs|pak)\)\s*$/iuD';
    private const MAX_QUANTITY = 4294967295;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function applied(): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM schema_versions WHERE version = ?');
        $statement->execute([self::VERSION]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Products to convert: name ends with "(N pcs|pak)", N ≥ 2, unit differs from that word, no unit rows yet.
     *
     * @return list<array{id: int, sku: string, name: string, unit: string, factor: int, base: string}>
     */
    public function plan(): array
    {
        $hasUnits = $this->tableExists('product_units');
        $rows = $this->rows('SELECT id, sku, name, unit FROM products ORDER BY id');
        $withUnits = $hasUnits ? array_map(static fn (array $row): int => (int) $row['product_id'], $this->rows('SELECT DISTINCT product_id FROM product_units')) : [];
        $plan = [];
        foreach ($rows as $row) {
            if (preg_match(self::NAME_PATTERN, (string) $row['name'], $match) !== 1) {
                continue;
            }
            $factor = (int) $match[1];
            $base = strtolower($match[2]);
            if ($factor < 2 || strcasecmp((string) $row['unit'], $base) === 0 || in_array((int) $row['id'], $withUnits, true)) {
                continue;
            }
            $plan[] = ['id' => (int) $row['id'], 'sku' => (string) $row['sku'], 'name' => (string) $row['name'], 'unit' => (string) $row['unit'], 'factor' => $factor, 'base' => $base];
        }

        return $plan;
    }

    /** Read-only report: what apply() would change, plus invariants and money totals before. @return array<string, mixed> */
    public function dryRun(): array
    {
        $plan = $this->plan();
        $factors = [];
        foreach ($plan as $product) {
            $factors[$product['factor']] = ($factors[$product['factor']] ?? 0) + 1;
        }
        ksort($factors);
        $converted = array_column($plan, 'id');
        $kept = $this->rows('SELECT sku, name, unit FROM products ORDER BY id');
        $kept = array_values(array_filter($kept, static fn (array $row): bool => !in_array($row['sku'], array_column($plan, 'sku'), true)));
        [$valueBefore, $valueAfter, $bound] = $this->projectedStockValue($plan);

        return [
            'mode' => 'dry-run',
            'already_applied' => $this->applied(),
            'products_total' => (int) $this->scalar('SELECT COUNT(*) FROM products'),
            'products_to_convert' => count($plan),
            'products_by_factor' => $factors,
            'products_kept_as_base' => count($kept),
            'kept_as_base' => array_map(static fn (array $row): string => $row['sku'] . ' (' . $row['unit'] . ') ' . $row['name'], $kept),
            'conversion_samples' => array_map(fn (array $product): array => $this->sample($product), array_slice($plan, 0, 5)),
            'rows_to_multiply' => $this->rowCounts($converted),
            'unit_rows_to_insert' => count($plan) * 2 + ((int) $this->scalar('SELECT COUNT(*) FROM products') - count($plan)),
            'quantity_overflow_rows' => $this->overflowRows($plan),
            'invariants_before' => $this->invariants(false),
            'stock_value' => ['before' => round($valueBefore, 2), 'projected_after' => round($valueAfter, 2), 'difference' => round($valueAfter - $valueBefore, 2), 'rounding_bound' => round($bound, 2)],
            'line_totals' => $this->lineTotalSums(false),
        ];
    }

    /**
     * Converts in one transaction and verifies before commit. @return array<string, mixed>
     * @throws InfrastructureException when a check fails (nothing is changed)
     */
    public function apply(): array
    {
        if ($this->applied()) {
            return ['status' => 'already-applied', 'checks' => $this->invariants(true) + (new MultiUnitChecks($this->pdo))->report()];
        }
        if (!$this->tableExists('product_units') || !$this->columnExists('purchase_order_items', 'unit_code') || !$this->columnExists('sales_order_items', 'unit_code') || !$this->columnExists('inventory_operation_items', 'unit_code')) {
            throw new InfrastructureException('Run database/migrations/20261012-multi-unit.sql first.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->rows('SELECT id FROM products ORDER BY id FOR UPDATE');
            $this->rows('SELECT product_id FROM product_stocks ORDER BY product_id, warehouse_id FOR UPDATE');
            $before = $this->invariants(false);
            $poTotals = $this->lineTotals('purchase_order_items', 'purchase_price', false);
            $soTotals = $this->lineTotals('sales_order_items', 'selling_price', false);
            $plan = $this->plan();
            [$valueBefore, , $bound] = $this->projectedStockValue($plan);
            if ($this->overflowRows($plan) > 0) {
                throw new InfrastructureException('A converted quantity would exceed the column range.');
            }
            // D-20 / ADR-026: valued ledger rows keep cost_amount = quantity × unit_cost (CHECK); rescaling them would
            // change their cents through rounding. Unit conversion therefore has to run before the valuation migration.
            $planIds = array_map(static fn (array $entry): int => (int) $entry['id'], $plan);
            if ($planIds !== [] && $this->columnExists('stock_ledger', 'cost_amount')
                && (int) $this->scalar('SELECT COUNT(*) FROM stock_ledger WHERE cost_amount IS NOT NULL AND product_id IN (' . implode(', ', $planIds) . ')') > 0) {
                throw new InfrastructureException('Valued ledger rows exist for products to convert; run the unit conversion before the valuation migration (ADR-026).');
            }
            $this->convert($plan);

            $after = $this->invariants(true) + (new MultiUnitChecks($this->pdo))->report();
            $valueAfter = (float) $this->scalar('SELECT COALESCE(SUM(ps.quantity * p.purchase_price), 0) FROM product_stocks ps INNER JOIN products p ON p.id = ps.product_id');
            $changedPo = $this->changedLineTotals($poTotals, $this->lineTotals('purchase_order_items', 'purchase_price', true));
            $changedSo = $this->changedLineTotals($soTotals, $this->lineTotals('sales_order_items', 'selling_price', true));
            $report = [
                'status' => 'applied',
                'products_converted' => count($plan),
                'invariants_before' => $before,
                'invariants_after' => $after,
                'stock_value' => ['before' => round($valueBefore, 2), 'after' => round($valueAfter, 2), 'difference' => round($valueAfter - $valueBefore, 2), 'rounding_bound' => round($bound, 2)],
                'line_totals_changed' => ['purchase_order_items' => $changedPo, 'sales_order_items' => $changedSo],
                'line_totals' => $this->lineTotalSums(true),
            ];
            $failed = array_sum($after) !== 0 || array_sum($before) !== 0 || $changedPo !== 0 || $changedSo !== 0 || abs($valueAfter - $valueBefore) > $bound + 0.01;
            if ($failed) {
                $this->pdo->rollBack();

                return ['status' => 'conflict'] + $report;
            }
            $this->pdo->prepare("INSERT INTO schema_versions (version, description) VALUES (?, 'Multiple units of measure with conversion; stock in base units (D-16, ADR-022)') ON DUPLICATE KEY UPDATE description = VALUES(description)")->execute([self::VERSION]);
            $this->pdo->commit();

            return $report;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Stock, reservation, fulfilment, transit and sequence invariants (all must be 0).
     * @return array<string, int>
     */
    public function invariants(bool $withUnits): array
    {
        $checks = [
            'ledger_mismatches' => (int) $this->scalar("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type = 'Receipt' THEN quantity WHEN movement_type = 'Issue' THEN -quantity ELSE COALESCE(quantity_delta, 0) END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)"),
            'reserved_above_quantity' => (int) $this->scalar('SELECT COUNT(*) FROM product_stocks WHERE reserved_quantity > quantity'),
            'reservation_mismatches' => (new StockReservationReconciler($this->pdo))->mismatches(),
            'sequence_mismatches' => (int) $this->scalar("SELECT COUNT(*) FROM (SELECT 'PO' AS p, SUBSTRING(order_number, 4, 4) AS per, CAST(SUBSTRING(order_number, 9) AS UNSIGNED) AS n FROM purchase_orders WHERE order_number REGEXP '^PO-[0-9]{4}-[0-9]{4,10}$' UNION ALL SELECT 'SO', SUBSTRING(order_number, 4, 4), CAST(SUBSTRING(order_number, 9) AS UNSIGNED) FROM sales_orders WHERE order_number REGEXP '^SO-[0-9]{4}-[0-9]{4,10}$') x LEFT JOIN document_sequences s ON s.prefix = x.p AND s.period = x.per WHERE s.last_number IS NULL OR x.n > s.last_number"),
        ] + (new PartialFulfilmentChecks($this->pdo))->report() + (new TransferTransitChecks($this->pdo))->report();
        if (!$withUnits) {
            return $checks;
        }

        return $checks + ['price_override_without_reason' => (int) $this->scalar('SELECT (SELECT COUNT(*) FROM purchase_order_items WHERE (purchase_price <> list_price AND price_reason IS NULL) OR (purchase_price = list_price AND price_reason IS NOT NULL)) + (SELECT COUNT(*) FROM sales_order_items WHERE (selling_price <> list_price AND price_reason IS NULL) OR (selling_price = list_price AND price_reason IS NOT NULL))')];
    }

    /** @param list<array{id: int, sku: string, name: string, unit: string, factor: int, base: string}> $plan */
    private function convert(array $plan): void
    {
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS multi_unit_plan');
        $this->pdo->exec('CREATE TEMPORARY TABLE multi_unit_plan (product_id INT UNSIGNED PRIMARY KEY, factor INT UNSIGNED NOT NULL, base_code VARCHAR(30) NOT NULL, old_unit VARCHAR(30) NOT NULL) ENGINE=InnoDB');
        $insert = $this->pdo->prepare('INSERT INTO multi_unit_plan (product_id, factor, base_code, old_unit) VALUES (?, ?, ?, ?)');
        foreach ($plan as $product) {
            $insert->execute([$product['id'], $product['factor'], $product['base'], $product['unit']]);
        }
        $converted = 'SELECT product_id FROM multi_unit_plan';
        // Every statement assigns columns that do not read one another, so MySQL's assignment order never matters.
        $this->pdo->exec('UPDATE product_stocks ps INNER JOIN multi_unit_plan m ON m.product_id = ps.product_id SET ps.quantity = ps.quantity * m.factor, ps.reserved_quantity = ps.reserved_quantity * m.factor, ps.reorder_point_override = ps.reorder_point_override * m.factor, ps.updated_at = ps.updated_at');
        $this->pdo->exec('UPDATE stock_ledger l INNER JOIN multi_unit_plan m ON m.product_id = l.product_id SET l.quantity = l.quantity * m.factor, l.quantity_delta = l.quantity_delta * CAST(m.factor AS SIGNED)');
        $this->pdo->exec('UPDATE inventory_return_totals t INNER JOIN stock_ledger l ON l.id = t.source_ledger_id INNER JOIN multi_unit_plan m ON m.product_id = l.product_id SET t.returned_quantity = t.returned_quantity * m.factor');
        // D-17 / ADR-023: where lots already exist (a database migrated out of order), lots and transfer lot rows scale too,
        // and a lot's unit cost becomes per base unit; Σ lots = stock and lot = opening + ledger keep holding.
        if ((int) $this->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'stock_lots'") === 1) {
            $this->pdo->exec('UPDATE stock_lots l INNER JOIN multi_unit_plan m ON m.product_id = l.product_id SET l.quantity = l.quantity * m.factor, l.opening_quantity = l.opening_quantity * m.factor, l.unit_cost = ROUND(l.unit_cost / m.factor, 2), l.updated_at = l.updated_at');
            $this->pdo->exec('UPDATE inventory_operation_lots t INNER JOIN multi_unit_plan m ON m.product_id = t.product_id SET t.dispatched_quantity = t.dispatched_quantity * m.factor, t.received_quantity = t.received_quantity * m.factor, t.damaged_quantity = t.damaged_quantity * m.factor, t.short_quantity = t.short_quantity * m.factor, t.unit_cost = ROUND(t.unit_cost / m.factor, 2)');
        }
        // Order and operation lines: first the unit snapshot (old unit, factor, quantity as entered), then base quantities.
        foreach (['purchase_order_items' => 'received_quantity = received_quantity * unit_factor', 'sales_order_items' => 'issued_quantity = issued_quantity * unit_factor'] as $table => $progress) {
            $this->pdo->exec("UPDATE {$table} i INNER JOIN multi_unit_plan m ON m.product_id = i.product_id SET i.unit_code = m.old_unit, i.unit_factor = m.factor, i.unit_quantity = i.quantity, i.updated_at = i.updated_at WHERE i.unit_code IS NULL");
            $this->pdo->exec("UPDATE {$table} SET quantity = unit_quantity * unit_factor, {$progress}, updated_at = updated_at WHERE product_id IN ({$converted}) AND unit_factor > 1 AND quantity = unit_quantity");
            $this->pdo->exec("UPDATE {$table} i INNER JOIN products p ON p.id = i.product_id SET i.unit_code = p.unit, i.unit_factor = 1, i.unit_quantity = i.quantity, i.updated_at = i.updated_at WHERE i.unit_code IS NULL");
        }
        $this->pdo->exec('UPDATE inventory_operation_items i INNER JOIN multi_unit_plan m ON m.product_id = i.product_id SET i.unit_code = m.old_unit, i.unit_factor = m.factor, i.unit_quantity = i.quantity WHERE i.unit_code IS NULL');
        $this->pdo->exec("UPDATE inventory_operation_items SET quantity = unit_quantity * unit_factor, baseline = baseline * unit_factor, dispatched_quantity = dispatched_quantity * unit_factor, received_quantity = received_quantity * unit_factor, damaged_quantity = damaged_quantity * unit_factor, short_quantity = short_quantity * unit_factor WHERE product_id IN ({$converted}) AND unit_factor > 1 AND quantity = unit_quantity");
        $this->pdo->exec('UPDATE inventory_operation_items i INNER JOIN products p ON p.id = i.product_id SET i.unit_code = p.unit, i.unit_factor = 1, i.unit_quantity = i.quantity WHERE i.unit_code IS NULL');
        $this->pdo->exec("UPDATE sales_order_closures c SET c.closed_units = (SELECT SUM(i.quantity - i.issued_quantity) FROM sales_order_items i WHERE i.sales_order_id = c.sales_order_id) WHERE c.sales_order_id IN (SELECT DISTINCT i.sales_order_id FROM sales_order_items i WHERE i.product_id IN ({$converted}))");
        // Units: the old unit keeps the old master prices as its own per-unit prices; then the product moves to the base.
        $this->pdo->exec('INSERT INTO product_units (product_id, unit_code, factor_to_base, purchase_price, selling_price, is_base, is_purchase_default, is_sales_default) SELECT p.id, p.unit, m.factor, p.purchase_price, p.selling_price, 0, 1, 1 FROM products p INNER JOIN multi_unit_plan m ON m.product_id = p.id');
        $this->pdo->exec('UPDATE products p INNER JOIN multi_unit_plan m ON m.product_id = p.id SET p.unit = m.base_code, p.purchase_price = ROUND(p.purchase_price / m.factor, 2), p.selling_price = ROUND(p.selling_price / m.factor, 2), p.price = ROUND(p.price / m.factor, 2), p.reorder_point = p.reorder_point * m.factor, p.updated_at = p.updated_at');
        $this->pdo->exec('INSERT INTO product_units (product_id, unit_code, factor_to_base, is_base) SELECT p.id, p.unit, 1, 1 FROM products p WHERE NOT EXISTS (SELECT 1 FROM product_units u WHERE u.product_id = p.id AND u.is_base = 1)');
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS multi_unit_plan');
    }

    /** @param array{id: int, sku: string, name: string, unit: string, factor: int, base: string} $product @return array<string, mixed> */
    private function sample(array $product): array
    {
        $statement = $this->pdo->prepare('SELECT purchase_price, selling_price, reorder_point, (SELECT COALESCE(SUM(quantity), 0) FROM product_stocks WHERE product_id = p.id) AS stock FROM products p WHERE id = ?');
        $statement->execute([$product['id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'sku' => $product['sku'],
            'from' => '1 ' . $product['unit'] . ' = ' . $product['factor'] . ' ' . $product['base'],
            'stock' => (int) ($row['stock'] ?? 0) . ' ' . $product['unit'] . ' -> ' . (int) ($row['stock'] ?? 0) * $product['factor'] . ' ' . $product['base'],
            'reorder_point' => (int) ($row['reorder_point'] ?? 0) . ' -> ' . (int) ($row['reorder_point'] ?? 0) * $product['factor'],
            'purchase_price' => $row['purchase_price'] . ' per ' . $product['unit'] . ' (kept on the unit) -> ' . number_format(round((float) $row['purchase_price'] / $product['factor'], 2), 2, '.', '') . ' per ' . $product['base'],
            'selling_price' => $row['selling_price'] . ' per ' . $product['unit'] . ' (kept on the unit) -> ' . number_format(round((float) $row['selling_price'] / $product['factor'], 2), 2, '.', '') . ' per ' . $product['base'],
        ];
    }

    /** @param list<int> $converted @return array<string, int> */
    private function rowCounts(array $converted): array
    {
        if ($converted === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $converted));

        return [
            'product_stocks' => (int) $this->scalar("SELECT COUNT(*) FROM product_stocks WHERE product_id IN ({$in})"),
            'stock_ledger' => (int) $this->scalar("SELECT COUNT(*) FROM stock_ledger WHERE product_id IN ({$in})"),
            'purchase_order_items' => (int) $this->scalar("SELECT COUNT(*) FROM purchase_order_items WHERE product_id IN ({$in})"),
            'sales_order_items' => (int) $this->scalar("SELECT COUNT(*) FROM sales_order_items WHERE product_id IN ({$in})"),
            'inventory_operation_items' => (int) $this->scalar("SELECT COUNT(*) FROM inventory_operation_items WHERE product_id IN ({$in})"),
            'inventory_return_totals' => (int) $this->scalar("SELECT COUNT(*) FROM inventory_return_totals t INNER JOIN stock_ledger l ON l.id = t.source_ledger_id WHERE l.product_id IN ({$in})"),
            'sales_order_closures_recomputed' => (int) $this->scalar("SELECT COUNT(*) FROM sales_order_closures c WHERE c.sales_order_id IN (SELECT sales_order_id FROM sales_order_items WHERE product_id IN ({$in}))"),
            'products_reorder_points' => count($converted),
            'reorder_point_overrides' => (int) $this->scalar("SELECT COUNT(*) FROM product_stocks WHERE reorder_point_override IS NOT NULL AND product_id IN ({$in})"),
        ];
    }

    /** @param list<array{id: int, sku: string, name: string, unit: string, factor: int, base: string}> $plan */
    private function overflowRows(array $plan): int
    {
        $count = 0;
        $check = $this->pdo->prepare('SELECT (SELECT COUNT(*) FROM product_stocks WHERE product_id = :p1 AND quantity > :m1)
            + (SELECT COUNT(*) FROM stock_ledger WHERE product_id = :p2 AND quantity > :m2)
            + (SELECT COUNT(*) FROM purchase_order_items WHERE product_id = :p3 AND quantity > :m3)
            + (SELECT COUNT(*) FROM sales_order_items WHERE product_id = :p4 AND quantity > :m4)
            + (SELECT COUNT(*) FROM products WHERE id = :p5 AND reorder_point > :m5)');
        foreach ($plan as $product) {
            $limit = intdiv(self::MAX_QUANTITY, $product['factor']);
            $check->execute(['p1' => $product['id'], 'm1' => $limit, 'p2' => $product['id'], 'm2' => $limit, 'p3' => $product['id'], 'm3' => $limit, 'p4' => $product['id'], 'm4' => $limit, 'p5' => $product['id'], 'm5' => $limit]);
            $count += (int) $check->fetchColumn();
        }

        return $count;
    }

    /**
     * Stock value Σ quantity × purchase price (base) before, projected after (quantity × factor × ROUND(price / factor, 2))
     * and the rounding bound Σ converted base quantity × 0.005.
     *
     * @param list<array{id: int, sku: string, name: string, unit: string, factor: int, base: string}> $plan
     * @return array{0: float, 1: float, 2: float}
     */
    private function projectedStockValue(array $plan): array
    {
        $factors = array_column($plan, 'factor', 'id');
        $before = 0;
        $after = 0;
        $bound = 0.0;
        foreach ($this->rows('SELECT ps.product_id, ps.quantity, p.purchase_price FROM product_stocks ps INNER JOIN products p ON p.id = ps.product_id') as $row) {
            $quantity = (int) $row['quantity'];
            $cents = (int) round((float) $row['purchase_price'] * 100);
            $before += $quantity * $cents;
            $factor = $factors[(int) $row['product_id']] ?? 1;
            $after += $quantity * $factor * (int) round($cents / $factor, 0, PHP_ROUND_HALF_UP);
            $bound += $factor > 1 ? $quantity * $factor * 0.005 : 0.0;
        }

        return [$before / 100, $after / 100, $bound];
    }

    /** @return array<int, int> line id => total in cents (before: quantity × price; after: unit_quantity × price) */
    private function lineTotals(string $table, string $priceColumn, bool $converted): array
    {
        $quantity = $converted ? 'unit_quantity' : 'quantity';
        $rows = $this->rows("SELECT id, {$quantity} AS q, {$priceColumn} AS price FROM {$table}");
        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['id']] = (int) $row['q'] * (int) round((float) $row['price'] * 100);
        }

        return $totals;
    }

    /** @param array<int, int> $before @param array<int, int> $after */
    private function changedLineTotals(array $before, array $after): int
    {
        $changed = 0;
        foreach ($before as $id => $total) {
            if (($after[$id] ?? null) !== $total) {
                $changed++;
            }
        }

        return $changed;
    }

    /** @return array<string, float> Σ line totals in Rupiah per document type */
    private function lineTotalSums(bool $converted): array
    {
        $quantity = $converted ? 'unit_quantity' : 'quantity';

        return [
            'purchase_orders' => round((float) $this->scalar("SELECT COALESCE(SUM({$quantity} * purchase_price), 0) FROM purchase_order_items"), 2),
            'sales_orders' => round((float) $this->scalar("SELECT COALESCE(SUM({$quantity} * selling_price), 0) FROM sales_order_items"), 2),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? [] : array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? null : $statement->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }
}
