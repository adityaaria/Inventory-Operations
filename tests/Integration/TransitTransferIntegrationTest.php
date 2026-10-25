<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\Contract\OperationalQueryRepositoryInterface;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlBusinessOperationRepository;
use App\Repository\MySql\MySqlDocumentTimelineRepository;
use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Repository\MySql\MySqlUserRepository;
use App\Repository\MySql\MySqlWorkQueueRepository;
use App\Repository\MySql\TransferTransitChecks;
use App\Security\AuthContext;
use App\Service\BusinessOperationService;
use App\Service\DocumentTimelineService;
use App\Service\MasterDataAuthorizationService;
use App\Service\ProductService;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\OutstandingCriteria;
use App\Support\ProductInput;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** D-15 / ADR-021 against real MySQL: persistence, invariants, read models and migration of transfers in transit. */
final class TransitTransferIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MySqlStockRepository $balances;
    private StockService $stock;
    private BusinessOperationService $service;
    private AuthContext $admin;
    private AuthContext $reviewer;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->balances = new MySqlStockRepository($this->pdo);
        $this->stock = new StockService($this->balances, new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
        $this->service = new BusinessOperationService(new MySqlBusinessOperationRepository($this->pdo), $this->stock, new MySqlAuditLogRepository($this->pdo));
        $this->admin = new AuthContext(1, 'admin@example.test', 'Admin');
        $user = (new MySqlUserRepository($this->pdo))->create('Transit reviewer', 'transit-reviewer@example.test', password_hash('password', PASSWORD_DEFAULT), 'Admin', true);
        $this->reviewer = new AuthContext($user->id(), $user->email(), 'Admin');
    }

    public function testDemoSeedHasTransfersInTransitAndWithDiscrepanciesAndAllInvariantsHold(): void
    {
        $this->assertInvariants();
        self::assertGreaterThanOrEqual(2, $this->scalar("SELECT COUNT(*) FROM inventory_operations WHERE kind = 'Transfer' AND status = 'Dispatched'"));
        self::assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM inventory_operation_items WHERE damaged_quantity > 0'));
        self::assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM inventory_operation_items WHERE short_quantity > 0'));
        self::assertGreaterThan(0, (new TransferTransitChecks($this->pdo))->inTransitUnits());
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM inventory_operations WHERE kind = 'Transfer' AND status = 'Posted'"));
    }

    public function testDispatchAndReceivePersistLedgerLinesStatusAndAudit(): void
    {
        [$first, $second] = [$this->product('TR-A', 20), $this->product('TR-B', 8)];
        $id = $this->approved([['product_id' => $first, 'quantity' => 10], ['product_id' => $second, 'quantity' => 8]]);
        $this->service->dispatch($this->admin, $id);

        self::assertSame([10, 0, 0, 0], [$this->balances->quantity($first, 1), $this->balances->quantity($first, 2), $this->balances->quantity($second, 1), $this->balances->quantity($second, 2)]);
        self::assertSame(['Dispatched', 1], [(string) $this->value('SELECT status FROM inventory_operations WHERE id = ' . $id), $this->scalar('SELECT dispatched_by FROM inventory_operations WHERE id = ' . $id)]);
        self::assertSame(18, $this->scalar('SELECT SUM(dispatched_quantity) FROM inventory_operation_items WHERE operation_id = ' . $id));
        self::assertSame(18, (new TransferTransitChecks($this->pdo))->inTransitUnits() - $this->seedInTransit());
        $this->assertInvariants();

        $this->service->receive($this->admin, $id, [$first => ['good' => 8, 'damaged' => 1], $second => ['good' => 8, 'damaged' => 0]], 'One carton crushed, one missing');
        self::assertSame([8, 8], [$this->balances->quantity($first, 2), $this->balances->quantity($second, 2)]);
        $row = $this->pdo->query('SELECT status, received_by, receipt_reason FROM inventory_operations WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['Received', 1, 'One carton crushed, one missing'], [$row['status'], (int) $row['received_by'], $row['receipt_reason']]);
        self::assertSame([8, 1, 1], array_map('intval', array_values($this->pdo->query("SELECT received_quantity, damaged_quantity, short_quantity FROM inventory_operation_items WHERE operation_id = $id AND product_id = $first")->fetch(PDO::FETCH_ASSOC))));
        self::assertSame([-10, -8, 8, 8], array_map('intval', $this->pdo->query("SELECT quantity_delta FROM stock_ledger WHERE reference_type = 'TRANSFER' AND reference_id = $id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $audit = json_decode((string) $this->value("SELECT metadata_json FROM audit_logs WHERE action = 'inventory-operations.receive' AND entity_type = 'TRANSFER' AND entity_id = $id"), true);
        self::assertSame([16, 1, 1, 2], [$audit['received_units'], $audit['damaged_units'], $audit['short_units'], $audit['written_off_units']]);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'inventory-operations.dispatch' AND entity_id = $id AND status = 'success'"));
        $this->assertInvariants();
    }

    public function testDispatchRespectsReservationsAfterTheLock(): void
    {
        $product = $this->product('TR-RES', 10);
        $this->pdo->exec("UPDATE product_stocks SET reserved_quantity = 6 WHERE product_id = $product AND warehouse_id = 1");
        $id = $this->approved([['product_id' => $product, 'quantity' => 5]]);
        try {
            $this->service->dispatch($this->admin, $id);
            self::fail('Dispatch consumed reserved stock.');
        } catch (\App\Exception\ValidationException $exception) {
            self::assertStringContainsString('available 4', $exception->getMessage());
        }
        self::assertSame(['Approved', 10, 0], [(string) $this->value('SELECT status FROM inventory_operations WHERE id = ' . $id), $this->balances->quantity($product, 1), $this->scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type = 'TRANSFER' AND reference_id = $id")]);
        $this->pdo->exec("UPDATE product_stocks SET reserved_quantity = 0 WHERE product_id = $product AND warehouse_id = 1");
    }

    public function testDatabaseGuardsTransitColumns(): void
    {
        $product = $this->product('TR-DB', 10);
        $id = $this->approved([['product_id' => $product, 'quantity' => 4]]);
        $this->service->dispatch($this->admin, $id);
        foreach ([
            "UPDATE inventory_operation_items SET received_quantity = 4, damaged_quantity = 1, short_quantity = 0 WHERE operation_id = $id",
            "UPDATE inventory_operation_items SET dispatched_quantity = 3 WHERE operation_id = $id",
            "UPDATE inventory_operations SET status = 'Posted' WHERE id = $id",
            "UPDATE inventory_operations SET status = 'Received' WHERE id = $id",
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('Database accepted: ' . $sql);
            } catch (PDOException $exception) {
                self::assertStringContainsString('3819', (string) $exception->getMessage(), $sql);
            }
        }
    }

    public function testReadModelsShowGoodsInTransitWithoutCountingThemAsStock(): void
    {
        $product = $this->product('TR-READ', 30, 15);
        $id = $this->approved([['product_id' => $product, 'quantity' => 12]]);
        $this->service->dispatch($this->admin, $id);

        $stocks = [];
        foreach ((new MySqlProductRepository($this->pdo))->stocksForProduct($product) as $stock) { $stocks[$stock->warehouseId()] = $stock; }
        self::assertSame([12, 0, 0], [$stocks[2]->inTransitInbound(), $stocks[2]->quantity(), $stocks[2]->availableQuantity()]);
        self::assertSame(0, $stocks[1]->inTransitInbound());
        self::assertTrue($stocks[2]->isLowStock(), 'Low stock stays on available.');

        $recommendation = array_values(array_filter((new MySqlBusinessOperationRepository($this->pdo))->recommendations('TR-READ', 10, 0, 2), static fn (array $row): bool => (int) $row['warehouse_id'] === 2));
        self::assertSame([12, 3], [(int) $recommendation[0]['in_transit'], (int) $recommendation[0]['suggested']], 'Suggested = 15 − 0 available − 0 PO − 12 in transit.');

        $queue = new MySqlWorkQueueRepository($this->pdo);
        self::assertSame(['TransferReceive' => 1], $queue->counts('WarehouseStaff', 5, 'TransferReceive', 'Transfer #' . $id . ' '));
        self::assertSame('Receive transfer', $queue->page('WarehouseStaff', 5, 'TransferReceive', 'Transfer #' . $id . ' ', 10, 0)[0]['action']);
        $pending = $this->approved([['product_id' => $product, 'quantity' => 1]]);
        self::assertSame(['TransferDispatch' => 1], $queue->counts('Admin', 1, 'TransferDispatch', 'Transfer #' . $pending . ' '));
        self::assertSame([], $queue->counts('Admin', 1, 'Posting', 'Transfer #' . $pending));

        $outstanding = [];
        foreach ((new MySqlOperationalQueryRepository($this->pdo))->iterateOutstandingRows(new OutstandingCriteria(OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_FULFILMENT, null, null, null, 'OP')) as $row) { $outstanding[(string) $row['OrderNumber']] = $row; }
        self::assertSame(['Dispatched', 12], [$outstanding['Transfer #' . $id]['Status'], (int) $outstanding['Transfer #' . $id]['OutstandingQty']]);

        $this->service->receive($this->admin, $id, [$product => ['good' => 11, 'damaged' => 1]], 'Pallet dropped');
        $timeline = (new DocumentTimelineService(new MySqlDocumentTimelineRepository($this->pdo)))->forDocument($this->admin, 'Operation', $id)['events'];
        $titles = array_column($timeline, 'title');
        self::assertContains('Transfer dispatched (in transit)', $titles);
        self::assertContains('Transfer received', $titles);
        self::assertNotEmpty(array_filter($titles, static fn (string $title): bool => str_starts_with($title, 'Adjustment (transfer out) ledger #')));
        self::assertNotEmpty(array_filter($titles, static fn (string $title): bool => str_starts_with($title, 'Adjustment (transfer in) ledger #')));
        $received = array_values(array_filter($timeline, static fn (array $event): bool => $event['title'] === 'Transfer received'))[0];
        self::assertSame(['Received 11 good units; written off 1 damaged and 0 short', 'Pallet dropped'], [$received['detail'], $received['reason']]);

        $rows = array_values(array_filter((new MySqlOperationalQueryRepository($this->pdo))->stockLedgerRows(null, null), static fn (array $row): bool => $row['ReferenceType'] === 'TRANSFER' && (int) $row['ReferenceId'] === $id));
        self::assertEqualsCanonicalizing([-12, 11], array_map(static fn (array $row): int => (int) $row['Quantity'], $rows), 'Report rows carry signed transfer movements; the write-off is not stock.');
        self::assertSame(['Adjustment', 'Adjustment'], array_column($rows, 'Movement'));
        $this->assertInvariants();
    }

    public function testMigrationMapsPostedTransfersToReceivedReportsConflictsAndIsRepeatable(): void
    {
        $product = $this->product('TR-MIG', 20);
        $posted = $this->approved([['product_id' => $product, 'quantity' => 5]]);
        $this->service->dispatch($this->admin, $posted);
        $this->service->receive($this->admin, $posted);
        $approved = $this->approved([['product_id' => $product, 'quantity' => 2]]);
        $before = $this->stockChecksum();
        $this->downgradeToPreTransitSchema();
        self::assertSame('Posted', (string) $this->value('SELECT status FROM inventory_operations WHERE id = ' . $posted));
        // A corrupt legacy transfer: Posted, but no TRANSFER ledger rows were ever written.
        $this->pdo->exec("INSERT INTO inventory_operations (kind, status, warehouse_id, destination_id, reason, created_by, approved_by, posted_by, approved_at, posted_at) VALUES ('Transfer', 'Posted', 1, 2, 'Broken legacy transfer', 1, {$this->reviewer->userId()}, 1, NOW(), NOW())");
        $broken = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO inventory_operation_items (operation_id, product_id, quantity, unit_code, unit_factor, unit_quantity) VALUES ($broken, $product, 3, 'pcs', 1, 3)");

        [$code, $output] = $this->runMigration();
        self::assertSame(2, $code, $output);
        $result = json_decode($output, true);
        self::assertSame('conflict', $result['status']);
        self::assertSame(1, $result['checks']['transfer_dispatch_ledger_mismatches']);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'transit-transfer-1'"));

        // Owner removes the broken document (it never moved stock); the migration completes and is repeatable.
        $this->pdo->exec("DELETE FROM inventory_operation_items WHERE operation_id = $broken");
        $this->pdo->exec("DELETE FROM inventory_operations WHERE id = $broken");
        foreach ([1, 2] as $run) {
            [$code, $output] = $this->runMigration();
            self::assertSame(0, $code, 'Run ' . $run . ': ' . $output);
            $result = json_decode($output, true);
            self::assertSame(['ready', 0, 0, 0, 0, 0, 0], [$result['status'], $result['transfer_dispatch_ledger_mismatches'], $result['transfer_receipt_ledger_mismatches'], $result['transfer_status_mismatches'], $result['in_transit_equation_mismatch'], $result['transfer_discrepancy_without_reason'], $result['ledger_mismatches']]);
        }
        $row = $this->pdo->query("SELECT o.status, i.dispatched_quantity, i.received_quantity, i.damaged_quantity, i.short_quantity, o.dispatched_at = o.posted_at AS same_time FROM inventory_operations o JOIN inventory_operation_items i ON i.operation_id = o.id WHERE o.id = $posted")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['Received', 5, 5, 0, 0, 1], [$row['status'], (int) $row['dispatched_quantity'], (int) $row['received_quantity'], (int) $row['damaged_quantity'], (int) $row['short_quantity'], (int) $row['same_time']]);
        self::assertSame('Approved', (string) $this->value('SELECT status FROM inventory_operations WHERE id = ' . $approved));
        self::assertSame($before, $this->stockChecksum(), 'Stock and ledger are never re-applied.');
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'transit-transfer-1'"));
        self::assertSame(4, $this->scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_name IN ('chk_operation_transit_status','chk_operation_transit_stamps','chk_operation_items_dispatched','chk_operation_items_receipt')"));
        // The migrated Approved transfer follows the new flow.
        $this->service->dispatch($this->admin, $approved);
        $this->service->receive($this->admin, $approved);
        self::assertSame(7, $this->balances->quantity($product, 2));
        $this->assertInvariants();
    }

    /** Pre-D-15 schema: no transit columns, no Dispatched/Received; seed transfers in transit cannot exist there. */
    private function downgradeToPreTransitSchema(): void
    {
        // Transfers in transit or received with a write-off cannot exist before D-15: drop those seed documents (their
        // ledger rows stay, so stock = ledger still holds; the checks start from document lines).
        $this->pdo->exec("CREATE TEMPORARY TABLE transit_only SELECT DISTINCT o.id FROM inventory_operations o LEFT JOIN inventory_operation_items i ON i.operation_id = o.id WHERE o.status = 'Dispatched' OR i.damaged_quantity + i.short_quantity > 0");
        $this->pdo->exec('DELETE l FROM inventory_operation_lots l JOIN transit_only t ON t.id = l.operation_id'); // D-17 lot rows of those transfers
        $this->pdo->exec('DELETE i FROM inventory_operation_items i JOIN transit_only t ON t.id = i.operation_id');
        $this->pdo->exec('DELETE o FROM inventory_operations o JOIN transit_only t ON t.id = o.id');
        $this->pdo->exec('ALTER TABLE inventory_operations DROP CHECK chk_operation_transit_status, DROP CHECK chk_operation_transit_stamps');
        $this->pdo->exec("UPDATE inventory_operations SET status = 'Posted', posted_by = received_by, posted_at = received_at WHERE status = 'Received'");
        $this->pdo->exec('ALTER TABLE inventory_operations DROP FOREIGN KEY fk_inventory_operations_dispatched_by, DROP FOREIGN KEY fk_inventory_operations_received_by');
        $this->pdo->exec('ALTER TABLE inventory_operations DROP COLUMN dispatched_by, DROP COLUMN dispatched_at, DROP COLUMN received_by, DROP COLUMN received_at, DROP COLUMN receipt_reason');
        $this->pdo->exec("ALTER TABLE inventory_operations MODIFY COLUMN status ENUM('PendingApproval','Approved','Rejected','Posted','Cancelled') NOT NULL DEFAULT 'PendingApproval'");
        $this->pdo->exec('ALTER TABLE inventory_operation_items DROP CHECK chk_operation_items_dispatched, DROP CHECK chk_operation_items_receipt');
        $this->pdo->exec('ALTER TABLE inventory_operation_items DROP COLUMN dispatched_quantity, DROP COLUMN received_quantity, DROP COLUMN damaged_quantity, DROP COLUMN short_quantity');
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'transit-transfer-1'");
    }

    private function assertInvariants(): void
    {
        self::assertSame(['transfer_dispatch_ledger_mismatches' => 0, 'transfer_receipt_ledger_mismatches' => 0, 'transfer_status_mismatches' => 0, 'in_transit_equation_mismatch' => 0, 'transfer_discrepancy_without_reason' => 0], (new TransferTransitChecks($this->pdo))->report());
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type = 'Receipt' THEN quantity WHEN movement_type = 'Issue' THEN -quantity ELSE COALESCE(quantity_delta, 0) END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)"));
    }

    private function seedInTransit(): int
    {
        return $this->scalar("SELECT COALESCE(SUM(i.dispatched_quantity), 0) FROM inventory_operation_items i JOIN inventory_operations o ON o.id = i.operation_id JOIN products p ON p.id = i.product_id WHERE o.status = 'Dispatched' AND p.sku NOT LIKE 'TR-%'");
    }

    private function stockChecksum(): string
    {
        return (string) $this->value("SELECT CONCAT((SELECT COALESCE(SUM(quantity), 0) FROM product_stocks), ':', (SELECT COUNT(*) FROM stock_ledger), ':', (SELECT COALESCE(SUM(quantity_delta), 0) FROM stock_ledger))");
    }

    /** @param list<array{product_id:int,quantity:int}> $items */
    private function approved(array $items): int
    {
        $id = $this->service->propose($this->admin, 'Transfer', 1, 2, 'Transit test', $items);
        $this->service->decide($this->reviewer, $id, 'Approved', 'Checked');

        return $id;
    }

    private function product(string $sku, int $atSource, int $reorder = 1): int
    {
        $id = (new ProductService(new MySqlProductRepository($this->pdo), new MasterDataAuthorizationService(), $this->stock))
            ->create($this->admin, new ProductInput($sku, 'Transit ' . $sku, 'pcs', 10.0, 20.0, $reorder, 1))->id();
        $this->stock->receive(1, [new StockMovement($id, $atSource)], 1, 'TEST', $id);

        return $id;
    }

    /** @return array{0: int, 1: string} */
    private function runMigration(): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/migrate-transit-transfer.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $error];
    }

    private function value(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    private function scalar(string $sql): int
    {
        return (int) $this->value($sql);
    }
}
