<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\{Customer, Supplier, User, Warehouse};
use App\Exception\ValidationException;
use App\Repository\MySql\{MySqlAuditLogRepository, MySqlDocumentSequenceRepository, MySqlDocumentTimelineRepository, MySqlProductRepository, MySqlPurchaseOrderRepository, MySqlSalesOrderRepository, MySqlStockCatalogRepository, MySqlStockLedgerRepository, MySqlStockRepository, MySqlTransactionManager};
use App\Security\AuthContext;
use App\Service\{DocumentTimelineService, MasterDataAuthorizationService, OrderLinePricing, ProductService, PurchaseOrderService, SalesOrderService, StockService};
use App\Support\{OrderSearchCriteria, ProductInput};
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** D-13 / ADR-019: negotiated PO/SO line prices against real MySQL (persistence, CHECKs, seed, migration). */
final class NegotiatedPriceIntegrationTest extends TestCase
{
    private PDO $pdo;
    private StockService $stock;
    private MySqlProductRepository $products;
    private int $product;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->stock = new StockService(new MySqlStockRepository($this->pdo), new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));
        $this->products = new MySqlProductRepository($this->pdo);
        // Purchase 1000, selling 1500: Sales may go down to 1350 (10%), Admin down to 1000 (cost).
        // ProductService initializes zero stock pairs in every warehouse (ADR-006), so the PO can be received.
        $this->product = (new ProductService($this->products, new MasterDataAuthorizationService(), $this->stock))
            ->create(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), new ProductInput('NEGO-SKU', 'Negotiated price fixture', 'pcs', 1000.0, 1500.0, 1, 1))->id();
    }

    public function testSeedOverridesAllHaveReasonsAndRespectTheRules(): void
    {
        foreach (['purchase_order_items' => 'purchase_price', 'sales_order_items' => 'selling_price'] as $table => $column) {
            self::assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM {$table} WHERE {$column} <> list_price"), $table . ' has demo overrides');
            self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM {$table} WHERE ({$column} <> list_price AND (price_reason IS NULL OR CHAR_LENGTH(TRIM(price_reason)) < 3)) OR ({$column} = list_price AND price_reason IS NOT NULL)"), $table);
        }
        self::assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM sales_order_items WHERE selling_price > list_price'), 'one above-master SO line');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM sales_order_items i JOIN products p ON p.id = i.product_id WHERE i.selling_price < p.purchase_price'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM sales_order_items i JOIN sales_orders so ON so.id = i.sales_order_id JOIN users u ON u.id = so.created_by WHERE u.role = 'Sales' AND i.selling_price * 100 < i.list_price * 90"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'negotiated-price-1'"));
    }

    public function testOverridesPersistAndReadBackThroughEveryRepositoryPath(): void
    {
        $sales = new AuthContext(2, 'sales1@example.test', User::ROLE_SALES);
        $repository = new MySqlSalesOrderRepository($this->pdo);
        $order = $this->sales($repository)->createDraft($sales, 1, 1, [
            ['product_id' => $this->product, 'quantity' => 3, 'price' => '1400.50', 'price_reason' => '  Promo akhir bulan  ', 'list_price' => 1.0],
        ]);
        $row = $this->pdo->query('SELECT selling_price, list_price, price_reason FROM sales_order_items WHERE sales_order_id = ' . $order->id())->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['selling_price' => '1400.50', 'list_price' => '1500.00', 'price_reason' => 'Promo akhir bulan'], $row, 'server snapshot, trimmed reason, client list_price ignored');

        foreach ([$repository->findById($order->id()), $this->find($repository->all(), $order->id()), $this->find($repository->search(OrderSearchCriteria::fromArray(['q' => $order->orderNumber()]))->items(), $order->id())] as $loaded) {
            $item = $loaded->items()[0];
            self::assertSame([1400.5, 1500.0, 'Promo akhir bulan', true], [$item->sellingPrice(), $item->listPrice(), $item->priceReason(), $item->isPriceOverridden()]);
        }

        $purchaseRepository = new MySqlPurchaseOrderRepository($this->pdo);
        $warehouse = new AuthContext(4, 'warehouse1@example.test', User::ROLE_WAREHOUSE_STAFF);
        $po = $this->purchase($purchaseRepository)->createDraft($warehouse, 1, 1, [['product_id' => $this->product, 'quantity' => 10, 'price' => '950', 'price_reason' => 'Diskon principal']]);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $this->purchase($purchaseRepository)->markOrdered($admin, $po->id());
        $this->purchase($purchaseRepository)->receive($admin, $po->id(), [$po->items()[0]->id() => 4]);
        $received = $purchaseRepository->findById($po->id())?->items()[0];
        self::assertNotNull($received);
        self::assertSame([950.0, 1000.0, 'Diskon principal', 4], [$received->purchasePrice(), $received->listPrice(), $received->priceReason(), $received->receivedQuantity()], 'receipt keeps the negotiated price');
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM stock_ledger WHERE reference_type = 'PO' AND reference_id = ? AND quantity = 4", [$po->id()]), 'stock still moves only through the ledger');

        $equal = $this->sales($repository)->createDraft($admin, 1, 1, [['product_id' => $this->product, 'quantity' => 1, 'price' => '1500', 'price_reason' => 'not needed']]);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM sales_order_items WHERE sales_order_id = ? AND selling_price = list_price AND price_reason IS NULL', [$equal->id()]));
    }

    public function testServiceRulesAreEnforcedBeforeAnythingIsWritten(): void
    {
        $before = $this->scalar('SELECT COUNT(*) FROM sales_orders');
        $sequence = $this->scalar("SELECT COALESCE(SUM(last_number), 0) FROM document_sequences WHERE prefix = 'SO'");
        $sales = new AuthContext(2, 'sales1@example.test', User::ROLE_SALES);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        foreach ([[$sales, '1349.99', 'Promo'], [$admin, '999.99', 'Cuci gudang'], [$sales, '1400', ''], [$sales, '-1', 'Promo']] as [$actor, $price, $reason]) {
            try {
                $this->sales(new MySqlSalesOrderRepository($this->pdo))->createDraft($actor, 1, 1, [['product_id' => $this->product, 'quantity' => 1, 'price' => $price, 'price_reason' => $reason]]);
                self::fail('Expected 422 for ' . $price);
            } catch (ValidationException $exception) {
                self::assertSame(422, $exception->statusCode());
            }
        }
        self::assertSame($before, $this->scalar('SELECT COUNT(*) FROM sales_orders'));
        self::assertSame($sequence, $this->scalar("SELECT COALESCE(SUM(last_number), 0) FROM document_sequences WHERE prefix = 'SO'"), 'no number claimed');
        self::assertSame(1100.0, $this->sales(new MySqlSalesOrderRepository($this->pdo))->createDraft($admin, 1, 1, [['product_id' => $this->product, 'quantity' => 1, 'price' => '1100', 'price_reason' => 'Persetujuan direksi']])->items()[0]->sellingPrice(), 'Admin has no discount cap');
    }

    public function testDatabaseChecksGuardTheReasonInvariant(): void
    {
        $order = (int) $this->pdo->query('SELECT MIN(id) FROM sales_orders')->fetchColumn();
        foreach ([
            [1400.0, 1500.0, null],       // override without reason
            [1400.0, 1500.0, '  '],       // blank reason
            [1500.0, 1500.0, 'Promo'],    // reason without override
            [1500.0, -1.0, null],         // negative list price
        ] as [$price, $list, $reason]) {
            try {
                $statement = $this->pdo->prepare('INSERT INTO sales_order_items (sales_order_id, product_id, quantity, selling_price, list_price, price_reason, unit_code, unit_factor, unit_quantity) VALUES (?, ?, 1, ?, ?, ?, \'pcs\', 1, 1)');
                $statement->execute([$order, $this->product, $price, $list, $reason]);
                self::fail('CHECK must reject ' . json_encode([$price, $list, $reason]));
            } catch (\PDOException $exception) {
                self::assertStringContainsString('3819', $exception->getMessage(), 'MySQL check constraint violation');
            }
        }
        $this->expectException(\PDOException::class);
        $this->pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, purchase_price, unit_code, unit_factor, unit_quantity) VALUES ((SELECT MIN(id) FROM purchase_orders), ?, 1, 10, \'pcs\', 1, 1)')->execute([$this->product]);
    }

    public function testTimelineCreationEventNamesOverriddenLines(): void
    {
        $order = $this->sales(new MySqlSalesOrderRepository($this->pdo))->createDraft(new AuthContext(2, 'sales1@example.test', User::ROLE_SALES), 1, 1, [
            ['product_id' => $this->product, 'quantity' => 1, 'price' => '1450', 'price_reason' => 'Volume order > 50 karton'],
        ]);
        $events = (new DocumentTimelineService(new MySqlDocumentTimelineRepository($this->pdo)))->forDocument(new AuthContext(2, 'sales1@example.test', User::ROLE_SALES), 'SO', $order->id())['events'];
        $created = array_values(array_filter($events, static fn (array $event): bool => $event['title'] === 'Document created'))[0];
        self::assertSame('1 line priced away from master (reasons on the order lines)', $created['detail']);

        $plain = (int) $this->pdo->query('SELECT MIN(po.id) FROM purchase_orders po WHERE NOT EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.purchase_order_id = po.id AND i.purchase_price <> i.list_price)')->fetchColumn();
        $events = (new DocumentTimelineService(new MySqlDocumentTimelineRepository($this->pdo)))->forDocument(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 'PO', $plain)['events'];
        $created = array_values(array_filter($events, static fn (array $event): bool => $event['title'] === 'Document created'))[0];
        self::assertSame('', $created['detail'], 'master-priced documents keep an empty creation detail');
    }

    public function testMigrationBackfillsFromLinePriceAndIsIdempotent(): void
    {
        // Simulate a database from before D-13: no columns or CHECKs, no schema version; sales_order_items as if a first
        // run stopped after adding the nullable column.
        $this->pdo->exec('ALTER TABLE purchase_order_items DROP CHECK chk_purchase_order_items_price_reason, DROP CHECK chk_purchase_order_items_list_price_non_negative');
        $this->pdo->exec('ALTER TABLE purchase_order_items DROP COLUMN price_reason, DROP COLUMN list_price');
        $this->pdo->exec('ALTER TABLE sales_order_items DROP CHECK chk_sales_order_items_price_reason, DROP CHECK chk_sales_order_items_list_price_non_negative');
        $this->pdo->exec('ALTER TABLE sales_order_items DROP COLUMN price_reason, DROP COLUMN list_price');
        $this->pdo->exec('ALTER TABLE sales_order_items ADD COLUMN list_price DECIMAL(14,2) NULL AFTER selling_price');
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'negotiated-price-1'");
        $updatedAt = $this->pdo->query('SELECT MD5(GROUP_CONCAT(id, updated_at ORDER BY id)) FROM sales_order_items')->fetchColumn();
        $lines = $this->scalar('SELECT COUNT(*) FROM purchase_order_items') + $this->scalar('SELECT COUNT(*) FROM sales_order_items');

        $first = $this->runMigration(1);
        $second = $this->runMigration(2);

        self::assertSame('ready', $first['status']);
        self::assertFalse($first['destructive']);
        self::assertSame($first, $second, 'a re-run changes nothing');
        self::assertSame(0, $first['purchase_order_items']['overridden'] + $first['sales_order_items']['overridden'], 'old lines used the master price');
        self::assertSame($lines, $first['purchase_order_items']['lines'] + $first['sales_order_items']['lines']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM purchase_order_items WHERE list_price <> purchase_price OR price_reason IS NOT NULL'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM sales_order_items WHERE list_price <> selling_price OR price_reason IS NOT NULL'));
        self::assertSame($updatedAt, $this->pdo->query('SELECT MD5(GROUP_CONCAT(id, updated_at ORDER BY id)) FROM sales_order_items')->fetchColumn(), 'backfill keeps updated_at');
        self::assertSame(4, $this->scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_name IN ('chk_purchase_order_items_price_reason', 'chk_purchase_order_items_list_price_non_negative', 'chk_sales_order_items_price_reason', 'chk_sales_order_items_list_price_non_negative')"));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'list_price' AND is_nullable = 'NO' AND column_type = 'decimal(14,2)' AND table_name IN ('purchase_order_items', 'sales_order_items')"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'negotiated-price-1'"));

        // After migrating, new drafts store overrides as usual.
        $order = $this->sales(new MySqlSalesOrderRepository($this->pdo))->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 1, 1, [['product_id' => $this->product, 'quantity' => 1, 'price' => '1600', 'price_reason' => 'Kemasan khusus']]);
        self::assertSame(1500.0, $order->items()[0]->listPrice());
    }

    private function sales(MySqlSalesOrderRepository $orders): SalesOrderService
    {
        return new SalesOrderService($orders, new MySqlDocumentSequenceRepository($this->pdo), $this->products, [1 => new Customer(1, 'Customer', '', '', '', true)], [1 => new Warehouse(1, 'Warehouse', '', true)], $this->stock, null, new OrderLinePricing(10));
    }

    private function purchase(MySqlPurchaseOrderRepository $orders): PurchaseOrderService
    {
        return new PurchaseOrderService($orders, new MySqlDocumentSequenceRepository($this->pdo), $this->products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Warehouse', '', true)], $this->stock);
    }

    /**
     * @template T of object
     * @param iterable<T> $orders
     * @return T
     */
    private function find(iterable $orders, int $id): object
    {
        foreach ($orders as $order) {
            if ($order->id() === $id) {
                return $order;
            }
        }
        self::fail('Order ' . $id . ' not found.');
    }

    /** @return array<string, mixed> */
    private function runMigration(int $run): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/migrate-negotiated-prices.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), 'Run ' . $run . ': ' . $error);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<int|string> $params */
    private function scalar(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
