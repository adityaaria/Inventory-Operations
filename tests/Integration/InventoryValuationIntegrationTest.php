<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\User;
use App\Repository\MySql\BatchLotChecks;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlInventoryValuationRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Repository\MySql\SalesDocumentChecks;
use App\Repository\MySql\ValuationChecks;
use App\Security\AuthContext;
use App\Service\InventoryValuationService;
use App\Service\LotSplit;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\ProductInput;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** D-20 / ADR-026 against real MySQL: seed invariants, reports vs direct SQL, as-of and period reconciliation, DB guards, migration. */
final class InventoryValuationIntegrationTest extends TestCase
{
    private const MONTHS = ['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'];

    private PDO $pdo;
    private InventoryValuationService $valuation;
    private AuthContext $admin;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->valuation = new InventoryValuationService(new MySqlInventoryValuationRepository($this->pdo));
        $this->admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
    }

    public function testSeedHoldsEveryValuationInvariantHasCostLayersAndReconcilesEveryMonthToTheCent(): void
    {
        $this->assertInvariants();
        $statistics = (new ValuationChecks($this->pdo))->statistics();
        self::assertSame(0, $statistics['ledger_rows_before_valuation_start'], 'The seed is generated with lots from the start: every row is valued.');
        self::assertGreaterThan(50, $statistics['products_with_several_costs'], 'Supplier price letters create several cost layers per product.');
        self::assertSame($statistics['on_hand_value_cents'], $statistics['opening_value_cents'] + $statistics['ledger_cost_cents'], 'Σ lot values = Σ opening values + Σ ledger cost amounts.');

        $previous = null;
        foreach (self::MONTHS as $month) {
            $from = $month . '-01';
            $to = (new \DateTimeImmutable($from))->format('Y-m-t');
            $report = $this->valuation->reconciliation($this->admin, ['from' => $from, 'to' => $to]);
            self::assertTrue($report['reconciled'], 'Month ' . $month . ' reconciles to the cent.');
            $lines = array_column($report['rows'], null, 'label');
            if ($previous !== null) {
                foreach ($previous as $label => $row) {
                    self::assertSame($row['closing'], $lines[$label]['opening'] ?? 0, $label . ': closing of the previous month = opening of ' . $month);
                }
            }
            // The as-of valuation at the month end (lot values rolled back) equals the reconciliation closing.
            if ($to <= '2026-10-12') {
                $asOf = $this->valuation->valuation($this->admin, ['as_of' => $to], false);
                self::assertSame($lines['Total']['closing'], $asOf['total_cents'], 'As-of ' . $to . ' = closing of ' . $month);
                self::assertSame($lines['In transit']['closing'], $asOf['transit_cents']);
            }
            $previous = $lines;
        }
        self::assertSame($previous['Total']['closing'], $this->valuation->summary($this->admin)['total_cents'], 'The last closing is the value now.');
    }

    public function testReportsAgreeWithDirectSql(): void
    {
        $current = $this->valuation->valuation($this->admin, ['by' => 'warehouse'], false);
        $byWarehouse = $this->pdo->query('SELECT w.name, SUM(CAST(ROUND(l.quantity * l.unit_cost * 100) AS SIGNED)) FROM stock_lots l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.quantity > 0 GROUP BY w.name')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertEquals(array_map('intval', $byWarehouse), array_column($current['rows'], 'value_cents', 'warehouse_name'));
        self::assertSame($this->scalar("SELECT COALESCE(SUM(CAST(ROUND(t.dispatched_quantity * t.unit_cost * 100) AS SIGNED)), 0) FROM inventory_operation_lots t JOIN inventory_operations o ON o.id = t.operation_id WHERE o.status = 'Dispatched'"), $current['transit_cents']);
        self::assertGreaterThan(0, $current['transit_cents'], 'The seed has transfers in transit.');
        $byLot = $this->valuation->valuation($this->admin, ['by' => 'lot', 'warehouse_id' => '1'], false);
        self::assertSame($this->scalar('SELECT COUNT(*) FROM stock_lots WHERE quantity > 0 AND warehouse_id = 1'), count($byLot['rows']));

        // As of a past date: the roll-back from today equals an independent roll-forward of every cost amount up to then.
        $end = InventoryValuationService::endUtc('2026-08-31');
        $statement = $this->pdo->prepare('SELECT COALESCE(SUM(CAST(ROUND(cost_amount * 100) AS SIGNED)), 0) FROM stock_ledger WHERE cost_amount IS NOT NULL AND created_at < ?');
        $statement->execute([$end]);
        $asOf = $this->valuation->valuation($this->admin, ['as_of' => '2026-08-31'], false);
        self::assertSame((int) $statement->fetchColumn(), $asOf['totals']['value_cents']);

        // Gross margin: revenue = non-void invoice lines in the range, COGS = the invoiced lines' lot costs.
        $margin = $this->valuation->margin($this->admin, ['from' => '2026-07-01', 'to' => '2026-09-30', 'group' => 'product']);
        self::assertSame($this->scalar("SELECT COALESCE(SUM(CAST(ROUND(il.amount * 100) AS SIGNED)), 0) FROM invoices i JOIN invoice_lines il ON il.invoice_id = i.id WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN '2026-07-01' AND '2026-09-30'"),
            $margin['totals']['revenue_cents'] + $margin['totals']['unvalued_revenue_cents']);
        self::assertSame($this->scalar("SELECT COALESCE(SUM(-CAST(ROUND(s.cost_amount * 100) AS SIGNED)), 0) FROM invoices i JOIN invoice_lines il ON il.invoice_id = i.id JOIN delivery_note_lots x ON x.delivery_note_line_id = il.delivery_note_line_id
            JOIN stock_ledger s ON s.id = x.ledger_id WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN '2026-07-01' AND '2026-09-30'"), $margin['totals']['cogs_cents']);
        self::assertSame($margin['totals']['revenue_cents'] - $margin['totals']['cogs_cents'], $margin['totals']['margin_cents']);
        self::assertSame($margin['totals']['revenue_cents'], array_sum(array_column($margin['groups'], 'revenue_cents')));
        self::assertGreaterThan(0, $margin['totals']['margin_cents']);
        $all = $this->valuation->margin($this->admin, ['from' => '2026-01-01', 'to' => '2026-12-31', 'group' => 'line']);
        self::assertSame($this->scalar('SELECT COUNT(*) FROM delivery_note_lines l JOIN delivery_notes dn ON dn.id = l.delivery_note_id LEFT JOIN invoices i ON i.active_delivery_note_id = dn.id WHERE i.id IS NULL'), count($all['not_invoiced']));

        // Losses at cost.
        $losses = $this->valuation->losses($this->admin, ['from' => '2026-01-01', 'to' => '2026-12-31']);
        self::assertSame($this->scalar("SELECT COALESCE(SUM(-CAST(ROUND(s.cost_amount * 100) AS SIGNED)), 0) FROM stock_ledger s JOIN inventory_operations o ON o.id = s.reference_id AND o.purpose = 'ExpiredWriteOff' WHERE s.reference_type = 'ADJUSTMENT'"), $losses['totals']['ExpiredWriteOff']['value_cents']);
        self::assertSame($this->scalar("SELECT COALESCE(SUM(CAST(ROUND(t.damaged_quantity * t.unit_cost * 100) AS SIGNED)), 0) FROM inventory_operation_lots t JOIN inventory_operations o ON o.id = t.operation_id WHERE o.status = 'Received'"), $losses['totals']['TransitDamage']['value_cents']);
        self::assertSame($this->scalar("SELECT COALESCE(SUM(CAST(ROUND(t.short_quantity * t.unit_cost * 100) AS SIGNED)), 0) FROM inventory_operation_lots t JOIN inventory_operations o ON o.id = t.operation_id WHERE o.status = 'Received'"), $losses['totals']['TransitShortage']['value_cents']);
        self::assertSame($this->scalar("SELECT COALESCE(SUM(-CAST(ROUND(cost_amount * 100) AS SIGNED)), 0) FROM stock_ledger WHERE reference_type = 'STOCK_COUNT' AND quantity_delta < 0"), $losses['totals']['CountShortfall']['value_cents']);
        self::assertGreaterThan(0, $losses['totals']['ExpiredWriteOff']['value_cents'] + $losses['totals']['TransitDamage']['value_cents']);

        // SO costing = −Σ Issue cost of the order = Σ its delivery notes' lot costs.
        $order = $this->scalar("SELECT reference_id FROM stock_ledger WHERE movement_type = 'Issue' GROUP BY reference_id HAVING COUNT(*) > 1 ORDER BY reference_id LIMIT 1");
        self::assertSame($this->scalar("SELECT SUM(-CAST(ROUND(cost_amount * 100) AS SIGNED)) FROM stock_ledger WHERE movement_type = 'Issue' AND reference_type = 'SO' AND reference_id = {$order}"), $this->valuation->orderCosting($this->admin, $order)['summary']['cogs_cents']);
    }

    public function testNewMovementsCarryTheirLotCostAndTheDatabaseRefusesAnInconsistentCost(): void
    {
        $products = new MySqlProductRepository($this->pdo);
        $stock = new StockService(new MySqlStockRepository($this->pdo), new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
        $product = $products->create(new ProductInput('VAL-INT', 'Valuation integration', 'pcs', 10.0, 20.0, 1, 1), true)->id();
        $stock->initializeProduct($product);
        $stock->receive(1, [new StockMovement($product, 4, [new LotSplit('VAL-1', null, 4, '10.00')])], 1, 'TEST', 1);
        $stock->receive(1, [new StockMovement($product, 6, [new LotSplit('VAL-1', null, 6, '10.35')])], 1, 'TEST', 2);
        $stock->issue(1, [new StockMovement($product, 5)], 1, 'TEST', 3);

        $rows = $this->pdo->query("SELECT movement_type, quantity, unit_cost, cost_amount FROM stock_ledger WHERE product_id = {$product} ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        self::assertSame([['Receipt', 4, '10.00', '40.00'], ['Receipt', 6, '10.35', '62.10'], ['Issue', 4, '10.00', '-40.00'], ['Issue', 1, '10.35', '-10.35']],
            array_map(static fn (array $row): array => [(string) $row[0], (int) $row[1], (string) $row[2], (string) $row[3]], $rows));
        $this->assertInvariants();

        $lot = $this->scalar("SELECT id FROM stock_lots WHERE product_id = {$product} AND unit_cost = 10.35");
        foreach ([
            "INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, lot_id) VALUES ({$product}, 1, 'Issue', 1, 'TEST', 4, 1, {$lot})",
            "INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, lot_id, unit_cost, cost_amount) VALUES ({$product}, 1, 'Issue', 1, 'TEST', 4, 1, {$lot}, 10.35, 10.35)",
            "INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, unit_cost, cost_amount) VALUES ({$product}, 1, 'Receipt', 1, 'TEST', 4, 1, 10.00, 10.00)",
            "UPDATE stock_ledger SET cost_amount = cost_amount + 0.01 WHERE product_id = {$product} AND movement_type = 'Receipt'",
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('The CHECK must refuse: ' . $sql);
            } catch (PDOException $exception) {
                self::assertStringContainsString('chk_stock_ledger_cost', $exception->getMessage());
            }
        }
    }

    public function testMigrationDryRunBackfillsInOneTransactionRefusesABrokenLotAndIsIdempotent(): void
    {
        $before = $this->scalar('SELECT SUM(CAST(ROUND(cost_amount * 100) AS SIGNED)) FROM stock_ledger');
        $valued = $this->scalar('SELECT COUNT(*) FROM stock_ledger WHERE lot_id IS NOT NULL');
        $this->pdo->exec('ALTER TABLE stock_ledger DROP CHECK chk_stock_ledger_cost');
        $this->pdo->exec('ALTER TABLE stock_ledger DROP INDEX idx_stock_ledger_created, DROP COLUMN cost_amount, DROP COLUMN unit_cost');
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'inventory-valuation-1'");

        [$code, $output] = $this->runMigration('--dry-run');
        self::assertSame(0, $code, $output);
        $dry = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($dry['columns_present']);
        self::assertSame($valued, $dry['rows_to_backfill']);
        self::assertSame(0, $dry['projected_rollforward_difference_cents']);
        self::assertSame($before, $dry['ledger_cost_after_backfill_cents']);

        // A lot that no longer matches its ledger: the backfill is rolled back and the version is not recorded.
        $broken = $this->scalar('SELECT id FROM stock_lots WHERE quantity > 0 ORDER BY id LIMIT 1');
        $this->pdo->exec("UPDATE stock_lots SET quantity = quantity + 1 WHERE id = {$broken}");
        [$code, $output] = $this->runMigration();
        self::assertSame(2, $code, $output);
        self::assertStringContainsString('lot_value_rollforward_mismatches', $output);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM stock_ledger WHERE unit_cost IS NOT NULL'), 'Nothing was backfilled.');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'inventory-valuation-1'"));
        $this->pdo->exec("UPDATE stock_lots SET quantity = quantity - 1 WHERE id = {$broken}");

        [$code, $output] = $this->runMigration();
        self::assertSame(0, $code, $output);
        $applied = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['ready', $valued], [$applied['status'], $applied['rows_backfilled']]);
        self::assertSame($before, $this->scalar('SELECT SUM(CAST(ROUND(cost_amount * 100) AS SIGNED)) FROM stock_ledger'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'chk_stock_ledger_cost'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'inventory-valuation-1'"));
        $this->assertInvariants();

        [$code, $output] = $this->runMigration();
        self::assertSame(0, $code, $output);
        $again = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([true, 0], [$again['already_applied'], $again['rows_backfilled']]);
    }

    private function assertInvariants(): void
    {
        $valuation = (new ValuationChecks($this->pdo))->report();
        self::assertSame(array_fill_keys(array_keys($valuation), 0), $valuation);
        self::assertSame(0, array_sum((new BatchLotChecks($this->pdo))->report()));
        self::assertSame(0, array_sum((new SalesDocumentChecks($this->pdo))->report()));
    }

    /** @return array{0: int, 1: string} */
    private function runMigration(string ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/migrate-inventory-valuation.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $error];
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
