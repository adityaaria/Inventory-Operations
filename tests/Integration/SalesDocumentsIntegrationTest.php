<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\PersistenceException;
use App\Exception\ValidationException;
use App\Repository\Contract\DeliveryNoteRepositoryInterface;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlCustomerRepository;
use App\Repository\MySql\MySqlDeliveryNoteRepository;
use App\Repository\MySql\MySqlDocumentSequenceRepository;
use App\Repository\MySql\MySqlInvoiceRepository;
use App\Repository\MySql\MySqlOrderExceptionRepository;
use App\Repository\MySql\MySqlPriceListRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlProductUnitRepository;
use App\Repository\MySql\MySqlQuotationRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Repository\MySql\MySqlWarehouseRepository;
use App\Repository\MySql\PartialFulfilmentChecks;
use App\Repository\MySql\SalesDocumentChecks;
use App\Security\AuthContext;
use App\Service\DeliveryNoteService;
use App\Service\InvoiceService;
use App\Service\OrderLinePricing;
use App\Service\PriceListService;
use App\Service\PriceResolver;
use App\Service\ProductUnitCatalog;
use App\Service\QuotationService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\BusinessDate;
use App\Support\PaginatedResult;
use App\Support\TaxSettings;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** D-19 / ADR-025 on MySQL: seed invariants, price lists, quotations, delivery notes, invoices, DB guards, reports, migration. */
final class SalesDocumentsIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MySqlProductRepository $products;
    private ProductUnitCatalog $units;
    private MySqlSalesOrderRepository $orders;
    private MySqlStockRepository $balances;
    private MySqlCustomerRepository $customers;
    private MySqlDocumentSequenceRepository $sequences;
    private PriceResolver $resolver;
    private AuthContext $admin;
    private AuthContext $sales;
    private AuthContext $warehouse;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->products = new MySqlProductRepository($this->pdo);
        $this->units = new ProductUnitCatalog($this->products, new MySqlProductUnitRepository($this->pdo));
        $this->orders = new MySqlSalesOrderRepository($this->pdo);
        $this->balances = new MySqlStockRepository($this->pdo);
        $this->customers = new MySqlCustomerRepository($this->pdo);
        $this->sequences = new MySqlDocumentSequenceRepository($this->pdo);
        $this->resolver = new PriceResolver(new MySqlPriceListRepository($this->pdo), $this->products, $this->units);
        // Seed users: 1 Admin Demo, 2 Sales Demo One, 4 Warehouse Demo One.
        $this->admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $this->sales = new AuthContext(2, 'sales1@example.test', User::ROLE_SALES);
        $this->warehouse = new AuthContext(4, 'warehouse1@example.test', User::ROLE_WAREHOUSE_STAFF);
    }

    public function testSeedSatisfiesEveryInvariantAndCoversEveryState(): void
    {
        self::assertSame(array_fill_keys(array_keys((new SalesDocumentChecks($this->pdo))->report()), 0), (new SalesDocumentChecks($this->pdo))->report());
        $stats = (new SalesDocumentChecks($this->pdo))->statistics();
        self::assertGreaterThanOrEqual(3, $stats['price_lists']);
        self::assertGreaterThan(1000, $stats['so_lines_from_price_list']);
        foreach (['Draft', 'Sent', 'Accepted', 'Rejected', 'Cancelled', 'Expired'] as $status) {
            self::assertArrayHasKey($status, $stats['quotations_by_status'], $status);
        }
        self::assertGreaterThan(10, $stats['quotations_converted']);
        self::assertSame($this->scalar("SELECT COUNT(DISTINCT x.delivery_note_id) FROM delivery_note_lots x"), $stats['delivery_notes'], 'every note has lots');
        foreach (['Paid', 'PartiallyPaid', 'Unpaid', 'Void'] as $status) {
            self::assertArrayHasKey($status, $stats['invoices_by_status'], $status);
        }
        self::assertGreaterThan(0, $stats['invoices_overdue']);
        self::assertGreaterThan(0, $stats['delivery_notes_not_invoiced']);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM delivery_notes WHERE delivery_number NOT REGEXP '^DO-[0-9]{4}-[0-9]{4,}$'"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM invoices WHERE invoice_number NOT REGEXP '^INV-[0-9]{4}-[0-9]{4,}$'"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM quotations WHERE quotation_number NOT REGEXP '^QT-[0-9]{4}-[0-9]{4,}$'"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM invoices WHERE vat_rate_percent <> 12 OR vat_dpp_numerator <> 11 OR vat_dpp_denominator <> 12"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'sales-documents-1'"));
        self::assertSame(['issued_ledger_mismatches' => 0, 'fulfilment_status_mismatches' => 0, 'closure_mismatches' => 0], (new PartialFulfilmentChecks($this->pdo))->report());
    }

    public function testPriceListLinesPersistAndTheApiAnswersFromMySql(): void
    {
        [$customer, $product, $karton] = $this->grosirFixture();
        $tiers = $this->pdo->query("SELECT min_quantity, unit_price FROM price_list_items WHERE price_list_id = 1 AND product_id = {$product} AND unit_code = '{$karton}' ORDER BY min_quantity")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([1, 10, 50], array_map('intval', array_keys($tiers)));
        $service = $this->salesOrderService();
        $order = $service->createDraft($this->sales, $customer, 1, [['product_id' => $product, 'quantity' => 12, 'unit_code' => $karton]]);
        $row = $this->pdo->query('SELECT selling_price, list_price, price_reason, price_source, price_list_id FROM sales_order_items WHERE sales_order_id = ' . $order->id())->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['selling_price' => $tiers[10], 'list_price' => $tiers[10], 'price_reason' => null, 'price_source' => 'PRICE_LIST', 'price_list_id' => 1], $row);
        $answer = $this->resolver->lookup($this->sales, ['customer_id' => (string) $customer, 'product_id' => (string) $product, 'unit_code' => $karton, 'quantity' => '60']);
        self::assertSame([$tiers[50], 'Price list Grosir, from 50 ' . $karton], [$answer['price'], $answer['label']]);
        // Deactivating the list returns the customer to master prices for new lines; the stored line keeps its price.
        (new PriceListService(new MySqlPriceListRepository($this->pdo), $this->customers, $this->products, $this->units, new MySqlTransactionManager($this->pdo), new MySqlAuditLogRepository($this->pdo)))
            ->update($this->admin, 1, ['name' => 'Grosir', 'valid_from' => '', 'valid_to' => '']);
        self::assertSame('MASTER', $this->resolver->lookup($this->sales, ['customer_id' => (string) $customer, 'product_id' => (string) $product, 'unit_code' => $karton, 'quantity' => '60'])['source']);
        self::assertSame($tiers[10], (string) $this->pdo->query('SELECT list_price FROM sales_order_items WHERE sales_order_id = ' . $order->id())->fetchColumn());
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'price-lists.update' AND entity_id = 1"));
    }

    public function testShipmentCreatesItsLinkedDeliveryNoteAndRollsBackWithIt(): void
    {
        [$customer, $product, $karton] = $this->grosirFixture();
        $service = $this->salesOrderService();
        $order = $service->createDraft($this->sales, $customer, 1, [['product_id' => $product, 'quantity' => 2, 'unit_code' => $karton]]);
        $service->submit($this->sales, $order->id());
        $service->approve($this->admin, $order->id());
        $before = [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1), $this->scalar("SELECT COALESCE(MAX(last_number), 0) FROM document_sequences WHERE prefix = 'DO'"),
            $this->scalar('SELECT COUNT(*) FROM stock_ledger'), $this->scalar('SELECT COUNT(*) FROM audit_logs')];

        // A delivery note failure after the stock, lots and ledger rows were written rolls everything back.
        $failing = $this->salesOrderService(new class (new MySqlDeliveryNoteRepository($this->pdo)) implements DeliveryNoteRepositoryInterface {
            public function __construct(private readonly MySqlDeliveryNoteRepository $inner) {}
            public function nextShipmentNumber(int $salesOrderId): int { return $this->inner->nextShipmentNumber($salesOrderId); }
            public function create(array $note, array $lines): int { $this->inner->create($note, $lines); throw new PersistenceException('Printer queue offline.'); }
            public function find(int $id): ?array { return $this->inner->find($id); }
            public function lock(int $id): ?array { return $this->inner->lock($id); }
            public function forSalesOrder(int $salesOrderId): array { return $this->inner->forSalesOrder($salesOrderId); }
            public function search(array $filters, int $page, int $perPage, ?int $salesCreatedBy = null): PaginatedResult { return $this->inner->search($filters, $page, $perPage, $salesCreatedBy); }
        });
        try {
            $failing->issue($this->warehouse, $order->id(), null, null, ['vehicle' => 'B 1 XX', 'driver' => 'Test']);
            self::fail('The shipment must fail with its delivery note.');
        } catch (PersistenceException) {
        }
        self::assertSame($before, [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1), $this->scalar("SELECT COALESCE(MAX(last_number), 0) FROM document_sequences WHERE prefix = 'DO'"),
            $this->scalar('SELECT COUNT(*) FROM stock_ledger'), $this->scalar('SELECT COUNT(*) FROM audit_logs')]);
        self::assertSame([0, 'Approved'], [$this->scalar('SELECT COUNT(*) FROM delivery_notes WHERE sales_order_id = ' . $order->id()), $this->orders->findById($order->id())?->status()]);

        $service->issue($this->warehouse, $order->id(), null, [$order->items()[0]->id() => 1], ['vehicle' => 'B 9123 KXT', 'driver' => 'Slamet']);
        $service->issue($this->warehouse, $order->id(), null, null);
        $notes = (new MySqlDeliveryNoteRepository($this->pdo))->forSalesOrder($order->id());
        self::assertSame([[1, 'B 9123 KXT', 'Slamet', 'Issue'], [2, null, null, 'Issue']], array_map(static fn (array $note): array => [$note['shipment_number'], $note['vehicle'], $note['driver'], $note['origin']], $notes));
        $note = (new MySqlDeliveryNoteRepository($this->pdo))->find($notes[0]['id']);
        self::assertNotNull($note);
        self::assertSame([$karton, 1, true], [$note['lines'][0]['unit_code'], $note['lines'][0]['unit_quantity'], $note['lines'][0]['quantity'] === $note['lines'][0]['unit_factor']]);
        $ledgerIds = array_map('intval', $this->pdo->query("SELECT id FROM stock_ledger WHERE movement_type = 'Issue' AND reference_type = 'SO' AND reference_id = " . $order->id() . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $linked = array_map('intval', $this->pdo->query('SELECT ledger_id FROM delivery_note_lots WHERE delivery_note_id IN (' . $notes[0]['id'] . ',' . $notes[1]['id'] . ') ORDER BY ledger_id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($ledgerIds, $linked, 'every Issue row of the order is on exactly one note');
        self::assertSame(array_fill_keys(array_keys((new SalesDocumentChecks($this->pdo))->report()), 0), (new SalesDocumentChecks($this->pdo))->report());
        $this->expectException(\App\Exception\HttpException::class);
        (new DeliveryNoteService(new MySqlDeliveryNoteRepository($this->pdo), $this->sequences, $this->customers))->show(new AuthContext(3, 'sales2@example.test', User::ROLE_SALES), $notes[0]['id']);
    }

    public function testQuotationConversionAndInvoiceLifecyclePersist(): void
    {
        [$customer, $product, $karton] = $this->grosirFixture();
        $quotations = $this->quotationService();
        $id = $quotations->create($this->sales, $customer, 1, null, 'Integration', [['product_id' => $product, 'quantity' => 3, 'unit_code' => $karton]]);
        $quotations->send($this->sales, $id);
        $quotations->accept($this->sales, $id);
        $orderId = $quotations->convert($this->sales, $id);
        self::assertSame([$id, 2], [(int) $this->scalar('SELECT quotation_id FROM sales_orders WHERE id = ' . $orderId), (int) $this->scalar('SELECT created_by FROM sales_orders WHERE id = ' . $orderId)]);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM sales_order_items i JOIN quotation_items q ON q.id = i.quotation_item_id WHERE i.sales_order_id = ' . $orderId . ' AND q.quotation_id = ' . $id . ' AND i.selling_price = q.unit_price'));
        try {
            $quotations->convert($this->admin, $id);
            self::fail('A quotation converts once.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('already converted', $exception->getMessage());
        }

        $service = $this->salesOrderService();
        $service->submit($this->sales, $orderId);
        $service->approve($this->admin, $orderId);
        $service->issue($this->warehouse, $orderId);
        $noteId = (int) $this->scalar('SELECT id FROM delivery_notes WHERE sales_order_id = ' . $orderId);
        $invoices = $this->invoiceService();
        $invoiceId = $invoices->createFromDeliveryNote($this->admin, $noteId);
        $invoice = $invoices->show($this->admin, $invoiceId);
        $price = TaxSettings::cents((string) $this->scalarText('SELECT selling_price FROM sales_order_items WHERE sales_order_id = ' . $orderId));
        $totals = TaxSettings::compute(3 * $price, 12, 11, 12);
        self::assertSame([TaxSettings::decimal(3 * $price), TaxSettings::decimal($totals['dpp_cents']), TaxSettings::decimal($totals['vat_cents']), TaxSettings::decimal($totals['total_cents'])],
            [$invoice['subtotal'], $invoice['dpp_amount'], $invoice['vat_amount'], $invoice['total_amount']]);
        self::assertSame($this->scalarText('SELECT npwp FROM customers WHERE id = ' . $customer), $invoice['customer_npwp'], 'tax identity snapshot');

        $today = $invoices->today();
        $half = intdiv($totals['total_cents'], 2);
        $invoices->recordPayment($this->admin, $invoiceId, ['paid_on' => $today, 'amount' => TaxSettings::decimal($half), 'method' => 'Giro', 'reference' => 'GR-1']);
        self::assertSame('PartiallyPaid', $invoices->show($this->admin, $invoiceId)['state']['status']);
        try {
            $invoices->recordPayment($this->admin, $invoiceId, ['paid_on' => $today, 'amount' => TaxSettings::decimal($totals['total_cents'] - $half + 1), 'method' => 'Transfer']);
            self::fail('Overpayment must be refused.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Overpayment refused', $exception->getMessage());
        }
        $invoices->recordPayment($this->admin, $invoiceId, ['paid_on' => $today, 'amount' => TaxSettings::decimal($totals['total_cents'] - $half), 'method' => 'Transfer']);
        self::assertSame(['Paid', $invoice['total_amount']], [$invoices->show($this->admin, $invoiceId)['state']['status'], $invoices->show($this->admin, $invoiceId)['paid_amount']]);
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'invoices.payment' AND entity_id = " . $invoiceId));

        // Reports read the same rows: the paid invoice is not open, the sales report counts it.
        $aging = $invoices->agingReport($this->admin);
        self::assertSame((int) round((float) $this->scalarText('SELECT SUM(total_amount - paid_amount) FROM invoices WHERE voided_at IS NULL') * 100), $aging['totals']['outstanding']);
        $sales = $invoices->salesByCustomer($this->admin, ['from' => $today, 'to' => $today]);
        self::assertContains($customer, array_column($sales['rows'], 'customer_id'));
        self::assertStringStartsWith('customer,invoices,net,ppn,total,paid,from,to', $invoices->salesByCustomerCsv($this->admin, ['from' => '2026-01-01', 'to' => $today]));
        self::assertSame(array_fill_keys(array_keys((new SalesDocumentChecks($this->pdo))->report()), 0), (new SalesDocumentChecks($this->pdo))->report());
    }

    public function testDatabaseGuards(): void
    {
        $invoice = $this->pdo->query('SELECT id, total_amount FROM invoices WHERE voided_at IS NULL AND paid_amount = 0 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $noteWithInvoice = (int) $this->scalar('SELECT delivery_note_id FROM invoices WHERE id = ' . (int) $invoice['id']);
        $guards = [
            'PPN must recompute from the snapshot' => 'UPDATE invoices SET vat_amount = vat_amount + 1, total_amount = total_amount + 1 WHERE id = ' . (int) $invoice['id'],
            'total = subtotal + PPN' => 'UPDATE invoices SET total_amount = total_amount + 0.01 WHERE id = ' . (int) $invoice['id'],
            'paid ≤ total' => 'UPDATE invoices SET paid_amount = total_amount + 0.01 WHERE id = ' . (int) $invoice['id'],
            'void without payment' => "UPDATE invoices SET paid_amount = 1, voided_at = NOW(), voided_by = 1, void_reason = 'x' WHERE id = " . (int) $invoice['id'],
            'one live invoice per delivery note' => 'INSERT INTO invoices (invoice_number, delivery_note_id, sales_order_id, customer_id, invoice_date, due_date, customer_name, subtotal, dpp_amount, vat_amount, total_amount, vat_rate_percent, vat_dpp_numerator, vat_dpp_denominator, created_by)
                SELECT \'INV-9901-0001\', delivery_note_id, sales_order_id, customer_id, invoice_date, due_date, customer_name, subtotal, dpp_amount, vat_amount, total_amount, vat_rate_percent, vat_dpp_numerator, vat_dpp_denominator, created_by FROM invoices WHERE delivery_note_id = ' . $noteWithInvoice . ' AND voided_at IS NULL',
            'line amount = quantity × price' => 'UPDATE invoice_lines SET amount = amount + 1 WHERE invoice_id = ' . (int) $invoice['id'] . ' LIMIT 1',
            'payment amount > 0' => 'INSERT INTO invoice_payments (invoice_id, paid_on, amount, method, recorded_by) VALUES (' . (int) $invoice['id'] . ', CURRENT_DATE, 0, \'Cash\', 1)',
            'Issue ledger row on one note only' => 'INSERT INTO delivery_note_lots (delivery_note_id, delivery_note_line_id, ledger_id, lot_id, lot_code, quantity) SELECT delivery_note_id, delivery_note_line_id, ledger_id, lot_id, lot_code, quantity FROM delivery_note_lots LIMIT 1',
            'delivery note line = unit × factor' => 'UPDATE delivery_note_lines SET quantity = quantity + 1 LIMIT 1',
            'price source names its list' => "UPDATE sales_order_items SET price_list_id = NULL WHERE price_source = 'PRICE_LIST' LIMIT 1",
            'one SO per quotation' => 'UPDATE sales_orders SET quotation_id = (SELECT quotation_id FROM (SELECT quotation_id FROM sales_orders WHERE quotation_id IS NOT NULL LIMIT 1) q) WHERE quotation_id IS NULL LIMIT 1',
            'quotation reason when the price differs' => 'UPDATE quotation_items SET unit_price = unit_price + 1 WHERE price_reason IS NULL LIMIT 1',
            'NPWP 15 or 16 digits' => "UPDATE customers SET npwp = '12.345' WHERE id = 1",
            'valid_to after valid_from' => "UPDATE price_lists SET valid_from = '2026-12-31', valid_to = '2026-01-01' WHERE id = 1",
            'sequence prefixes' => "INSERT INTO document_sequences (prefix, period, last_number) VALUES ('XX', '2610', 1)",
        ];
        foreach ($guards as $label => $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('The database accepted a violation: ' . $label);
            } catch (PDOException $exception) {
                self::assertContains((int) ($exception->errorInfo[1] ?? 0), [3819, 1062, 1452, 1451], $label . ': ' . $exception->getMessage());
            }
        }
    }

    public function testMigrationCreatesOneDeliveryNotePerHistoricalShipmentAndIsRepeatable(): void
    {
        $shipments = $this->scalar('SELECT COUNT(*) FROM delivery_notes');
        $ledger = $this->scalar("SELECT COUNT(*) FROM stock_ledger WHERE movement_type = 'Issue' AND reference_type = 'SO'");
        $stock = $this->scalarText('SELECT CONCAT(SUM(quantity), "/", SUM(reserved_quantity)) FROM product_stocks');
        $this->downgrade();
        [$code, $output] = $this->runMigration('--dry-run');
        self::assertSame(0, $code, $output);
        $dry = json_decode($output, true);
        self::assertContains('delivery_notes', $dry['tables_missing']);
        self::assertSame([$ledger, $shipments, []], [$dry['delivery_notes']['issue_rows_to_link'], $dry['delivery_notes']['shipments_to_create'], $dry['delivery_notes']['conflicts']], 'the dry run previews the grouping before the DDL exists');

        // A shipment row whose product is not on its order is a conflict: nothing is created, exit 2.
        $order = (int) $this->scalar("SELECT MIN(reference_id) FROM stock_ledger WHERE movement_type = 'Issue' AND reference_type = 'SO'");
        $foreign = (int) $this->scalar('SELECT MIN(id) FROM products WHERE id NOT IN (SELECT product_id FROM sales_order_items WHERE sales_order_id = ' . $order . ')');
        $this->pdo->exec("INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by) VALUES ({$foreign}, 1, 'Issue', 1, 'SO', {$order}, 1)");
        [$code, $output] = $this->runMigration();
        self::assertSame(2, $code, $output);
        self::assertStringContainsString('is not on the order', $output);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM delivery_notes'));
        $this->pdo->exec("DELETE FROM stock_ledger WHERE product_id = {$foreign} AND reference_type = 'SO' AND reference_id = {$order} AND performed_by = 1 AND lot_id IS NULL AND quantity = 1 AND movement_type = 'Issue' ORDER BY id DESC LIMIT 1");

        [$code, $output] = $this->runMigration();
        self::assertSame(0, $code, $output);
        $result = json_decode($output, true);
        self::assertSame(['ready', $shipments, $ledger], [$result['status'], $result['delivery_notes']['delivery_notes_created'], $result['delivery_notes']['issue_rows_linked']]);
        self::assertSame($shipments, $this->scalar("SELECT COUNT(*) FROM delivery_notes WHERE origin = 'Migration' AND vehicle IS NULL"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM delivery_notes WHERE SUBSTRING(delivery_number, 4, 4) <> DATE_FORMAT(shipped_at, '%y%m')"), 'numbered in the month of the shipment');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM invoices'), 'no invoice is invented for history');
        self::assertSame(array_fill_keys(array_keys((new SalesDocumentChecks($this->pdo))->report()), 0), $result['sales_document_checks']);
        self::assertSame($stock, $this->scalarText('SELECT CONCAT(SUM(quantity), "/", SUM(reserved_quantity)) FROM product_stocks'), 'stock untouched');
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM schema_versions WHERE version = 'sales-documents-1'"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM sales_order_items WHERE price_source <> 'MASTER'"), 'history keeps master as its price source');

        [$code, $output] = $this->runMigration();
        self::assertSame(0, $code, $output);
        self::assertSame([true, 0], [json_decode($output, true)['already_applied'], json_decode($output, true)['delivery_notes']['delivery_notes_created']]);
        // After the migration the application works on the migrated schema: a new shipment gets the next DO number.
        [$customer, $product, $karton] = [(int) $this->scalar('SELECT MIN(id) FROM customers'), ...$this->stockedProduct()];
        $service = $this->salesOrderService();
        $draft = $service->createDraft($this->admin, $customer, 1, [['product_id' => $product, 'quantity' => 1, 'unit_code' => $karton]]);
        $service->submit($this->admin, $draft->id());
        $service->approve($this->admin, $draft->id());
        $service->issue($this->warehouse, $draft->id());
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM delivery_notes WHERE origin = 'Issue' AND sales_order_id = " . $draft->id()));
        self::assertSame(array_fill_keys(array_keys((new SalesDocumentChecks($this->pdo))->report()), 0), (new SalesDocumentChecks($this->pdo))->report());
    }

    /** Pre-D-19 schema: none of the new tables, columns or sequence prefixes. */
    private function downgrade(): void
    {
        foreach (['invoice_payments', 'invoice_lines', 'invoices', 'delivery_note_lots', 'delivery_note_lines', 'delivery_notes'] as $table) {
            $this->pdo->exec('DROP TABLE ' . $table);
        }
        $this->pdo->exec('ALTER TABLE sales_order_items DROP FOREIGN KEY fk_sales_order_items_price_list, DROP FOREIGN KEY fk_sales_order_items_quotation_item, DROP CHECK chk_sales_order_items_price_source');
        $this->pdo->exec('ALTER TABLE sales_order_items DROP INDEX uq_sales_order_items_quotation_item, DROP COLUMN price_source, DROP COLUMN price_list_id, DROP COLUMN quotation_item_id');
        $this->pdo->exec('ALTER TABLE sales_orders DROP FOREIGN KEY fk_sales_orders_quotation');
        $this->pdo->exec('ALTER TABLE sales_orders DROP INDEX uq_sales_orders_quotation, DROP COLUMN quotation_id');
        $this->pdo->exec('DROP TABLE quotation_items');
        $this->pdo->exec('DROP TABLE quotations');
        $this->pdo->exec('ALTER TABLE customers DROP FOREIGN KEY fk_customers_price_list, DROP CHECK chk_customers_npwp, DROP CHECK chk_customers_nik');
        $this->pdo->exec('ALTER TABLE customers DROP INDEX idx_customers_price_list, DROP COLUMN price_list_id, DROP COLUMN npwp, DROP COLUMN nik, DROP COLUMN tax_name, DROP COLUMN tax_address');
        $this->pdo->exec('DROP TABLE price_list_items');
        $this->pdo->exec('DROP TABLE price_lists');
        $this->pdo->exec("DELETE FROM document_sequences WHERE prefix NOT IN ('PO', 'SO')");
        $this->pdo->exec("ALTER TABLE document_sequences DROP CHECK chk_document_sequences_prefix, ADD CONSTRAINT chk_document_sequences_prefix CHECK (prefix IN ('PO', 'SO'))");
        $this->pdo->exec("DELETE FROM schema_versions WHERE version = 'sales-documents-1'");
    }

    /** A Grosir customer and a product with karton tiers on the Grosir list (price list 1 in the seed). @return array{0: int, 1: int, 2: string} */
    private function grosirFixture(): array
    {
        $customer = (int) $this->scalar("SELECT MIN(c.id) FROM customers c JOIN price_lists pl ON pl.id = c.price_list_id WHERE pl.name = 'Grosir'");
        [$product, $unit] = $this->stockedProduct("JOIN price_list_items pli ON pli.product_id = ps.product_id AND pli.price_list_id = 1 AND pli.min_quantity = 50 AND pli.unit_code = u.unit_code");

        return [$customer, $product, $unit];
    }

    /** A product with a non-base sales unit and at least 60 such units available at warehouse 1. @return array{0: int, 1: string} */
    private function stockedProduct(string $join = ''): array
    {
        $row = $this->pdo->query("SELECT ps.product_id, u.unit_code FROM product_stocks ps JOIN product_units u ON u.product_id = ps.product_id AND u.is_sales_default = 1 AND u.is_base = 0
            JOIN products p ON p.id = ps.product_id AND p.is_active = 1 {$join}
            WHERE ps.warehouse_id = 1 AND ps.quantity - ps.reserved_quantity - " . \App\Repository\MySql\ExpirySql::expiredQuantity('ps') . " >= 60 * u.factor_to_base ORDER BY ps.product_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'fixture product');

        return [(int) $row['product_id'], (string) $row['unit_code']];
    }

    private function salesOrderService(?DeliveryNoteRepositoryInterface $notes = null): SalesOrderService
    {
        $customers = [];
        foreach ($this->customers->all() as $customer) {
            $customers[$customer->id()] = $customer;
        }
        $stock = new StockService($this->balances, new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo), new MySqlStockCatalogRepository($this->pdo), new MySqlTransactionManager($this->pdo));

        return new SalesOrderService($this->orders, $this->sequences, $this->products, $customers, [1 => new Warehouse(1, 'Gudang Pusat Cikarang', '', true)], $stock, null, new OrderLinePricing(),
            new MySqlOrderExceptionRepository($this->pdo), $this->units, $this->resolver,
            new DeliveryNoteService($notes ?? new MySqlDeliveryNoteRepository($this->pdo), $this->sequences, $this->customers, new MySqlAuditLogRepository($this->pdo)));
    }

    private function quotationService(): QuotationService
    {
        return new QuotationService(new MySqlQuotationRepository($this->pdo), $this->sequences, $this->products, $this->customers, new MySqlWarehouseRepository($this->pdo), $this->salesOrderService(),
            new MySqlTransactionManager($this->pdo), $this->units, new OrderLinePricing(), $this->resolver, new MySqlAuditLogRepository($this->pdo));
    }

    private function invoiceService(): InvoiceService
    {
        return new InvoiceService(new MySqlInvoiceRepository($this->pdo), new MySqlDeliveryNoteRepository($this->pdo), $this->orders, $this->customers, $this->sequences, new MySqlTransactionManager($this->pdo), new TaxSettings(), new MySqlAuditLogRepository($this->pdo));
    }

    /** @return array{0: int, 1: string} */
    private function runMigration(string ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/migrate-sales-documents.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
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

    private function scalarText(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }
}
