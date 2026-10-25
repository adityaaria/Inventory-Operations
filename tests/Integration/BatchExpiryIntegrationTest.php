<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Customer;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\MySql\BatchLotChecks;
use App\Repository\MySql\LowStockSql;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlBusinessOperationRepository;
use App\Repository\MySql\MySqlDocumentSequenceRepository;
use App\Repository\MySql\MySqlDocumentTimelineRepository;
use App\Repository\MySql\MySqlLotQueryRepository;
use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Repository\MySql\MySqlOrderExceptionRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlPurchaseOrderRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Repository\MySql\MySqlUserRepository;
use App\Repository\MySql\PartialFulfilmentChecks;
use App\Repository\MySql\StockReservationReconciler;
use App\Repository\MySql\TransferTransitChecks;
use App\Security\AuthContext;
use App\Service\BusinessOperationService;
use App\Service\DocumentTimelineService;
use App\Service\LotService;
use App\Service\MasterDataAuthorizationService;
use App\Service\OrderLinePricing;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\BusinessDate;
use App\Support\ProductInput;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** D-17 / ADR-023 against real MySQL: lots, FEFO, expiry, invariants, read models, recall and the migration. */
final class BatchExpiryIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MySqlStockRepository $balances;
    private StockService $stock;
    private BusinessOperationService $operations;
    private AuthContext $admin;
    private AuthContext $reviewer;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->balances = new MySqlStockRepository($this->pdo);
        $this->stock = new StockService($this->balances, new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
        $products = new MySqlProductRepository($this->pdo);
        $this->operations = new BusinessOperationService(new MySqlBusinessOperationRepository($this->pdo), $this->stock, new MySqlAuditLogRepository($this->pdo), null, $products);
        $this->admin = new AuthContext(1, 'admin@example.test', 'Admin');
        $user = (new MySqlUserRepository($this->pdo))->create('Lot reviewer', 'lot-reviewer@example.test', password_hash('password', PASSWORD_DEFAULT), 'Admin', true);
        $this->reviewer = new AuthContext($user->id(), $user->email(), 'Admin');
    }

    public function testDemoSeedHasLotsNearExpiryExpiredStockAndEveryInvariantHolds(): void
    {
        self::assertSame(array_fill_keys(['lot_aggregate_mismatches', 'lot_ledger_mismatches', 'lot_count_mismatches', 'ledger_rows_without_lot', 'issue_from_expired_lot', 'fefo_violations', 'transfer_lot_mismatches'], 0), (new BatchLotChecks($this->pdo))->report());
        $statistics = (new BatchLotChecks($this->pdo))->statistics();
        self::assertGreaterThan(1000, $statistics['lots']);
        self::assertGreaterThan(100, $statistics['tracked_products']);
        self::assertSame(0, $statistics['tracked_lots_without_expiry']);
        self::assertGreaterThan(0, $statistics['transfer_lot_rows']);
        self::assertGreaterThan(0, $statistics['issue_rows_multi_lot_lines'], 'Some shipments took units from two lots (FEFO across lots).');
        self::assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM inventory_operations WHERE purpose = 'ExpiredWriteOff' AND status = 'Posted'"));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM stock_ledger WHERE lot_id IS NULL'), 'The generated seed has no pre-migration history.');
        self::assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM stock_lots l JOIN products p ON p.id = l.product_id WHERE p.tracks_expiry = 0 AND l.expiry_date IS NOT NULL"), 'Detergent carries an expiry date without tracking it.');
        $this->assertOldInvariants();
    }

    public function testReceiptIssueTransferReturnAndWriteOffKeepLotsEqualToStockAndLedger(): void
    {
        $product = $this->product('LOT-LIFE', true);
        [$po, $poRepository] = $this->purchasing();
        $order = $po->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => 10]]);
        $po->markOrdered($this->admin, $order->id());
        $item = $order->items()[0]->id();
        $soon = $this->date('+20 day');
        $later = $this->date('+200 day');
        $po->receive($this->admin, $order->id(), [$item => 10], str_repeat('a', 32), [$item => [
            ['lot_code' => 'LIFE-LATE', 'expiry_date' => $later, 'quantity' => 6],
            ['lot_code' => 'LIFE-SOON', 'expiry_date' => $soon, 'quantity' => 4],
        ]]);
        // Replay with the same key and lots is a no-op; a different split with the same key is a 409.
        $po->receive($this->admin, $order->id(), [$item => 10], str_repeat('a', 32), [$item => [['lot_code' => 'LIFE-LATE', 'expiry_date' => $later, 'quantity' => 6], ['lot_code' => 'LIFE-SOON', 'expiry_date' => $soon, 'quantity' => 4]]]);
        try {
            $po->receive($this->admin, $order->id(), [$item => 10], str_repeat('a', 32), [$item => [['lot_code' => 'OTHER', 'expiry_date' => $later, 'quantity' => 10]]]);
            self::fail('Changed payload with the same key.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->statusCode());
        }
        self::assertSame(['Received', 10], [$poRepository->findById($order->id())->status(), $this->balances->quantity($product, 1)]);
        self::assertSame([['LIFE-SOON', 4], ['LIFE-LATE', 6]], $this->lots($product, 1));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type = 'PO' AND reference_id = " . $order->id() . ' AND lot_id IS NOT NULL'));

        // FEFO: an order of 5 ships the 4 soon-expiring units first, then 1 of the later lot.
        [$so] = $this->selling();
        $sale = $so->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => 5]]);
        $so->submit($this->admin, $sale->id());
        $so->approve($this->admin, $sale->id());
        $so->issue($this->admin, $sale->id());
        self::assertSame([['LIFE-SOON', 4], ['LIFE-LATE', 1]], $this->pdo->query('SELECT l.lot_code, s.quantity FROM stock_ledger s JOIN stock_lots l ON l.id = s.lot_id WHERE s.reference_type = \'SO\' AND s.reference_id = ' . $sale->id() . ' ORDER BY s.id')->fetchAll(PDO::FETCH_NUM));
        self::assertSame([['LIFE-SOON', 0], ['LIFE-LATE', 5]], $this->lots($product, 1));

        // Transfer: dispatch picks the later lot; the destination lot keeps its code, expiry and unit cost.
        $transfer = $this->operations->propose($this->admin, 'Transfer', 1, 2, 'Rebalance', [['product_id' => $product, 'quantity' => 3]]);
        $this->operations->decide($this->reviewer, $transfer, 'Approved', 'OK');
        $this->operations->dispatch($this->admin, $transfer);
        $this->operations->receive($this->admin, $transfer, [$product => ['good' => 2, 'damaged' => 1]], 'One crushed');
        self::assertSame([['LIFE-LATE', 2]], $this->lots($product, 2));
        self::assertSame($later, (string) $this->value("SELECT expiry_date FROM stock_lots WHERE product_id = $product AND warehouse_id = 2"));
        self::assertSame([3, 2, 1, 0], array_map('intval', $this->pdo->query("SELECT dispatched_quantity, received_quantity, damaged_quantity, short_quantity FROM inventory_operation_lots WHERE operation_id = $transfer")->fetch(PDO::FETCH_NUM)));

        // Customer return of the soon lot's issue row goes back to that lot.
        $issueRow = $this->scalar("SELECT s.id FROM stock_ledger s JOIN stock_lots l ON l.id = s.lot_id WHERE s.reference_type = 'SO' AND s.reference_id = " . $sale->id() . " AND l.lot_code = 'LIFE-SOON'");
        $return = $this->operations->propose($this->admin, 'CustomerReturn', 0, null, 'Returned', [['source_ledger_id' => (string) $issueRow, 'quantity' => '2', 'fit_for_stock' => true]]);
        $this->operations->decide($this->reviewer, $return, 'Approved', 'OK');
        $this->operations->post($this->admin, $return);
        self::assertSame([['LIFE-SOON', 2], ['LIFE-LATE', 2]], $this->lots($product, 1));

        // Time passes: a lot received later with a short date expires (simulated by moving its date; it was never issued).
        $this->stock->receive(1, [new \App\Service\StockMovement($product, 2, [new \App\Service\LotSplit('LIFE-OLD', $this->date('+3 day'), 2, '10.00')])], 1, 'TEST', 1);
        $this->pdo->exec("UPDATE stock_lots SET expiry_date = '" . $this->date('-1 day') . "' WHERE product_id = $product AND lot_code = 'LIFE-OLD'");
        self::assertSame(2, $this->balances->expiredQuantity($product, 1));
        $stock = (new MySqlProductRepository($this->pdo))->stocksForProduct($product);
        $main = array_values(array_filter($stock, static fn ($row): bool => $row->warehouseId() === 1))[0];
        self::assertSame([6, 2, 4], [$main->quantity(), $main->expiredQuantity(), $main->availableQuantity()]);

        // Expired write-off: proposed, approved by another Admin, posted per lot.
        $writeOff = $this->operations->proposeExpiredWriteOff($this->admin, 1, 'Destroy expired', $product);
        $this->operations->decide($this->reviewer, $writeOff, 'Approved', 'Witnessed');
        $this->operations->post($this->admin, $writeOff);
        self::assertSame([['LIFE-OLD', 0], ['LIFE-SOON', 2], ['LIFE-LATE', 2]], $this->lots($product, 1));
        self::assertSame(-2, $this->scalar("SELECT quantity_delta FROM stock_ledger WHERE reference_type = 'ADJUSTMENT' AND reference_id = $writeOff"));
        $this->assertLotInvariants();
        $this->assertOldInvariants();
    }

    public function testExpiredLotsAreNeverIssuedOverReservationIsRefusedAndReported(): void
    {
        $product = $this->product('LOT-OVER', true);
        $this->stock->receive(1, [new \App\Service\StockMovement($product, 8, [new \App\Service\LotSplit('OVER-A', $this->date('+100 day'), 4, '10.00'), new \App\Service\LotSplit('OVER-B', $this->date('+10 day'), 4, '10.00')])], 1, 'TEST', 1);
        [$so, $orders] = $this->selling();
        $sale = $so->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => 7]]);
        $so->submit($this->admin, $sale->id());
        $so->approve($this->admin, $sale->id());
        $this->pdo->exec("UPDATE stock_lots SET expiry_date = '" . $this->date('-2 day') . "' WHERE lot_code = 'OVER-B'");
        try {
            $so->issue($this->admin, $sale->id());
            self::fail('The reservation outgrew the sellable stock.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('expired after the order was approved', $exception->getMessage());
        }
        self::assertSame([8, 7, 'Approved'], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1), $orders->findById($sale->id())->status()]);
        // A partial shipment within the sellable stock still works and never touches the expired lot.
        $so->issue($this->admin, $sale->id(), null, [$sale->items()[0]->id() => 4]);
        self::assertSame([['OVER-B', 4], ['OVER-A', 0]], $this->lots($product, 1), 'Only the expired lot is left.');
        $service = new LotService(new MySqlLotQueryRepository($this->pdo), 30);
        $report = $service->expiryReport($this->admin, ['q' => 'LOT-OVER', 'status' => 'expired']);
        self::assertSame(1, $report['result']->total());
        $over = array_values(array_filter($report['over_reserved'], static fn (array $row): bool => $row['sku'] === 'LOT-OVER'));
        self::assertSame([3, 4, $sale->orderNumber()], [(int) $over[0]['reserved_quantity'], (int) $over[0]['expired_quantity'], $over[0]['orders']]);
        self::assertSame(0, (new BatchLotChecks($this->pdo))->report()['issue_from_expired_lot']);
        // Low stock and the API use available without expired stock.
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id = ps.product_id WHERE p.sku = 'LOT-OVER' AND ps.warehouse_id = 1 AND " . LowStockSql::isLow()));
        $api = (new MySqlOperationalQueryRepository($this->pdo))->productAvailability('LOT-OVER');
        $main = array_values(array_filter($api['warehouses'], static fn (array $row): bool => $row['available_quantity'] === 1))[0];
        self::assertSame([4, -3, null], [$main['expired_quantity'], $main['available_excluding_expired'], $main['nearest_expiry']]);
        self::assertSame(4, $api['total_expired']);
        $this->assertLotInvariants();
    }

    public function testDatabaseGuardsLotRows(): void
    {
        $product = $this->product('LOT-DB', false);
        $this->stock->receive(1, [new \App\Service\StockMovement($product, 2, [new \App\Service\LotSplit('DB-1', null, 2, '5.00')])], 1, 'TEST', 1);
        $lot = $this->scalar("SELECT id FROM stock_lots WHERE lot_code = 'DB-1'");
        foreach ([
            "UPDATE stock_lots SET quantity = quantity - 3 WHERE id = $lot",
            "INSERT INTO stock_lots (product_id, warehouse_id, lot_seq, lot_code, expiry_date, unit_cost, quantity, source_type) VALUES ($product, 1, 9, 'DB-1', NULL, 5.00, 0, 'PO')",
            "INSERT INTO stock_lots (product_id, warehouse_id, lot_seq, lot_code, unit_cost, quantity, opening_quantity, source_type) VALUES ($product, 1, 10, 'DB-2', 5.00, 1, 1, 'PO')",
            "INSERT INTO stock_lots (product_id, warehouse_id, lot_seq, lot_code, unit_cost, source_type) VALUES ($product, 1, 1, 'DB-3', 5.00, 'PO')",
            "INSERT INTO stock_lots (product_id, warehouse_id, lot_seq, lot_code, unit_cost, source_type) VALUES ($product, 1, 11, '  ', 5.00, 'PO')",
            'INSERT INTO stock_lot_watermark (id, legacy_ledger_max_id) VALUES (2, 0)',
            "INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, lot_id) VALUES ($product, 1, 'Receipt', 1, 'TEST', 1, 1, 999999)",
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('Accepted: ' . $sql);
            } catch (PDOException) {
                self::assertTrue(true);
            }
        }
        $this->assertLotInvariants();
    }

    public function testReadModelsShowLotsRecallTraceTimelineAndDashboard(): void
    {
        $product = $this->product('LOT-READ', true);
        [$po] = $this->purchasing();
        $order = $po->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => 5]]);
        $po->markOrdered($this->admin, $order->id());
        $item = $order->items()[0]->id();
        $po->receive($this->admin, $order->id(), [$item => 5], null, [$item => [['lot_code' => 'RECALL-7', 'expiry_date' => $this->date('+5 day'), 'quantity' => 5]]]);
        [$so] = $this->selling();
        $sale = $so->createDraft($this->admin, 2, 1, [['product_id' => $product, 'quantity' => 3]]);
        $so->submit($this->admin, $sale->id());
        $so->approve($this->admin, $sale->id());
        $so->issue($this->admin, $sale->id());

        $service = new LotService(new MySqlLotQueryRepository($this->pdo), 30);
        $lot = $this->scalar("SELECT id FROM stock_lots WHERE lot_code = 'RECALL-7'");
        $trace = $service->trace($this->admin, $lot);
        self::assertSame(['Near expiry', 5], [$trace['lot']['status'], $trace['lot']['days_left']]);
        self::assertSame([['PO', $order->orderNumber()], ['SO', $sale->orderNumber()]], array_map(static fn (array $row): array => [$row['reference_type'], $row['po_number'] ?? $row['so_number']], $trace['movements']));
        self::assertNotNull($trace['movements'][0]['supplier_name']);
        self::assertSame((string) $this->value('SELECT name FROM customers WHERE id = 2'), $trace['movements'][1]['customer_name']);
        self::assertSame(1, $service->search($this->admin, ['q' => 'RECALL-7'])->total());
        self::assertSame('RECALL-7', $service->productLots($this->admin, $product)[0]['lot_code']);
        $near = $service->expiryReport($this->admin, ['status' => 'near', 'q' => 'RECALL'])['result']->items();
        self::assertSame([['RECALL-7', 2, 'Near expiry']], array_map(static fn (array $row): array => [$row['lot_code'], (int) $row['quantity'], $row['status']], $near));
        ob_start();
        ($service->expiryCsv($this->admin, ['q' => 'RECALL']))();
        $csv = (string) ob_get_clean();
        self::assertStringContainsString('SKU,Product,Warehouse,Lot,ExpiryDate,DaysLeft,Status,Quantity,BaseUnit,UnitCost', $csv);
        self::assertStringContainsString('RECALL-7', $csv);

        $timeline = (new DocumentTimelineService(new MySqlDocumentTimelineRepository($this->pdo)))->forDocument($this->admin, 'SO', $sale->id());
        self::assertStringContainsString('lot RECALL-7', implode(' ', array_map(static fn (array $event): string => (string) $event['detail'], $timeline['events'])));
        $dashboard = (new MySqlOperationalQueryRepository($this->pdo, 30))->adminDashboard();
        self::assertGreaterThanOrEqual(1, $dashboard['near_expiry_lots']);
        self::assertSame($this->scalar('SELECT COUNT(*) FROM stock_lots WHERE quantity > 0 AND expiry_date < DATE(UTC_TIMESTAMP() + INTERVAL ' . BusinessDate::offsetSeconds() . ' SECOND)'), $dashboard['expired_lots']);
        self::assertArrayHasKey('expired_lots', (new MySqlOperationalQueryRepository($this->pdo, 30))->warehouseDashboard());
        self::assertSame((string) $this->value('SELECT ' . \App\Repository\MySql\ExpirySql::today()), BusinessDate::today(), 'SQL and PHP agree on the business date.');
    }

    public function testFefoCheckDetectsAnIssueThatSkippedAnEarlierLot(): void
    {
        $product = $this->product('LOT-FEFO', false);
        $this->stock->receive(1, [new \App\Service\StockMovement($product, 4, [new \App\Service\LotSplit('F-EARLY', $this->date('+30 day'), 2, '1.00'), new \App\Service\LotSplit('F-LATE', $this->date('+90 day'), 2, '1.00')])], 1, 'TEST', 1);
        self::assertSame(0, (new BatchLotChecks($this->pdo))->report()['fefo_violations']);
        // Simulate a buggy issue straight into the ledger and lots (bypassing StockService) from the later lot.
        $late = $this->scalar("SELECT id FROM stock_lots WHERE lot_code = 'F-LATE'");
        // D-20 / ADR-026: a row with a lot must carry its cost (chk_stock_ledger_cost), so the simulated row does too.
        $this->pdo->exec("INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, lot_id, unit_cost, cost_amount) VALUES ($product, 1, 'Issue', 1, 'TEST', 2, 1, $late, 1.00, -1.00)");
        $this->pdo->exec("UPDATE stock_lots SET quantity = quantity - 1 WHERE id = $late");
        $this->pdo->exec("UPDATE product_stocks SET quantity = quantity - 1 WHERE product_id = $product AND warehouse_id = 1");
        self::assertSame(1, (new BatchLotChecks($this->pdo))->report()['fefo_violations']);
        $this->pdo->exec("UPDATE stock_lots SET expiry_date = '" . $this->date('-1 day') . "' WHERE id = $late");
        self::assertSame(1, (new BatchLotChecks($this->pdo))->report()['issue_from_expired_lot'], 'An issue dated after the lot expired is flagged.');
    }

    public function testMigrationDryRunApplyConflictAndIdempotency(): void
    {
        $before = $this->stockChecksum();
        $this->downgradeToPreLotSchema();
        [$code, $output] = $this->runMigration('--dry-run');
        self::assertSame(0, $code, $output);
        self::assertSame('missing', json_decode($output, true)['schema']);

        // Apply the DDL only (as the first part of the runner does) to get a detailed dry run of the data step.
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261012-batch-expiry.sql'));
        [$code, $output] = $this->runMigration('--dry-run');
        self::assertSame(0, $code, $output);
        $dry = json_decode($output, true);
        $pairs = $this->scalar('SELECT COUNT(*) FROM product_stocks WHERE quantity > 0');
        self::assertSame([$pairs, $this->scalar('SELECT SUM(quantity) FROM product_stocks')], [$dry['pairs_to_convert'], $dry['units_to_convert']]);
        self::assertContains('Minuman', $dry['food_beverage_categories']);
        self::assertNotContains('Perawatan Rumah Tangga', $dry['food_beverage_categories']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM stock_lots'), 'The dry run changes nothing.');

        // Unsafe data (stock ≠ ledger) stops the migration with a report and changes nothing.
        $this->pdo->exec('UPDATE product_stocks SET quantity = quantity + 1 WHERE product_id = 1 AND warehouse_id = 1');
        [$code, $output] = $this->runMigration();
        self::assertSame(2, $code, $output);
        self::assertSame(1, json_decode($output, true)['unsafe']['ledger_mismatches']);
        self::assertSame([0, 0], [$this->scalar('SELECT COUNT(*) FROM stock_lots'), $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'batch-expiry-1'")]);
        $this->pdo->exec('UPDATE product_stocks SET quantity = quantity - 1 WHERE product_id = 1 AND warehouse_id = 1');

        $maxLedger = $this->scalar('SELECT MAX(id) FROM stock_ledger');
        foreach ([1, 2] as $run) {
            [$code, $output] = $this->runMigration();
            self::assertSame(0, $code, 'Run ' . $run . ': ' . $output);
            $result = json_decode($output, true);
            self::assertSame('ready', $result['status']);
            self::assertSame(0, array_sum($result['checks']));
            self::assertSame($run === 1 ? $pairs : 0, $result['legacy_lots_created']);
        }
        self::assertSame($pairs, $this->scalar("SELECT COUNT(*) FROM stock_lots WHERE lot_code = 'LEGACY' AND expiry_date IS NULL AND opening_quantity = quantity AND source_type = 'LEGACY'"));
        self::assertSame($maxLedger, $this->scalar('SELECT legacy_ledger_max_id FROM stock_lot_watermark'));
        self::assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM products WHERE tracks_expiry = 1'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM products p JOIN categories c ON c.id = p.category_id WHERE c.name = 'Perawatan Rumah Tangga' AND p.tracks_expiry = 1"));
        self::assertSame($before, $this->stockChecksum(), 'Stock and ledger are unchanged.');
        // An Admin choice survives a re-run.
        $this->pdo->exec("UPDATE products SET tracks_expiry = 0 WHERE sku = (SELECT sku FROM (SELECT p.sku FROM products p WHERE p.tracks_expiry = 1 ORDER BY p.id LIMIT 1) x)");
        $untracked = $this->scalar('SELECT COUNT(*) FROM products WHERE tracks_expiry = 0');
        $this->runMigration();
        self::assertSame($untracked, $this->scalar('SELECT COUNT(*) FROM products WHERE tracks_expiry = 0'));

        // The later stages' schema follows in order (stock counts and sales documents as additive DDL: their data checks
        // would see the STOCK_COUNT ledger rows this synthetic downgrade kept without their count documents), then the
        // valuation runner of D-20 / ADR-026, which gives every ledger row with a lot its cost.
        foreach (['20261012-stock-counts.sql', '20261012-sales-documents.sql'] as $file) {
            $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $file));
        }
        [$code, $output] = $this->runMigration(...['--script=migrate-inventory-valuation.php']);
        self::assertSame(0, $code, $output);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM stock_ledger WHERE lot_id IS NOT NULL'), 'Only pre-lot rows exist so far: nothing gets a cost.');
        // After the migration, migrated stock ships FEFO from the LEGACY lot, a legacy receipt returns to it, and every new
        // ledger row has a lot.
        $pair = $this->pdo->query('SELECT product_id, warehouse_id FROM product_stocks WHERE quantity - reserved_quantity >= 2 AND warehouse_id = 1 ORDER BY product_id LIMIT 1')->fetch(PDO::FETCH_NUM);
        $this->stock->issue((int) $pair[1], [new \App\Service\StockMovement((int) $pair[0], 1)], 1, 'TEST', 99);
        self::assertSame('LEGACY', (string) $this->value('SELECT l.lot_code FROM stock_ledger s JOIN stock_lots l ON l.id = s.lot_id ORDER BY s.id DESC LIMIT 1'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM stock_ledger s JOIN stock_lots l ON l.id = s.lot_id WHERE s.unit_cost = l.unit_cost AND s.cost_amount = -l.unit_cost'), 'The new row is valued at the LEGACY lot cost.');
        $this->assertLotInvariants();
        [$code, $output] = $this->runMigration('--dry-run');
        self::assertTrue(json_decode($output, true)['already_applied']);
    }

    private function downgradeToPreLotSchema(): void
    {
        // Write-off documents (several lines per product) cannot exist before D-17; their ledger rows stay (stock = ledger).
        $this->pdo->exec("DELETE i FROM inventory_operation_items i JOIN inventory_operations o ON o.id = i.operation_id WHERE o.purpose = 'ExpiredWriteOff'");
        $this->pdo->exec("DELETE FROM inventory_operations WHERE purpose = 'ExpiredWriteOff'");
        // Stock count sessions (D-18, ADR-024) cannot exist before D-17 either; their STOCK_COUNT ledger rows stay (stock = ledger).
        foreach (['stock_count_lines', 'stock_count_counters', 'stock_count_products', 'stock_counts'] as $table) {
            $this->pdo->exec('DROP TABLE ' . $table);
        }
        // Delivery notes and invoices (D-19, ADR-025) reference lots and ledger rows; they cannot exist before D-17.
        foreach (['invoice_payments', 'invoice_lines', 'invoices', 'delivery_note_lots', 'delivery_note_lines', 'delivery_notes'] as $table) {
            $this->pdo->exec('DROP TABLE ' . $table);
        }
        $this->pdo->exec('DROP TABLE inventory_operation_lots');
        // The valuation columns (D-20, ADR-026) came after the lots: a pre-lot database has no ledger cost either.
        $this->pdo->exec('ALTER TABLE stock_ledger DROP CHECK chk_stock_ledger_cost');
        $this->pdo->exec('ALTER TABLE stock_ledger DROP INDEX idx_stock_ledger_created, DROP COLUMN cost_amount, DROP COLUMN unit_cost');
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'inventory-valuation-1'");
        $this->pdo->exec('ALTER TABLE stock_ledger DROP FOREIGN KEY fk_stock_ledger_lot');
        $this->pdo->exec('ALTER TABLE stock_ledger DROP INDEX idx_stock_ledger_lot, DROP COLUMN lot_id');
        $this->pdo->exec('ALTER TABLE inventory_operation_items DROP FOREIGN KEY fk_operation_items_lot');
        $this->pdo->exec('ALTER TABLE inventory_operation_items ADD UNIQUE KEY uq_operation_product (operation_id, product_id)');
        $this->pdo->exec('ALTER TABLE inventory_operation_items DROP CHECK chk_operation_items_lot, DROP CHECK chk_operation_items_lot_expiry');
        $this->pdo->exec('ALTER TABLE inventory_operation_items DROP INDEX uq_operation_product_lot, DROP COLUMN lot_key, DROP COLUMN lot_id, DROP COLUMN lot_code, DROP COLUMN expiry_date');
        $this->pdo->exec('ALTER TABLE inventory_operations DROP CHECK chk_operation_purpose');
        $this->pdo->exec('ALTER TABLE inventory_operations DROP COLUMN purpose');
        $this->pdo->exec('DROP TABLE stock_lots');
        $this->pdo->exec('DROP TABLE stock_lot_watermark');
        $this->pdo->exec('ALTER TABLE product_stocks DROP COLUMN lot_count');
        $this->pdo->exec('ALTER TABLE products DROP COLUMN tracks_expiry');
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'batch-expiry-1'");
    }

    /** @return array{0: int, 1: string} */
    private function runMigration(string ...$arguments): array
    {
        $script = 'migrate-batch-expiry.php';
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, '--script=')) {
                $script = substr($argument, 9);
                unset($arguments[$index]);
            }
        }
        $process = proc_open([PHP_BINARY, 'scripts/' . $script, ...array_values($arguments)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $error];
    }

    private function stockChecksum(): string
    {
        return (string) $this->value("SELECT CONCAT((SELECT COALESCE(SUM(quantity), 0) FROM product_stocks), '/', (SELECT COALESCE(SUM(reserved_quantity), 0) FROM product_stocks), '/', (SELECT COUNT(*) FROM stock_ledger), '/', (SELECT COALESCE(SUM(quantity), 0) FROM stock_ledger))");
    }

    private function assertLotInvariants(): void
    {
        self::assertSame(array_fill_keys(['lot_aggregate_mismatches', 'lot_ledger_mismatches', 'lot_count_mismatches', 'ledger_rows_without_lot', 'issue_from_expired_lot', 'fefo_violations', 'transfer_lot_mismatches'], 0), (new BatchLotChecks($this->pdo))->report());
    }

    private function assertOldInvariants(): void
    {
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type = 'Receipt' THEN quantity WHEN movement_type = 'Issue' THEN -quantity ELSE COALESCE(quantity_delta, 0) END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)"));
        self::assertSame(0, (new StockReservationReconciler($this->pdo))->mismatches());
        self::assertSame(0, array_sum((new PartialFulfilmentChecks($this->pdo))->report()));
        self::assertSame(0, array_sum((new TransferTransitChecks($this->pdo))->report()));
    }

    private function product(string $sku, bool $tracked): int
    {
        $products = new MySqlProductRepository($this->pdo);
        $product = (new ProductService($products, new MasterDataAuthorizationService(), $this->stock))->create($this->admin, new ProductInput($sku, 'Lot product ' . $sku, 'pcs', 10.0, 20.0, 5, 1, $tracked));

        return $product->id();
    }

    /** @return array{0: PurchaseOrderService, 1: MySqlPurchaseOrderRepository} */
    private function purchasing(): array
    {
        $orders = new MySqlPurchaseOrderRepository($this->pdo);

        return [new PurchaseOrderService($orders, new MySqlDocumentSequenceRepository($this->pdo), new MySqlProductRepository($this->pdo), [1 => new Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], $this->stock, new \App\Service\OperationIdempotency(new \App\Repository\MySql\MySqlOperationRequestRepository($this->pdo)), new MySqlOrderExceptionRepository($this->pdo)), $orders];
    }

    /** @return array{0: SalesOrderService, 1: MySqlSalesOrderRepository} */
    private function selling(): array
    {
        $orders = new MySqlSalesOrderRepository($this->pdo);

        return [new SalesOrderService($orders, new MySqlDocumentSequenceRepository($this->pdo), new MySqlProductRepository($this->pdo), [1 => new Customer(1, 'Customer', '', '', '', true), 2 => new Customer(2, 'Customer two', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], $this->stock, null, new OrderLinePricing(), new MySqlOrderExceptionRepository($this->pdo)), $orders];
    }

    /** @return list<array{0: string, 1: int}> */
    private function lots(int $product, int $warehouse): array
    {
        return array_map(static fn (array $row): array => [(string) $row[0], (int) $row[1]], $this->pdo->query("SELECT lot_code, quantity FROM stock_lots WHERE product_id = $product AND warehouse_id = $warehouse ORDER BY expiry_key, received_at, id")->fetchAll(PDO::FETCH_NUM));
    }

    private function date(string $modify): string
    {
        return (new \DateTimeImmutable(BusinessDate::today()))->modify($modify)->format('Y-m-d');
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
