<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Customer;
use App\Entity\SalesOrder;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\MySql\MultiUnitChecks;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlBusinessOperationRepository;
use App\Repository\MySql\MySqlDocumentSequenceRepository;
use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Repository\MySql\MySqlOrderExceptionRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlProductUnitRepository;
use App\Repository\MySql\MySqlPurchaseOrderRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Repository\MySql\PartialFulfilmentChecks;
use App\Repository\MySql\StockReservationReconciler;
use App\Repository\MySql\TransferTransitChecks;
use App\Security\AuthContext;
use App\Service\MasterDataAuthorizationService;
use App\Service\OrderLinePricing;
use App\Service\ProductService;
use App\Service\ProductUnitCatalog;
use App\Service\ProductUnitService;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\ProductInput;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** D-16 / ADR-022 against real MySQL: seed invariants, orders in mixed units, DB guards, replenishment and the migration. */
final class MultiUnitIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MySqlStockRepository $balances;
    private StockService $stock;
    private MySqlProductRepository $products;
    private MySqlProductUnitRepository $units;
    private AuthContext $admin;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->balances = new MySqlStockRepository($this->pdo);
        $this->stock = new StockService($this->balances, new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
        $this->products = new MySqlProductRepository($this->pdo);
        $this->units = new MySqlProductUnitRepository($this->pdo);
        $this->admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
    }

    public function testSeedKeepsEveryUnitInvariant(): void
    {
        self::assertSame(['line_unit_mismatches' => 0, 'whole_unit_mismatches' => 0, 'base_unit_mismatches' => 0], (new MultiUnitChecks($this->pdo))->report());
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'multi-unit-1'"));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM (SELECT product_id FROM product_units WHERE is_base = 1 GROUP BY product_id HAVING COUNT(*) <> 1) x'));
        // Purchases mostly in cartons; sales mix cartons with pieces and inner boxes.
        self::assertGreaterThan(0.9, $this->scalar("SELECT SUM(unit_code IN ('karton', 'bal')) / COUNT(*) FROM purchase_order_items", true));
        foreach (['karton', 'pcs', 'box'] as $unit) {
            self::assertGreaterThan(50, $this->scalar("SELECT COUNT(*) FROM sales_order_items WHERE unit_code = '{$unit}'"), $unit);
        }
        // Every master-priced line's list price is its unit's master price (own price, else base × factor). Since D-19
        // (ADR-025) a customer price-list line's list price is its tier instead; those lines are checked by SalesDocumentChecks.
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM sales_order_items i JOIN products p ON p.id = i.product_id LEFT JOIN product_units u ON u.product_id = i.product_id AND u.unit_code = i.unit_code
            WHERE i.price_source = \'MASTER\' AND ROUND(i.list_price * 100) <> COALESCE(ROUND(u.selling_price * 100), ROUND(p.selling_price * 100) * i.unit_factor)'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM product_units WHERE barcode IS NOT NULL AND barcode NOT REGEXP \'^[0-9]{13}$\''));
        self::assertSame(0, (new StockReservationReconciler($this->pdo))->mismatches());
    }

    public function testOrdersInMixedUnitsReserveIssueAndReceiveInBaseUnits(): void
    {
        $product = $this->productWithKarton('MU-FLOW', 24, 500);
        $catalog = new ProductUnitCatalog($this->products, $this->units);
        $sales = $this->salesService($catalog);
        $order = $sales->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => 2, 'unit_code' => 'karton']]);
        self::assertSame([48, 2, 'karton', 24, 60000.0], [$order->items()[0]->quantity(), $order->items()[0]->unitQuantity(), $order->items()[0]->unitCode(), $order->items()[0]->unitFactor(), $order->items()[0]->sellingPrice()]);
        $sales->submit($this->admin, $order->id());
        $sales->approve($this->admin, $order->id());
        self::assertSame([500, 48], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        $sales->issue($this->admin, $order->id(), str_repeat('c', 32), [$order->items()[0]->id() => 1]);
        $partial = (new MySqlSalesOrderRepository($this->pdo))->findById($order->id()) ?? self::fail('order');
        self::assertSame([SalesOrder::STATUS_PARTIALLY_FULFILLED, 24, 1], [$partial->status(), $partial->items()[0]->issuedQuantity(), $partial->items()[0]->remainingUnitQuantity()]);
        self::assertSame([476, 24], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        self::assertSame([24], array_map(static fn ($entry): int => $entry->quantity(), (new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $order->id())));

        // A mixed order: a piece line next to the karton line; the Rupiah total is unit quantity × price per unit.
        $mixed = $sales->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => 30, 'unit_code' => 'pcs']]);
        self::assertSame([30, 'pcs', 1, 2600.0, 78000.0], [$mixed->items()[0]->quantity(), $mixed->items()[0]->unitCode(), $mixed->items()[0]->unitFactor(), $mixed->items()[0]->sellingPrice(), $mixed->items()[0]->lineTotal()]);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM sales_order_items WHERE sales_order_id = {$mixed->id()} AND unit_quantity = 30 AND quantity = 30"));

        $purchase = new PurchaseOrderService(new MySqlPurchaseOrderRepository($this->pdo), new MySqlDocumentSequenceRepository($this->pdo), $this->products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], $this->stock, null, null, new OrderLinePricing(), $catalog);
        $po = $purchase->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => 3]]); // no unit: the base unit
        self::assertSame(['pcs', 1, 3], [$po->items()[0]->unitCode(), $po->items()[0]->unitFactor(), $po->items()[0]->quantity()]);
        $karton = $purchase->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => 2, 'unit_code' => 'karton']]);
        $purchase->markOrdered($this->admin, $karton->id());
        $purchase->receive($this->admin, $karton->id(), [$karton->items()[0]->id() => 1]);
        self::assertSame(500, $this->balances->quantity($product, 1), '476 + 1 karton of 24');
        self::assertTrue($this->units->isUsed($product, 'karton'));
        self::assertSame(['line_unit_mismatches' => 0, 'whole_unit_mismatches' => 0, 'base_unit_mismatches' => 0], (new MultiUnitChecks($this->pdo))->report());
    }

    public function testDatabaseGuardsUnitArithmeticAndOneBasePerProduct(): void
    {
        $product = $this->productWithKarton('MU-CHECK', 12, 0);
        $order = (int) $this->scalar('SELECT MIN(id) FROM sales_orders');
        foreach ([
            "INSERT INTO sales_order_items (sales_order_id, product_id, quantity, selling_price, list_price, unit_code, unit_factor, unit_quantity) VALUES ({$order}, {$product}, 13, 1, 1, 'karton', 12, 1)",
            "INSERT INTO sales_order_items (sales_order_id, product_id, quantity, selling_price, list_price, unit_code, unit_factor, unit_quantity, issued_quantity) VALUES ({$order}, {$product}, 24, 1, 1, 'karton', 12, 2, 5)",
            "UPDATE purchase_order_items SET received_quantity = 1 WHERE unit_factor > 1 AND received_quantity = 0 LIMIT 1",
            "INSERT INTO product_units (product_id, unit_code, factor_to_base, is_base) VALUES ({$product}, 'lusin', 1, 0)",
        ] as $index => $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('Statement ' . $index . ' must be refused.');
            } catch (PDOException $exception) {
                self::assertContains((int) ($exception->errorInfo[1] ?? 0), [3819, 1062], $exception->getMessage());
            }
        }
        foreach (["INSERT INTO product_units (product_id, unit_code, factor_to_base, is_base) VALUES ({$product}, 'pc', 1, 1)", "INSERT INTO product_units (product_id, unit_code, factor_to_base, is_base, is_sales_default) VALUES ({$product}, 'pak', 6, 0, 1)"] as $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('A second base unit / second sales default must be refused.');
            } catch (PDOException $exception) {
                self::assertSame(1062, (int) ($exception->errorInfo[1] ?? 0));
            }
        }
        // Renaming the product's unit renames its base unit row in the same transaction.
        $this->products->update($product, new ProductInput('MU-CHECK', 'Renamed', 'biji', 100.0, 130.0, 12, 1));
        self::assertSame('biji', (string) $this->scalar("SELECT unit_code FROM product_units WHERE product_id = {$product} AND is_base = 1", true));
    }

    public function testReplenishmentRoundsUpToWholePurchaseUnitsAndApiListsUnits(): void
    {
        // Reorder point 100 pcs, 10 pcs in stock, purchase default karton of 24: 90 pcs needed → 4 karton (96 pcs).
        $product = $this->productWithKarton('MU-REPL', 24, 10, 100);
        $rows = (new MySqlBusinessOperationRepository($this->pdo))->recommendations('MU-REPL', 10, 0, 1);
        self::assertCount(1, $rows);
        self::assertSame([90, 'karton', 24, 'pcs'], [(int) $rows[0]['suggested'], $rows[0]['purchase_unit'], (int) $rows[0]['purchase_factor'], $rows[0]['base_unit']]);
        self::assertSame(4, \App\Support\UnitSet::unitsCovering((int) $rows[0]['suggested'], (int) $rows[0]['purchase_factor']));

        $api = (new MySqlOperationalQueryRepository($this->pdo))->productAvailability('MU-REPL') ?? self::fail('product');
        self::assertSame([10, 'pcs'], [$api['total_quantity'], $api['base_unit']], 'existing fields stay in base units');
        self::assertSame([['unit' => 'karton', 'factor_to_base' => 24], ['unit' => 'pcs', 'factor_to_base' => 1]], array_map(static fn (array $unit): array => ['unit' => $unit['unit'], 'factor_to_base' => $unit['factor_to_base']], $api['units']));
        unset($product);
    }

    public function testMigrationDryRunThenApplyConvertsLegacyQuantitiesAndPreservesValue(): void
    {
        $ids = $this->legacyFixture();
        [$code, $output] = $this->runMigration('--dry-run');
        self::assertSame(0, $code, $output);
        $dry = json_decode($output, true);
        self::assertSame(['dry-run', false, 2, [4 => 1, 48 => 1]], [$dry['mode'], $dry['already_applied'], $dry['products_to_convert'], $dry['products_by_factor']]);
        self::assertContains('KES-9999 (Karton) Asri Balsem', $dry['kept_as_base']);
        self::assertSame(0, array_sum($dry['invariants_before']), $output);
        self::assertSame(1.28, $dry['stock_value']['difference'], '384 pcs × (2010.42 − 96500/48) rounds up by Rp 1,28, within the bound 384 × 0,005');
        self::assertSame('Karton', (string) $this->scalar("SELECT unit FROM products WHERE sku = 'KES-9999'", true), 'a dry run changes nothing');
        self::assertSame('karton', (string) $this->scalar("SELECT unit FROM products WHERE id = {$ids['p1']}", true));

        // A broken invariant stops the data step: nothing is converted and the version is not recorded.
        $this->pdo->exec("UPDATE product_stocks SET quantity = quantity + 1 WHERE product_id = {$ids['p4']} AND warehouse_id = 1");
        [$code, $output] = $this->runMigration();
        self::assertSame(2, $code, $output);
        self::assertSame('conflict', json_decode($output, true)['status']);
        self::assertSame('karton', (string) $this->scalar("SELECT unit FROM products WHERE id = {$ids['p1']}", true));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'multi-unit-1'"));
        $this->pdo->exec("UPDATE product_stocks SET quantity = quantity - 1 WHERE product_id = {$ids['p4']} AND warehouse_id = 1");

        [$code, $output] = $this->runMigration();
        self::assertSame(0, $code, $output);
        $result = json_decode($output, true);
        self::assertSame(['applied', 2, 0, 0, 0, 1.28], [$result['status'], $result['products_converted'], array_sum($result['invariants_after']), $result['line_totals_changed']['purchase_order_items'], $result['line_totals_changed']['sales_order_items'], $result['stock_value']['difference']]);
        self::assertLessThanOrEqual($result['stock_value']['rounding_bound'], abs($result['stock_value']['difference']));
        self::assertSame($dry['line_totals'], $result['line_totals'], 'Σ PO/SO line totals in Rupiah are unchanged');

        // Product 1: karton of 48 → base pcs; the karton unit keeps the old master prices.
        self::assertSame(['pcs', '2010.42', '2500.00', 480], array_values($this->row("SELECT unit, purchase_price, selling_price, reorder_point FROM products WHERE id = {$ids['p1']}")));
        self::assertSame(['karton', 48, '96500.00', '120000.00', 1, 1], array_values($this->row("SELECT unit_code, factor_to_base, purchase_price, selling_price, is_purchase_default, is_sales_default FROM product_units WHERE product_id = {$ids['p1']} AND is_base = 0")));
        self::assertSame([384, 336, 720], array_values($this->row("SELECT quantity, reserved_quantity, reorder_point_override FROM product_stocks WHERE product_id = {$ids['p1']} AND warehouse_id = 1")));
        self::assertSame([960, 576, 'karton', 48, 20], array_values($this->row("SELECT quantity, received_quantity, unit_code, unit_factor, unit_quantity FROM purchase_order_items WHERE product_id = {$ids['p1']}")));
        self::assertSame([240, 96, 'karton', 48, 5], array_values($this->row("SELECT quantity, issued_quantity, unit_code, unit_factor, unit_quantity FROM sales_order_items WHERE sales_order_id = {$ids['so2']} AND product_id = {$ids['p1']}")));
        self::assertSame([96, 96], array_values($this->row("SELECT quantity, dispatched_quantity FROM inventory_operation_items WHERE operation_id = {$ids['t1']}")));
        // Product 3: bal of 4 → base pak; transfer receipt with damage converted line by line.
        self::assertSame([8, 8, 4, 4, 0, 'bal', 2], array_values($this->row("SELECT quantity, dispatched_quantity, received_quantity, damaged_quantity, short_quantity, unit_code, unit_quantity FROM inventory_operation_items WHERE operation_id = {$ids['t2']}")));
        // Unknown factor and single units: base = old unit, factor 1, nothing multiplied.
        self::assertSame(['Karton', 1, 1], array_values($this->row("SELECT unit_code, factor_to_base, is_base FROM product_units WHERE product_id = {$ids['p4']}")));
        self::assertSame([2, 2], array_values($this->row("SELECT quantity, unit_quantity FROM inventory_operation_items WHERE operation_id = {$ids['ad1']}")));
        self::assertSame(1, $this->scalar("SELECT returned_quantity FROM inventory_return_totals WHERE source_ledger_id = {$ids['issue_so4']}"));
        self::assertSame(3, $this->scalar("SELECT closed_units FROM sales_order_closures WHERE sales_order_id = {$ids['so3']}"));
        self::assertSame(['line_unit_mismatches' => 0, 'whole_unit_mismatches' => 0, 'base_unit_mismatches' => 0], (new MultiUnitChecks($this->pdo))->report());
        self::assertSame(['issued_ledger_mismatches' => 0, 'fulfilment_status_mismatches' => 0, 'closure_mismatches' => 0], (new PartialFulfilmentChecks($this->pdo))->report());
        self::assertSame(0, array_sum((new TransferTransitChecks($this->pdo))->report()));
        self::assertSame(0, (new StockReservationReconciler($this->pdo))->mismatches());
        self::assertSame(5, $this->scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_name IN ('chk_purchase_order_items_unit_quantity','chk_sales_order_items_unit_quantity','chk_operation_items_unit','chk_purchase_order_items_received_whole_units','chk_sales_order_items_issued_whole_units')"));

        // Idempotent: a second run converts nothing and re-verifies.
        [$code, $output] = $this->runMigration();
        self::assertSame(0, $code, $output);
        self::assertSame('already-applied', json_decode($output, true)['status']);
        self::assertSame(384, $this->scalar("SELECT quantity FROM product_stocks WHERE product_id = {$ids['p1']} AND warehouse_id = 1"));

        // Migrations run in order: the lot migration (D-17, ADR-023) then turns the converted stock into LEGACY lots.
        $lots = (new \App\Repository\MySql\BatchExpiryMigration($this->pdo))->apply();
        self::assertSame(['ready', 0], [$lots['status'], array_sum($lots['checks'])], json_encode($lots));
        self::assertSame(384, $this->scalar("SELECT quantity FROM stock_lots WHERE product_id = {$ids['p1']} AND warehouse_id = 1 AND lot_code = 'LEGACY'"));

        // The converted order keeps working in its unit: the 3 remaining karton ship as whole karton.
        $sales = $this->salesService(new ProductUnitCatalog($this->products, $this->units));
        $line = (int) $this->scalar("SELECT id FROM sales_order_items WHERE sales_order_id = {$ids['so2']} AND product_id = {$ids['p1']}");
        $sales->issue($this->admin, $ids['so2'], str_repeat('e', 32), [$line => 1]);
        self::assertSame([336, 288], array_values($this->row("SELECT quantity, reserved_quantity FROM product_stocks WHERE product_id = {$ids['p1']} AND warehouse_id = 1")));
    }

    /** Product with base pcs (Rp 2.000 / 2.600) and a karton unit (purchase/sales default, own prices) plus stock at warehouse 1. */
    private function productWithKarton(string $sku, int $factor, int $stock, int $reorder = 1): int
    {
        $id = (new ProductService($this->products, new MasterDataAuthorizationService(), $this->stock))
            ->create($this->admin, new ProductInput($sku, 'Unit ' . $sku, 'pcs', 2000.0, 2600.0, $reorder, 1))->id();
        (new ProductUnitService($this->products, $this->units, new MasterDataAuthorizationService(), new MySqlAuditLogRepository($this->pdo), new MySqlTransactionManager($this->pdo)))
            ->add($this->admin, $id, ['unit_code' => 'karton', 'factor_to_base' => (string) $factor, 'purchase_price' => '45000', 'selling_price' => '60000', 'is_purchase_default' => '1', 'is_sales_default' => '1']);
        if ($stock > 0) {
            $this->stock->receive(1, [new StockMovement($id, $stock)], 1, 'TEST', $id);
        }

        return $id;
    }

    private function salesService(ProductUnitCatalog $catalog): SalesOrderService
    {
        return new SalesOrderService(new MySqlSalesOrderRepository($this->pdo), new MySqlDocumentSequenceRepository($this->pdo), $this->products, [1 => new Customer(1, 'Customer', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], $this->stock, new \App\Service\OperationIdempotency(new \App\Repository\MySql\MySqlOperationRequestRepository($this->pdo)), new OrderLinePricing(), new MySqlOrderExceptionRepository($this->pdo), $catalog);
    }

    /**
     * Pre-D-16 database: the schema without product_units and line unit columns, and a small consistent history in the
     * old unit (karton/bal/sak/Karton) covering partial receipt, reservation, partial fulfilment with closure, transfers
     * in transit and received with damage, a customer return, a count adjustment and a reorder override.
     *
     * @return array<string, int>
     */
    private function legacyFixture(): array
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        // D-17 / ADR-023: a pre-D-16 database also predates lots, so its lot rows, watermark and version go too.
        // D-19 / ADR-025: delivery notes, invoices and quotations reference these rows and cannot exist before D-16 either.
        foreach (['invoice_payments', 'invoice_lines', 'invoices', 'delivery_note_lots', 'delivery_note_lines', 'delivery_notes', 'quotation_items', 'quotations', 'inventory_operation_lots', 'stock_lots', 'stock_lot_watermark', 'product_units', 'inventory_return_totals', 'inventory_operation_items', 'inventory_operations', 'sales_order_closures', 'sales_order_rejections', 'sales_order_items', 'sales_orders', 'purchase_order_closures', 'purchase_order_items', 'purchase_orders', 'stock_ledger', 'product_stocks', 'operation_requests', 'products'] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->exec('DROP TABLE product_units');
        foreach (['purchase_order_items' => ['chk_purchase_order_items_unit_factor', 'chk_purchase_order_items_unit_quantity', 'chk_purchase_order_items_received_whole_units'], 'sales_order_items' => ['chk_sales_order_items_unit_factor', 'chk_sales_order_items_unit_quantity', 'chk_sales_order_items_issued_whole_units'], 'inventory_operation_items' => ['chk_operation_items_unit']] as $table => $checks) {
            foreach ($checks as $check) {
                $this->pdo->exec("ALTER TABLE {$table} DROP CHECK {$check}");
            }
            $this->pdo->exec("ALTER TABLE {$table} DROP COLUMN unit_code, DROP COLUMN unit_factor, DROP COLUMN unit_quantity");
        }
        $this->pdo->exec("DELETE FROM schema_versions WHERE version IN ('multi-unit-1', 'batch-expiry-1')");
        $other = (int) $this->scalar("SELECT MIN(id) FROM users WHERE role = 'Admin' AND id <> 1");
        $customer = (int) $this->scalar('SELECT MIN(id) FROM customers');
        $supplier = (int) $this->scalar('SELECT MIN(id) FROM suppliers');
        $insert = function (string $table, array $row): int {
            $statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')');
            $statement->execute(array_values($row));

            return (int) $this->pdo->lastInsertId();
        };
        $product = static fn (string $sku, string $name, string $unit, string $buy, string $sell, int $reorder): array => ['category_id' => 1, 'sku' => $sku, 'name' => $name, 'unit' => $unit, 'purchase_price' => $buy, 'selling_price' => $sell, 'price' => $sell, 'reorder_point' => $reorder];
        $ids = [
            'p1' => $insert('products', $product('MIE-L1', 'Mi Goreng Legacy 85g (48 pcs)', 'karton', '96500.00', '120000.00', 10)),
            'p2' => $insert('products', $product('SBK-L2', 'Beras Legacy 5kg', 'sak', '66200.00', '72800.00', 5)),
            'p3' => $insert('products', $product('BYI-L3', 'Popok Legacy isi 20 (4 pak)', 'bal', '194000.00', '220000.00', 4)),
            'p4' => $insert('products', $product('KES-9999', 'Asri Balsem', 'Karton', '5000000.00', '600000.00', 2)),
        ];
        $stock = ['p1' => [1 => [8, 7, 15], 2 => [0, 0, null]], 'p2' => [1 => [8, 0, null], 2 => [0, 0, null]], 'p3' => [1 => [3, 1, null], 2 => [1, 0, null]], 'p4' => [1 => [2, 0, null], 2 => [0, 0, null]]];
        foreach ($stock as $key => $warehouses) {
            foreach ([1, 2, 3] as $warehouse) {
                [$quantity, $reserved, $override] = $warehouses[$warehouse] ?? [0, 0, null];
                $insert('product_stocks', ['product_id' => $ids[$key], 'warehouse_id' => $warehouse, 'quantity' => $quantity, 'reserved_quantity' => $reserved, 'reorder_point_override' => $override]);
            }
        }
        $ledger = fn (string $key, int $warehouse, string $type, int $quantity, ?int $delta, string $reference, int $referenceId): int => $insert('stock_ledger', ['product_id' => $ids[$key], 'warehouse_id' => $warehouse, 'movement_type' => $type, 'quantity' => $quantity, 'quantity_delta' => $delta, 'reference_type' => $reference, 'reference_id' => $referenceId, 'performed_by' => 1]);
        $order = fn (string $table, array $row): int => $insert($table, $row + ['order_date' => '2026-10-01', 'created_by' => 1]);
        // PO1 partially received; PO2 ordered with a negotiated price; PO3 received.
        $po1 = $order('purchase_orders', ['order_number' => 'LEG-PO-1', 'supplier_id' => $supplier, 'destination_warehouse_id' => 1, 'status' => 'PartiallyReceived']);
        $insert('purchase_order_items', ['purchase_order_id' => $po1, 'product_id' => $ids['p1'], 'quantity' => 20, 'received_quantity' => 12, 'purchase_price' => '96500.00', 'list_price' => '96500.00']);
        $insert('purchase_order_items', ['purchase_order_id' => $po1, 'product_id' => $ids['p2'], 'quantity' => 10, 'received_quantity' => 10, 'purchase_price' => '66200.00', 'list_price' => '66200.00']);
        $ledger('p1', 1, 'Receipt', 12, null, 'PO', $po1);
        $ledger('p2', 1, 'Receipt', 10, null, 'PO', $po1);
        $po2 = $order('purchase_orders', ['order_number' => 'LEG-PO-2', 'supplier_id' => $supplier, 'destination_warehouse_id' => 1, 'status' => 'Ordered']);
        $insert('purchase_order_items', ['purchase_order_id' => $po2, 'product_id' => $ids['p3'], 'quantity' => 5, 'received_quantity' => 0, 'purchase_price' => '190000.00', 'list_price' => '194000.00', 'price_reason' => 'Diskon principal']);
        $po3 = $order('purchase_orders', ['order_number' => 'LEG-PO-3', 'supplier_id' => $supplier, 'destination_warehouse_id' => 1, 'status' => 'Received']);
        $insert('purchase_order_items', ['purchase_order_id' => $po3, 'product_id' => $ids['p3'], 'quantity' => 6, 'received_quantity' => 6, 'purchase_price' => '194000.00', 'list_price' => '194000.00']);
        $insert('purchase_order_items', ['purchase_order_id' => $po3, 'product_id' => $ids['p4'], 'quantity' => 3, 'received_quantity' => 3, 'purchase_price' => '5000000.00', 'list_price' => '5000000.00']);
        $ledger('p3', 1, 'Receipt', 6, null, 'PO', $po3);
        $ledger('p4', 1, 'Receipt', 3, null, 'PO', $po3);
        // SO1 approved (reserves 4 karton); SO2 partially fulfilled (reserves its remainder); SO3 partial with closed
        // remainder; SO4 fulfilled with a negotiated price.
        $approved = ['customer_id' => $customer, 'source_warehouse_id' => 1, 'approved_by' => 1, 'approved_at' => '2026-10-02 10:00:00'];
        $so1 = $order('sales_orders', ['order_number' => 'LEG-SO-1', 'status' => 'Approved'] + $approved);
        $insert('sales_order_items', ['sales_order_id' => $so1, 'product_id' => $ids['p1'], 'quantity' => 4, 'selling_price' => '120000.00', 'list_price' => '120000.00', 'issued_quantity' => 0]);
        $ids['so2'] = $order('sales_orders', ['order_number' => 'LEG-SO-2', 'status' => 'PartiallyFulfilled'] + $approved);
        $insert('sales_order_items', ['sales_order_id' => $ids['so2'], 'product_id' => $ids['p1'], 'quantity' => 5, 'selling_price' => '120000.00', 'list_price' => '120000.00', 'issued_quantity' => 2]);
        $insert('sales_order_items', ['sales_order_id' => $ids['so2'], 'product_id' => $ids['p3'], 'quantity' => 2, 'selling_price' => '220000.00', 'list_price' => '220000.00', 'issued_quantity' => 1]);
        $ledger('p1', 1, 'Issue', 2, null, 'SO', $ids['so2']);
        $ledger('p3', 1, 'Issue', 1, null, 'SO', $ids['so2']);
        $ids['so3'] = $order('sales_orders', ['order_number' => 'LEG-SO-3', 'status' => 'PartiallyFulfilled'] + $approved);
        $insert('sales_order_items', ['sales_order_id' => $ids['so3'], 'product_id' => $ids['p2'], 'quantity' => 4, 'selling_price' => '72800.00', 'list_price' => '72800.00', 'issued_quantity' => 1]);
        $ledger('p2', 1, 'Issue', 1, null, 'SO', $ids['so3']);
        $insert('sales_order_closures', ['sales_order_id' => $ids['so3'], 'closed_by' => 1, 'reason' => 'Sisa dibatalkan pelanggan', 'closed_units' => 3]);
        $so4 = $order('sales_orders', ['order_number' => 'LEG-SO-4', 'status' => 'Fulfilled'] + $approved);
        $insert('sales_order_items', ['sales_order_id' => $so4, 'product_id' => $ids['p2'], 'quantity' => 2, 'selling_price' => '70000.00', 'list_price' => '72800.00', 'price_reason' => 'Promo akhir bulan', 'issued_quantity' => 2]);
        $ids['issue_so4'] = $ledger('p2', 1, 'Issue', 2, null, 'SO', $so4);
        // T1 in transit (2 karton), T2 received with 1 bal damaged, a customer return of 1 sak and a count adjustment.
        $operation = fn (array $row): int => $insert('inventory_operations', $row + ['warehouse_id' => 1, 'reason' => 'Legacy', 'created_by' => 1, 'approved_by' => $other, 'approved_at' => '2026-10-03 09:00:00']);
        $ids['t1'] = $operation(['kind' => 'Transfer', 'status' => 'Dispatched', 'destination_id' => 2, 'dispatched_by' => 1, 'dispatched_at' => '2026-10-04 09:00:00']);
        $insert('inventory_operation_items', ['operation_id' => $ids['t1'], 'product_id' => $ids['p1'], 'quantity' => 2, 'dispatched_quantity' => 2]);
        $ledger('p1', 1, 'Adjustment', 2, -2, 'TRANSFER', $ids['t1']);
        $ids['t2'] = $operation(['kind' => 'Transfer', 'status' => 'Received', 'destination_id' => 2, 'dispatched_by' => 1, 'dispatched_at' => '2026-10-04 09:00:00', 'received_by' => 1, 'received_at' => '2026-10-05 09:00:00', 'receipt_reason' => 'Kemasan rusak']);
        $insert('inventory_operation_items', ['operation_id' => $ids['t2'], 'product_id' => $ids['p3'], 'quantity' => 2, 'dispatched_quantity' => 2, 'received_quantity' => 1, 'damaged_quantity' => 1, 'short_quantity' => 0]);
        $ledger('p3', 1, 'Adjustment', 2, -2, 'TRANSFER', $ids['t2']);
        $ledger('p3', 2, 'Adjustment', 1, 1, 'TRANSFER', $ids['t2']);
        $return = $operation(['kind' => 'CustomerReturn', 'status' => 'Posted', 'condition_confirmed' => 1, 'posted_by' => 1, 'posted_at' => '2026-10-06 09:00:00']);
        $insert('inventory_operation_items', ['operation_id' => $return, 'product_id' => $ids['p2'], 'quantity' => 1, 'source_ledger_id' => $ids['issue_so4']]);
        $ledger('p2', 1, 'Adjustment', 1, 1, 'CUSTOMER_RETURN', $return);
        $insert('inventory_return_totals', ['source_ledger_id' => $ids['issue_so4'], 'returned_quantity' => 1]);
        $ids['ad1'] = $operation(['kind' => 'Adjustment', 'status' => 'Posted', 'posted_by' => 1, 'posted_at' => '2026-10-06 10:00:00']);
        $insert('inventory_operation_items', ['operation_id' => $ids['ad1'], 'product_id' => $ids['p4'], 'quantity' => 2, 'baseline' => 3]);
        $ledger('p4', 1, 'Adjustment', 1, -1, 'ADJUSTMENT', $ids['ad1']);

        return $ids;
    }

    /** @return array{0: int, 1: string} */
    private function runMigration(string ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/migrate-multi-unit.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $error];
    }

    /** @return array<string, int|string> */
    private function row(string $sql): array
    {
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, $sql);

        return array_map(static fn ($value) => is_string($value) && preg_match('/^-?\d+$/D', $value) === 1 ? (int) $value : $value, $row);
    }

    private function scalar(string $sql, bool $raw = false): mixed
    {
        $value = $this->pdo->query($sql)->fetchColumn();

        return $raw ? $value : (int) $value;
    }
}
