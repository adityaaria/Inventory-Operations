<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Customer;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\ValidationException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\MySql\LowStockSql;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlBusinessOperationRepository;
use App\Repository\MySql\MySqlDocumentSequenceRepository;
use App\Repository\MySql\MySqlDocumentTimelineRepository;
use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Repository\MySql\MySqlOperationRequestRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Repository\MySql\MySqlUserRepository;
use App\Repository\MySql\StockReservationReconciler;
use App\Security\AuthContext;
use App\Service\BusinessOperationService;
use App\Service\DocumentTimelineService;
use App\Service\MasterDataAuthorizationService;
use App\Service\OperationIdempotency;
use App\Service\ProductService;
use App\Service\SalesOrderService;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;
use App\Support\RequestOrigin;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabase;

/** D-12 / ADR-018 against real MySQL: reservation lifecycle, decrease guards, read models, invariant and migration. */
final class StockReservationIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MySqlStockRepository $balances;
    private StockService $stock;
    private MySqlSalesOrderRepository $orders;
    private SalesOrderService $sales;
    private AuthContext $admin;
    private StockReservationReconciler $reconciler;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->balances = new MySqlStockRepository($this->pdo);
        $this->stock = $this->stockService(new MySqlAuditLogRepository($this->pdo));
        $this->orders = new MySqlSalesOrderRepository($this->pdo);
        $this->sales = $this->salesService($this->stock);
        $this->admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $this->reconciler = new StockReservationReconciler($this->pdo);
    }

    public function testDemoSeedSatisfiesTheReservationInvariant(): void
    {
        self::assertSame(0, $this->reconciler->mismatches());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM product_stocks WHERE reserved_quantity > quantity'));
        self::assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM sales_orders WHERE status = 'Approved'"));
        self::assertSame(
            // D-14/ADR-020: the unissued remainder of Approved and PartiallyFulfilled orders without a closed remainder.
            $this->scalar("SELECT COALESCE(SUM(soi.quantity - soi.issued_quantity), 0) FROM sales_order_items soi JOIN sales_orders so ON so.id = soi.sales_order_id LEFT JOIN sales_order_closures c ON c.sales_order_id = so.id WHERE so.status IN ('Approved', 'PartiallyFulfilled') AND c.sales_order_id IS NULL"),
            $this->scalar('SELECT COALESCE(SUM(reserved_quantity), 0) FROM product_stocks'),
        );
        self::assertSame(0, $this->ledgerMismatches(), 'Physical stock still equals the ledger; reservations are outside it.');
    }

    public function testApproveReservesIssueReleasesAndLedgerOnlySeesTheIssue(): void
    {
        $product = $this->product('RSV-FLOW', 10, [1 => 12]);
        $order = $this->pending($product, 5);
        $ledger = $this->scalar('SELECT COUNT(*) FROM stock_ledger');

        $this->sales->approve($this->admin, $order->id());

        self::assertSame([12, 5], $this->pair($product, 1));
        self::assertSame($ledger, $this->scalar('SELECT COUNT(*) FROM stock_ledger'), 'Approval appends no ledger row.');
        $metadata = $this->auditMetadata('sales-orders.approve', $order->id());
        self::assertSame(5, $metadata['reserved_units']);
        self::assertEquals([['product_id' => $product, 'quantity' => 5]], $metadata['reservation']); // MySQL JSON reorders object keys
        self::assertSame(0, $this->reconciler->mismatches());

        $this->sales->issue($this->admin, $order->id(), str_repeat('c', 32));
        $this->sales->issue($this->admin, $order->id(), str_repeat('c', 32)); // replay: no second release

        self::assertSame([7, 0], $this->pair($product, 1));
        self::assertSame(SalesOrder::STATUS_FULFILLED, $this->orders->findById($order->id())?->status());
        self::assertSame($ledger + 1, $this->scalar('SELECT COUNT(*) FROM stock_ledger'));
        self::assertSame(5, $this->auditMetadata('sales-orders.issue', $order->id())['reservation_released_units']);
        self::assertSame(0, $this->reconciler->mismatches());
        self::assertSame(0, $this->ledgerMismatches());
    }

    public function testShortApprovalChangesNothingAndCompetingReservationsCannotOversell(): void
    {
        $product = $this->product('RSV-SHORT', 1, [1 => 6]);
        $first = $this->pending($product, 4);
        $second = $this->pending($product, 3);
        $this->sales->approve($this->admin, $first->id());
        $audits = $this->scalar('SELECT COUNT(*) FROM audit_logs');

        try {
            $this->sales->approve($this->admin, $second->id());
            self::fail('Only 2 of the 6 units are still available.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->statusCode());
            self::assertStringContainsString('Insufficient available stock', $exception->getMessage());
            self::assertStringContainsString('available 2', $exception->getMessage());
        }

        self::assertSame([6, 4], $this->pair($product, 1));
        self::assertSame(SalesOrder::STATUS_PENDING_APPROVAL, $this->orders->findById($second->id())?->status());
        self::assertNull($this->orders->findById($second->id())?->approvedBy());
        self::assertSame($audits, $this->scalar('SELECT COUNT(*) FROM audit_logs'));
    }

    public function testCancellingAnApprovedOrderReleasesInTheSameTransaction(): void
    {
        $product = $this->product('RSV-CANCEL', 1, [2 => 9]);
        $order = $this->sales->createDraft($this->admin, 1, 2, [['product_id' => $product, 'quantity' => 9]]);
        $this->sales->submit($this->admin, $order->id());
        $this->sales->approve($this->admin, $order->id());
        self::assertSame([9, 9], $this->pair($product, 2));

        $this->sales->rejectOrCancel($this->admin, $order->id());

        self::assertSame([9, 0], $this->pair($product, 2));
        self::assertSame(SalesOrder::STATUS_CANCELLED, $this->orders->findById($order->id())?->status());
        $metadata = $this->auditMetadata('sales-orders.cancel', $order->id());
        self::assertSame('Approved', $metadata['previous_status']);
        self::assertSame(9, $metadata['reservation_released_units']);
        self::assertSame(0, $this->reconciler->mismatches());

        $timeline = (new DocumentTimelineService(new MySqlDocumentTimelineRepository($this->pdo)))->forDocument($this->admin, 'SO', $order->id());
        $details = array_column($timeline['events'], 'detail', 'title');
        self::assertSame('Reserved 9 units at the source warehouse', $details['SO approved']);
        self::assertSame('Released reservation of 9 units', $details['SO cancelled']);
    }

    public function testFailedApprovalAuditRollsBackTheReservationAndTheStatus(): void
    {
        $product = $this->product('RSV-AUDIT', 1, [1 => 5]);
        $order = $this->pending($product, 2);
        $failing = $this->salesService($this->stockService(new class implements AuditLogRepositoryInterface {
            public function append(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, RequestOrigin $origin, array $metadata = []): void
            {
                throw new RuntimeException('audit unavailable');
            }
        }));

        try {
            $failing->approve($this->admin, $order->id());
            self::fail('Approval committed without its audit row.');
        } catch (RuntimeException) {
        }

        self::assertSame([5, 0], $this->pair($product, 1));
        self::assertSame(SalesOrder::STATUS_PENDING_APPROVAL, $this->orders->findById($order->id())?->status());
    }

    public function testAdjustmentTransferAndSupplierReturnCannotCutIntoReservedStock(): void
    {
        $product = $this->product('RSV-GUARD', 1, []);
        $receipt = $this->receiveByPurchaseOrder($product, 10);
        $order = $this->pending($product, 8);
        $this->sales->approve($this->admin, $order->id());
        [$business, $reviewer] = $this->business();
        $ids = [
            $business->propose($this->admin, 'Adjustment', 1, null, 'Count', [['product_id' => $product, 'quantity' => 7, 'baseline' => 10]]),
            $business->propose($this->admin, 'Transfer', 1, 2, 'Move', [['product_id' => $product, 'quantity' => 3]]),
            $business->propose($this->admin, 'SupplierReturn', 0, null, 'Damaged', [['quantity' => 3, 'source_ledger_id' => $receipt]]),
        ];
        $ledger = $this->scalar('SELECT COUNT(*) FROM stock_ledger');

        foreach ($ids as $id) {
            $business->decide($reviewer, $id, 'Approved', 'Checked');
            try {
                $business->post($this->admin, $id);
                self::fail('Operation ' . $id . ' cut into reserved stock.');
            } catch (ValidationException $exception) {
                self::assertSame(422, $exception->statusCode());
                self::assertStringContainsString('reserved for approved sales orders', $exception->getMessage());
            }
        }

        self::assertSame([10, 8], $this->pair($product, 1));
        self::assertSame($ledger, $this->scalar('SELECT COUNT(*) FROM stock_ledger'));
        self::assertSame(3, $this->scalar("SELECT COUNT(*) FROM inventory_operations WHERE id IN (" . implode(',', $ids) . ") AND status = 'Approved'"));

        $increase = $business->propose($this->admin, 'Adjustment', 1, null, 'Found', [['product_id' => $product, 'quantity' => 12, 'baseline' => 10]]);
        $business->decide($reviewer, $increase, 'Approved', 'Checked');
        $business->post($this->admin, $increase);
        self::assertSame([12, 8], $this->pair($product, 1), 'Increases are unaffected.');
        $business->post($this->admin, $ids[2]);
        self::assertSame([9, 8], $this->pair($product, 1), 'A decrease that leaves the reservation covered posts.');
        self::assertSame(0, $this->ledgerMismatches());
    }

    public function testDatabaseCheckRejectsReservationAboveQuantity(): void
    {
        $product = $this->product('RSV-CHECK', 1, [1 => 3]);

        try {
            $this->pdo->exec("UPDATE product_stocks SET reserved_quantity = 4 WHERE product_id = {$product} AND warehouse_id = 1");
            self::fail('CHECK (reserved_quantity <= quantity) did not fire.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('chk_product_stocks_reserved_lte_quantity', $exception->getMessage());
        }
        try {
            $this->balances->issueReserved($product, 1, 1);
            self::fail('Reserved cannot go below zero.');
        } catch (PDOException) {
        }
        self::assertSame([3, 0], $this->pair($product, 1));
    }

    public function testLowStockReplenishmentAndApiUseAvailableQuantity(): void
    {
        $product = $this->product('RSV-LOW', 10, [1 => 12, 2 => 20, 3 => 20]);
        $queries = new MySqlOperationalQueryRepository($this->pdo);
        $before = (int) $queries->adminDashboard()['low_stock_count'];
        self::assertNotContains('RSV-LOW', $this->lowSkus());

        $order = $this->pending($product, 4);
        $this->sales->approve($this->admin, $order->id());

        self::assertSame($before + 1, (int) $queries->adminDashboard()['low_stock_count'], '12 on hand, 8 available < 10.');
        $row = array_values(array_filter($queries->lowStockRows(), static fn (array $row): bool => $row['sku'] === 'RSV-LOW'))[0];
        self::assertSame([12, 4, 8, 10], [(int) $row['quantity'], (int) $row['reserved_quantity'], (int) $row['available_quantity'], (int) $row['reorder_point']]);
        self::assertContains('RSV-LOW', $this->lowSkus());
        $recommendation = (new MySqlBusinessOperationRepository($this->pdo))->recommendations('RSV-LOW', 10, 0, 1);
        self::assertSame(2, (int) $recommendation[0]['suggested'], 'Suggested = 10 − 8 available − 0 open supply.');
        $this->assertCommandLists('RSV-LOW | ', 'quantity=12 | reorder=10 | reserved=4 | available=8');

        $api = $queries->productAvailability('RSV-LOW') ?? self::fail('availability');
        self::assertSame(52, $api['total_quantity'], 'Existing field keeps the physical total.');
        self::assertSame(4, $api['total_reserved']);
        self::assertSame(48, $api['total_available']);
        $first = array_values(array_filter($api['warehouses'], static fn (array $row): bool => $row['reserved_quantity'] > 0))[0];
        self::assertSame(['quantity' => 12, 'reserved_quantity' => 4, 'available_quantity' => 8], array_intersect_key($first, ['quantity' => 0, 'reserved_quantity' => 0, 'available_quantity' => 0]));

        $stocks = (new MySqlProductRepository($this->pdo))->stocksForProduct($product);
        $cikarang = array_values(array_filter($stocks, static fn ($stock): bool => $stock->warehouseId() === 1))[0];
        self::assertSame([12, 4, 8, true], [$cikarang->quantity(), $cikarang->reservedQuantity(), $cikarang->availableQuantity(), $cikarang->isLowStock()]);
        self::assertSame($this->scalar('SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id = ps.product_id JOIN warehouses w ON w.id = ps.warehouse_id WHERE ' . LowStockSql::isLow() . ' AND p.is_active = 1 AND w.is_active = 1'), (int) $queries->adminDashboard()['low_stock_count']);
    }

    public function testMigrationReportsConflictingOrdersAndChangesNothingUntilResolved(): void
    {
        $product = $this->product('RSV-MIG', 1, [1 => 5]);
        $short = $this->pending($product, 7);
        $quantities = $this->scalar('SELECT COALESCE(SUM(quantity), 0) FROM product_stocks');
        $this->pdo->exec('ALTER TABLE product_stocks DROP CHECK chk_product_stocks_reserved_lte_quantity');
        $this->pdo->exec('ALTER TABLE product_stocks DROP COLUMN reserved_quantity');
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'stock-reservation-1'");
        // Practice data: an order approved before reservations existed although its source was already short.
        $this->orders->approve($short->id(), 1);

        [$code, $output] = $this->runMigration();
        self::assertSame(2, $code);
        $report = json_decode($output, true);
        self::assertSame('conflict', $report['status']);
        self::assertCount(1, $report['conflicts']);
        self::assertSame(['sku' => 'RSV-MIG', 'quantity' => 5, 'required' => 7, 'orders' => [$short->orderNumber()]], array_intersect_key($report['conflicts'][0], ['sku' => 0, 'quantity' => 0, 'required' => 0, 'orders' => 0]));
        self::assertSame(0, $this->scalar('SELECT COALESCE(SUM(reserved_quantity), 0) FROM product_stocks'), 'Nothing reserved, nothing clamped.');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'stock-reservation-1'"));
        self::assertSame(SalesOrder::STATUS_APPROVED, $this->orders->findById($short->id())?->status(), 'The migration never changes orders.');

        // The owner resolves it (here: receives 2 more), then the migration completes and is repeatable.
        $this->stock->receive(1, [new StockMovement($product, 2)], 1, 'TEST', $product);
        foreach ([1, 2] as $run) {
            [$code, $output] = $this->runMigration();
            self::assertSame(0, $code, 'Run ' . $run . ': ' . $output);
            $result = json_decode($output, true);
            self::assertSame('ready', $result['status']);
            self::assertSame($run === 1 ? $this->scalar('SELECT COUNT(*) FROM product_stocks WHERE reserved_quantity > 0') : 0, $result['updated_pairs']);
        }
        self::assertSame(0, $this->reconciler->mismatches());
        self::assertSame([7, 7], $this->pair($product, 1));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'product_stocks' AND constraint_name = 'chk_product_stocks_reserved_lte_quantity'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'stock-reservation-1'"));
        self::assertSame($quantities + 2, $this->scalar('SELECT COALESCE(SUM(quantity), 0) FROM product_stocks'));
    }

    public function testReconcilerRepairsDriftAndCountsMismatches(): void
    {
        $product = $this->product('RSV-DRIFT', 1, [1 => 5]);
        $order = $this->pending($product, 3);
        $this->orders->approve($order->id(), 1); // approved behind the service's back: drift of one pair
        self::assertSame(1, $this->reconciler->mismatches());

        $report = $this->reconciler->reconcile();

        self::assertSame('ready', $report['status']);
        self::assertSame(1, $report['updated_pairs']);
        self::assertSame(0, $this->reconciler->mismatches());
        self::assertSame([5, 3], $this->pair($product, 1));
    }

    private function stockService(AuditLogRepositoryInterface $audit): StockService
    {
        return new StockService(new MySqlStockRepository($this->pdo), new MySqlStockLedgerRepository($this->pdo), $audit, new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
    }

    private function salesService(StockService $stock): SalesOrderService
    {
        $warehouses = [];
        foreach ([1, 2, 3] as $id) { $warehouses[$id] = new Warehouse($id, 'Warehouse ' . $id, '', true); }

        return new SalesOrderService($this->orders, new MySqlDocumentSequenceRepository($this->pdo), new MySqlProductRepository($this->pdo), [1 => new Customer(1, 'Customer', '', '', '', true)], $warehouses, $stock, new OperationIdempotency(new MySqlOperationRequestRepository($this->pdo)));
    }

    /** Receives into warehouse 1 through a real PO and returns the Receipt ledger id (a supplier return needs one). */
    private function receiveByPurchaseOrder(int $product, int $quantity): int
    {
        $purchase = new \App\Service\PurchaseOrderService(new \App\Repository\MySql\MySqlPurchaseOrderRepository($this->pdo), new MySqlDocumentSequenceRepository($this->pdo), new MySqlProductRepository($this->pdo), [1 => new \App\Entity\Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Warehouse 1', '', true)], $this->stock);
        $po = $purchase->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => $quantity]]);
        $purchase->markOrdered($this->admin, $po->id());
        $purchase->receive($this->admin, $po->id(), [$po->items()[0]->id() => $quantity]);

        return (new MySqlStockLedgerRepository($this->pdo))->forReference('PO', $po->id())[0]->id();
    }

    /** @return array{0: BusinessOperationService, 1: AuthContext} */
    private function business(): array
    {
        $user = (new MySqlUserRepository($this->pdo))->create('Reservation reviewer', 'reservation-reviewer@example.test', password_hash('password', PASSWORD_DEFAULT), 'Admin', true);

        return [new BusinessOperationService(new MySqlBusinessOperationRepository($this->pdo), $this->stock, new MySqlAuditLogRepository($this->pdo)), new AuthContext($user->id(), $user->email(), 'Admin')];
    }

    /** Creates a product with zero pairs in every warehouse and receives the given quantities. @param array<int,int> $quantities */
    private function product(string $sku, int $reorderPoint, array $quantities): int
    {
        $id = (new ProductService(new MySqlProductRepository($this->pdo), new MasterDataAuthorizationService(), $this->stock))
            ->create($this->admin, new ProductInput($sku, 'Reservation ' . $sku, 'pcs', 10.0, 20.0, $reorderPoint, 1))->id();
        foreach ($quantities as $warehouse => $quantity) {
            $this->stock->receive($warehouse, [new StockMovement($id, $quantity)], 1, 'TEST', $id);
        }

        return $id;
    }

    private function pending(int $product, int $quantity): SalesOrder
    {
        $order = $this->sales->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => $quantity]]);
        $this->sales->submit($this->admin, $order->id());

        return $order;
    }

    /** @return array{0: int, 1: int} quantity and reserved_quantity */
    private function pair(int $product, int $warehouse): array
    {
        return [$this->balances->quantity($product, $warehouse), $this->balances->reservedQuantity($product, $warehouse)];
    }

    /** @return array<string, mixed> */
    private function auditMetadata(string $action, int $orderId): array
    {
        $statement = $this->pdo->prepare("SELECT metadata_json FROM audit_logs WHERE action = ? AND entity_type = 'SO' AND entity_id = ? AND status = 'success'");
        $statement->execute([$action, $orderId]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $rows, $action . ' must be audited exactly once.');

        return json_decode((string) $rows[0], true, 512, JSON_THROW_ON_ERROR);
    }

    private function ledgerMismatches(): int
    {
        return $this->scalar("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type = 'Receipt' THEN quantity WHEN movement_type = 'Issue' THEN -quantity ELSE COALESCE(quantity_delta, 0) END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)");
    }

    /** @return list<string> */
    private function lowSkus(): array
    {
        return array_map(static fn ($p): string => $p->sku(), (new MySqlProductRepository($this->pdo))->search(ProductSearchCriteria::fromArray(['stock_status' => 'low', 'q' => 'RSV-']))->items());
    }

    private function assertCommandLists(string $prefix, string $expected): void
    {
        $process = proc_open([PHP_BINARY, 'scripts/check-low-stock.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $lines = array_values(array_filter(explode("\n", $output), static fn (string $line): bool => str_starts_with($line, $prefix)));
        self::assertCount(1, $lines);
        self::assertStringContainsString($expected, $lines[0]);
    }

    /** @return array{0: int, 1: string} exit code and stdout */
    private function runMigration(): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/migrate-stock-reservation.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
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
