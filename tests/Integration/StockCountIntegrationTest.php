<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Customer;
use App\Entity\StockLot;
use App\Entity\Warehouse;
use App\Exception\ValidationException;
use App\Repository\MySql\BatchLotChecks;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlCategoryRepository;
use App\Repository\MySql\MySqlDocumentSequenceRepository;
use App\Repository\MySql\MySqlLotQueryRepository;
use App\Repository\MySql\MySqlOrderExceptionRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlProductUnitRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockCountRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Repository\MySql\MySqlUserRepository;
use App\Repository\MySql\MySqlWarehouseRepository;
use App\Repository\MySql\MySqlWorkQueueRepository;
use App\Repository\MySql\StockCountChecks;
use App\Repository\MySql\StockReservationReconciler;
use App\Security\AuthContext;
use App\Service\BarcodeService;
use App\Service\LotSplit;
use App\Service\OrderLinePricing;
use App\Service\ProductUnitCatalog;
use App\Service\SalesOrderService;
use App\Service\StockCountService;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\BusinessDate;
use App\Support\ProductInput;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** D-18 / ADR-024 on MySQL: count lifecycle with real ledger movements, guards, found lots, reports and the migration. */
final class StockCountIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MySqlStockRepository $balances;
    private StockService $stock;
    private MySqlProductRepository $products;
    private StockCountService $counts;
    private AuthContext $admin;
    private AuthContext $reviewer;
    private AuthContext $counter;
    private AuthContext $recounter;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->balances = new MySqlStockRepository($this->pdo);
        $this->stock = new StockService($this->balances, new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
        $this->products = new MySqlProductRepository($this->pdo);
        $this->counts = new StockCountService(
            new MySqlStockCountRepository($this->pdo),
            $this->stock,
            $this->balances,
            new MySqlAuditLogRepository($this->pdo),
            $this->products,
            new MySqlUserRepository($this->pdo),
            new MySqlWarehouseRepository($this->pdo),
            new MySqlCategoryRepository($this->pdo),
            new ProductUnitCatalog($this->products, new MySqlProductUnitRepository($this->pdo)),
        );
        // Seed users: 1 Admin Demo, 6 Hendra Wijaya (Admin), 4 and 5 Warehouse Demo One/Two.
        $this->admin = new AuthContext(1, 'admin@example.test', 'Admin');
        $this->reviewer = new AuthContext(6, 'hendra.wijaya@sumberniaga.test', 'Admin');
        $this->counter = new AuthContext(4, 'warehouse1@example.test', 'WarehouseStaff');
        $this->recounter = new AuthContext(5, 'warehouse2@example.test', 'WarehouseStaff');
    }

    public function testFullLifecycleWithRealMovementsBetweenSnapshotCountRecountAndPosting(): void
    {
        $product = $this->product('IC-COUNT-1', true);
        $expiryA = $this->day('+100 day');
        $this->stock->receive(1, [new StockMovement($product, 30, [new LotSplit('IC-A', $expiryA, 20, '1000.00'), new LotSplit('IC-B', $this->day('+200 day'), 10, '1000.00')])], 1, 'TEST', 1);
        [$lotA, $lotB] = $this->lots($product);
        $id = $this->counts->create($this->admin, ['warehouse_id' => '1', 'scope' => 'Products', 'product_ids' => [(string) $product], 'counter_ids' => ['4', '5'], 'reason' => 'Cycle count IC']);
        $this->counts->start($this->admin, $id);
        $snapshot = (int) $this->value('SELECT snapshot_ledger_id FROM stock_counts WHERE id = ?', [$id]);
        self::assertSame((int) $this->value('SELECT MAX(id) FROM stock_ledger'), $snapshot);
        $lines = $this->lineIds($id);
        self::assertSame([$lotA, $lotB], array_keys($lines));

        // Between snapshot and count: a sales order ships 6 (FEFO from lot A) and 5 more arrive into lot A.
        [$sales, $orders] = $this->sales();
        $this->shipOrder($sales, $product, 6);
        $this->stock->receive(1, [new StockMovement($product, 5, [new LotSplit('IC-A', $expiryA, 5, '1000.00')])], 1, 'TEST', 2);
        self::assertSame(19, $this->lotQuantity($lotA));

        $this->counts->record($this->counter, $id, [$lines[$lotA] => ['quantity' => '17', 'unit' => 'pcs'], $lines[$lotB] => ['quantity' => '10', 'unit' => 'pcs']]);
        $lineA = $this->row('SELECT * FROM stock_count_lines WHERE id = ?', [$lines[$lotA]]);
        self::assertSame([20, 19, -2], [(int) $lineA['snapshot_quantity'], (int) $lineA['expected_quantity'], (int) $lineA['variance']], 'expected = snapshot 20 − 6 issued + 5 received');
        self::assertGreaterThan($snapshot, (int) $lineA['count_ledger_id']);
        self::assertSame(19, 20 + (int) $this->value("SELECT SUM(CASE movement_type WHEN 'Receipt' THEN quantity WHEN 'Issue' THEN -quantity ELSE quantity_delta END) FROM stock_ledger WHERE lot_id = ? AND id > ? AND id <= ?", [$lotA, $snapshot, (int) $lineA['count_ledger_id']]));

        // After the count, 3 more ship from lot A; the recount happens after that shipment.
        $this->shipOrder($sales, $product, 3);
        $this->counts->submit($this->counter, $id);
        $queue = (new MySqlWorkQueueRepository($this->pdo))->page('Admin', $this->reviewer->userId(), 'CountReview', '', 50, 0);
        self::assertContains($id, array_map(static fn (array $row): int => (int) $row['id'], $queue), 'the reviewer sees the count to review');
        $this->counts->requestRecount($this->reviewer, $id, [(string) $lines[$lotA]], 'Two missing: recount lot IC-A');
        $todo = (new MySqlWorkQueueRepository($this->pdo))->page('WarehouseStaff', $this->recounter->userId(), 'CountToDo', '', 50, 0);
        self::assertContains($id, array_map(static fn (array $row): int => (int) $row['id'], $todo));
        try {
            $this->counts->record($this->counter, $id, [$lines[$lotA] => ['quantity' => '15', 'unit' => 'pcs']]);
            self::fail('The first counter cannot recount.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('different counter', $exception->getMessage());
        }
        $this->counts->record($this->recounter, $id, [$lines[$lotA] => ['quantity' => '15', 'unit' => 'pcs']]);
        $lineA = $this->row('SELECT * FROM stock_count_lines WHERE id = ?', [$lines[$lotA]]);
        self::assertSame([16, -1], [(int) $lineA['recount_expected_quantity'], (int) $lineA['variance']], 'recount expected = 20 − 6 + 5 − 3');
        $this->counts->submit($this->recounter, $id);
        $this->counts->approve($this->reviewer, $id, 'One unit short after recount');

        // After approval and before posting stock moves again; the delta still applies to the current quantity.
        $this->stock->receive(1, [new StockMovement($product, 4, [new LotSplit('IC-A', $expiryA, 4, '1000.00')])], 1, 'TEST', 3);
        $this->counts->post($this->counter, $id);
        self::assertSame([19, 10], [$this->lotQuantity($lotA), $this->lotQuantity($lotB)], 'current 20 − 1, never "set to counted" 15');
        self::assertSame(29, $this->balances->quantity($product, 1));
        self::assertSame([[$lotA, -1]], array_map(static fn (array $row): array => [(int) $row[0], (int) $row[1]], $this->pdo->query("SELECT lot_id, quantity_delta FROM stock_ledger WHERE reference_type = 'STOCK_COUNT' AND reference_id = $id")->fetchAll(PDO::FETCH_NUM)));
        self::assertSame('Posted', $this->value('SELECT status FROM stock_counts WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'stock-counts.post' AND entity_id = ?", [$id]));
        $this->assertInvariants();

        // Read models: report, CSV, lot trace and the stock movement report show the count adjustment.
        $report = $this->counts->report($this->admin, ['warehouse_id' => '1']);
        $row = array_values(array_filter($report['history'], static fn (array $item): bool => (int) $item['id'] === $id))[0];
        self::assertSame([-1, 1, 1], [(int) $row['net_units'], (int) $row['variance_lines'], (int) $row['recounted_lines']]);
        self::assertSame(-1000.0, (float) $row['net_value']);
        ob_start();
        ($this->counts->reportCsv($this->admin, ['warehouse_id' => '1']))();
        $csv = (string) ob_get_clean();
        self::assertStringContainsString($id . ',', $csv);
        self::assertStringContainsString('IC-COUNT-1', $csv);
        self::assertStringContainsString(',-1,pcs,1000.00,-1000.00', $csv);
        $trace = array_column((new MySqlLotQueryRepository($this->pdo))->lotMovements($lotA), 'reference_type');
        self::assertContains('STOCK_COUNT', $trace);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM stock_ledger sl WHERE sl.reference_type = 'STOCK_COUNT' AND sl.reference_id = ? AND sl.movement_type = 'Adjustment'", [$id]));
        self::assertSame('Fulfilled', $orders->findById((int) $this->value("SELECT MAX(id) FROM sales_orders"))->status());
    }

    public function testPostingBelowTheReservationIsRefusedAndDatabaseGuardsHold(): void
    {
        $product = $this->product('IC-COUNT-2', false);
        $this->stock->receive(1, [new StockMovement($product, 10, [new LotSplit('G-1', null, 10, '1000.00')])], 1, 'TEST', 1);
        $id = $this->counts->create($this->admin, ['warehouse_id' => '1', 'scope' => 'Products', 'product_ids' => [(string) $product], 'counter_ids' => ['4'], 'reason' => 'Guard test']);
        $this->counts->start($this->admin, $id);
        $line = array_values($this->lineIds($id))[0];
        $this->counts->record($this->counter, $id, [$line => ['quantity' => '6', 'unit' => '']]);
        $this->counts->submit($this->counter, $id);
        $this->counts->approve($this->reviewer, $id);
        [$sales] = $this->sales();
        $order = $sales->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => 8]]);
        $sales->submit($this->admin, $order->id());
        $sales->approve($this->admin, $order->id());
        try {
            $this->counts->post($this->counter, $id);
            self::fail('Posting below the reservation must be refused.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('below the 8 unit(s) reserved', $exception->getMessage());
            self::assertStringContainsString('line #' . $line, $exception->getMessage());
        }
        self::assertSame([10, 8, 'Approved'], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1), $this->value('SELECT status FROM stock_counts WHERE id = ?', [$id])]);
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM stock_ledger WHERE reference_type = 'STOCK_COUNT' AND reference_id = ?", [$id]));

        foreach ([
            'recounter = counter' => "UPDATE stock_count_lines SET recount_requested = 1, recounted_quantity = 1, recounted_by = counted_by, recounted_at = NOW(), recount_ledger_id = 1, recount_expected_quantity = 1 WHERE id = $line",
            'posted delta ≠ approved variance' => "UPDATE stock_count_lines SET posted_delta = approved_variance + 1 WHERE id = $line",
            'below reservation' => "UPDATE stock_count_lines SET pair_quantity_after = 1, pair_reserved_at_post = 2 WHERE id = $line",
            'approved variance changed' => "UPDATE stock_count_lines SET approved_variance = 3 WHERE id = $line",
            'posted without stamps' => "UPDATE stock_counts SET status = 'Posted' WHERE id = $id",
            'counting without snapshot' => "INSERT INTO stock_counts (warehouse_id, scope, status, reason, created_by) VALUES (1, 'All', 'Counting', 'x', 1)",
            'category scope without category' => "INSERT INTO stock_counts (warehouse_id, scope, reason, created_by) VALUES (1, 'Category', 'x', 1)",
            'snapshot line without lot' => "INSERT INTO stock_count_lines (count_id, product_id, lot_code, origin) VALUES ($id, $product, 'Z', 'Snapshot')",
        ] as $label => $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('The database accepted: ' . $label);
            } catch (\PDOException $exception) {
                self::assertContains((int) ($exception->errorInfo[1] ?? 0), [3819, 1048], $label);
            }
        }
    }

    public function testFoundLotsAreCreatedAtPostingAndBarcodesResolveFromTheDatabase(): void
    {
        $product = $this->product('IC-COUNT-3', false);
        $this->stock->receive(1, [new StockMovement($product, 5, [new LotSplit('F-1', null, 5, '1000.00')])], 1, 'TEST', 1);
        $id = $this->counts->create($this->admin, ['warehouse_id' => '1', 'scope' => 'Products', 'product_ids' => [(string) $product], 'counter_ids' => ['4'], 'reason' => 'Found lot test']);
        $this->counts->start($this->admin, $id);
        $line = array_values($this->lineIds($id))[0];
        $this->counts->record($this->counter, $id, [$line => ['quantity' => '5', 'unit' => '']]);
        $found = $this->counts->addFoundLot($this->counter, $id, ['product_id' => (string) $product, 'lot_code' => 'SHELF-9', 'quantity' => '3']);
        $this->counts->submit($this->counter, $id);
        $this->counts->approve($this->reviewer, $id);
        $this->counts->post($this->admin, $id);
        $lot = $this->row("SELECT * FROM stock_lots WHERE product_id = ? AND lot_code = 'SHELF-9'", [$product]);
        self::assertSame([3, 'STOCK_COUNT', $id], [(int) $lot['quantity'], $lot['source_type'], (int) $lot['source_id']]);
        self::assertSame((int) $lot['id'], (int) $this->value('SELECT posted_lot_id FROM stock_count_lines WHERE id = ?', [$found]));
        self::assertSame(8, $this->balances->quantity($product, 1));
        $this->assertInvariants();

        $seeded = $this->row('SELECT u.barcode, u.unit_code, u.factor_to_base, p.sku FROM product_units u JOIN products p ON p.id = u.product_id WHERE u.barcode IS NOT NULL AND u.is_base = 0 ORDER BY u.id LIMIT 1', []);
        $lookup = (new BarcodeService(new MySqlProductUnitRepository($this->pdo), $this->products))->lookup($this->counter, $seeded['barcode']);
        self::assertSame([$seeded['sku'], $seeded['unit_code'], (int) $seeded['factor_to_base']], [$lookup['product']['sku'], $lookup['unit']['code'], $lookup['unit']['factor_to_base']]);
    }

    public function testSeedCountsHoldEveryInvariant(): void
    {
        $this->assertInvariants();
        $statistics = (new StockCountChecks($this->pdo))->statistics();
        self::assertGreaterThanOrEqual(3, $statistics['counts_by_status']['Posted'] ?? 0);
        self::assertSame(1, $statistics['counts_by_status']['Counting'] ?? 0);
        self::assertGreaterThan(0, $statistics['recounted_lines']);
        self::assertGreaterThan(0, $statistics['found_lots_created']);
        self::assertGreaterThan(0, $statistics['posted_variance_lines']);
    }

    public function testMigrationAddsTheTablesNonDestructivelyAndIsIdempotent(): void
    {
        $before = $this->checksum();
        // Before the migration no count exists: the seed's count lots and ledger rows are relabelled (disposable database).
        $this->pdo->exec("UPDATE stock_lots SET source_type = 'ADJUSTMENT' WHERE source_type = 'STOCK_COUNT'");
        $this->pdo->exec("UPDATE stock_ledger SET reference_type = 'PRE_COUNT' WHERE reference_type = 'STOCK_COUNT'");
        foreach (['stock_count_lines', 'stock_count_counters', 'stock_count_products', 'stock_counts'] as $table) {
            $this->pdo->exec('DROP TABLE ' . $table);
        }
        $this->pdo->exec("ALTER TABLE stock_lots DROP CHECK chk_stock_lots_source, ADD CONSTRAINT chk_stock_lots_source CHECK (source_type IN ('PO', 'TRANSFER', 'ADJUSTMENT', 'CUSTOMER_RETURN', 'LEGACY', 'RECEIPT'))");
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'stock-count-1'");

        [$code, $output] = $this->runMigration('--dry-run');
        self::assertSame(0, $code, $output);
        $dry = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['stock_counts', 'stock_count_products', 'stock_count_counters', 'stock_count_lines'], $dry['tables_missing']);
        self::assertFalse($dry['lot_source_allows_stock_count']);
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'stock_counts'"), 'the dry run changes nothing');

        [$code, $output] = $this->runMigration();
        self::assertSame(0, $code, $output);
        $applied = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['ready', false, true], [$applied['status'], $applied['already_applied'], $applied['lot_source_widened']]);
        self::assertSame(0, array_sum($applied['count_checks']));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM schema_versions WHERE version = 'stock-count-1'"));
        [$code, $output] = $this->runMigration();
        self::assertSame(0, $code, $output);
        self::assertTrue(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['already_applied']);
        self::assertSame($before, $this->checksum(), 'stock, reservations and ledger are unchanged');

        // The migrated schema works end to end.
        $product = $this->product('IC-COUNT-4', false);
        $this->stock->receive(1, [new StockMovement($product, 2, [new LotSplit('M-1', null, 2, '1000.00')])], 1, 'TEST', 1);
        $id = $this->counts->create($this->admin, ['warehouse_id' => '1', 'scope' => 'Products', 'product_ids' => [(string) $product], 'counter_ids' => ['4'], 'reason' => 'After migration']);
        $this->counts->start($this->admin, $id);
        $this->counts->addFoundLot($this->counter, $id, ['product_id' => (string) $product, 'lot_code' => 'M-2', 'quantity' => '1']);
        $this->counts->record($this->counter, $id, [array_values($this->lineIds($id))[0] => ['quantity' => '2', 'unit' => '']]);
        $this->counts->submit($this->counter, $id);
        $this->counts->approve($this->reviewer, $id);
        $this->counts->post($this->admin, $id);
        self::assertSame(3, $this->balances->quantity($product, 1));
        self::assertSame(array_fill_keys(['count_posted_delta_mismatches', 'count_below_reserved', 'count_approved_by_counter', 'count_expected_mismatches', 'count_open_overlaps'], 0), (new StockCountChecks($this->pdo))->report());
    }

    // ---- Helpers ----------------------------------------------------------------------------------------------------

    private function assertInvariants(): void
    {
        self::assertSame(array_fill_keys(['count_posted_delta_mismatches', 'count_below_reserved', 'count_approved_by_counter', 'count_expected_mismatches', 'count_open_overlaps'], 0), (new StockCountChecks($this->pdo))->report());
        self::assertSame(array_fill_keys(['lot_aggregate_mismatches', 'lot_ledger_mismatches', 'lot_count_mismatches', 'ledger_rows_without_lot', 'issue_from_expired_lot', 'fefo_violations', 'transfer_lot_mismatches'], 0), (new BatchLotChecks($this->pdo))->report());
        self::assertSame(0, (new StockReservationReconciler($this->pdo))->mismatches());
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE movement_type WHEN 'Receipt' THEN quantity WHEN 'Issue' THEN -CAST(quantity AS SIGNED) ELSE quantity_delta END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)"));
    }

    private function product(string $sku, bool $tracked): int
    {
        $product = $this->products->create(new ProductInput($sku, 'Count product ' . $sku, 'pcs', 10.0, 20.0, 1, 1, $tracked), true)->id();
        $this->stock->initializeProduct($product);

        return $product;
    }

    /** @return array{0: SalesOrderService, 1: MySqlSalesOrderRepository} */
    private function sales(): array
    {
        $orders = new MySqlSalesOrderRepository($this->pdo);

        return [new SalesOrderService($orders, new MySqlDocumentSequenceRepository($this->pdo), $this->products, [1 => new Customer(1, 'Customer', '', '', '', true)], [1 => new Warehouse(1, 'Warehouse', '', true)], $this->stock, null, new OrderLinePricing(), new MySqlOrderExceptionRepository($this->pdo)), $orders];
    }

    private function shipOrder(SalesOrderService $sales, int $product, int $quantity): void
    {
        $order = $sales->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => $quantity]]);
        $sales->submit($this->admin, $order->id());
        $sales->approve($this->admin, $order->id());
        $sales->issue($this->counter, $order->id());
    }

    /** @return list<int> lot ids in lot_seq order */
    private function lots(int $product): array
    {
        return array_map(static fn (StockLot $lot): int => $lot->id(), $this->sortedLots($product));
    }

    /** @return list<StockLot> */
    private function sortedLots(int $product): array
    {
        $lots = [];
        foreach ($this->pdo->query("SELECT id FROM stock_lots WHERE product_id = $product AND warehouse_id = 1 ORDER BY lot_seq")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $lots[] = $this->balances->findLot((int) $id) ?? throw new \RuntimeException('Lot missing.');
        }

        return $lots;
    }

    /** @return array<int, int> lot id => line id */
    private function lineIds(int $id): array
    {
        $map = [];
        foreach ($this->pdo->query("SELECT lot_id, id FROM stock_count_lines WHERE count_id = $id AND lot_id IS NOT NULL ORDER BY lot_id")->fetchAll(PDO::FETCH_NUM) as [$lot, $line]) {
            $map[(int) $lot] = (int) $line;
        }

        return $map;
    }

    private function lotQuantity(int $lotId): int
    {
        return (int) $this->value('SELECT quantity FROM stock_lots WHERE id = ?', [$lotId]);
    }

    private function day(string $offset): string
    {
        return (new \DateTimeImmutable(BusinessDate::today()))->modify($offset)->format('Y-m-d');
    }

    /** @param list<int|string> $params */
    private function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /** @param list<int|string> $params @return array<string, mixed> */
    private function row(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function checksum(): string
    {
        return (string) $this->value("SELECT CONCAT((SELECT COALESCE(SUM(quantity), 0) FROM product_stocks), '/', (SELECT COALESCE(SUM(reserved_quantity), 0) FROM product_stocks), '/', (SELECT COUNT(*) FROM stock_ledger), '/', (SELECT COALESCE(SUM(quantity), 0) FROM stock_lots))");
    }

    /** @return array{0: int, 1: string} */
    private function runMigration(string ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/migrate-stock-counts.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $error];
    }
}
