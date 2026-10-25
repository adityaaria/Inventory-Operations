<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\Api\PriceResolutionController;
use App\Controller\CustomerController;
use App\Controller\DeliveryNoteController;
use App\Controller\InvoiceController;
use App\Controller\PriceListController;
use App\Controller\QuotationController;
use App\Controller\SalesOrderController;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\ProductUnit;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryCustomerRepository;
use App\Repository\InMemory\InMemoryDeliveryNoteRepository;
use App\Repository\InMemory\InMemoryDocumentSequenceRepository;
use App\Repository\InMemory\InMemoryInvoiceRepository;
use App\Repository\InMemory\InMemoryOrderExceptionRepository;
use App\Repository\InMemory\InMemoryPriceListRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryProductUnitRepository;
use App\Repository\InMemory\InMemoryQuotationRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\CustomerService;
use App\Service\DeliveryNoteService;
use App\Service\InvoiceService;
use App\Service\MasterDataAuthorizationService;
use App\Service\OrderLinePricing;
use App\Service\PriceListService;
use App\Service\PriceResolver;
use App\Service\ProductUnitCatalog;
use App\Service\QuotationService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\TaxSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImmediateTransactions;

/** D-19 / ADR-025: pages, forms and JSON of price lists, quotations, delivery notes and invoices (controllers render real views). */
final class SalesDocumentsControllerTest extends TestCase
{
    private InMemoryProductRepository $products;
    private ProductUnitCatalog $units;
    private InMemoryCustomerRepository $customers;
    private InMemoryPriceListRepository $lists;
    private InMemorySalesOrderRepository $orders;
    private InMemoryDocumentSequenceRepository $sequences;
    private InMemoryDeliveryNoteRepository $notes;
    private InMemoryInvoiceRepository $invoices;
    private InMemoryQuotationRepository $quotations;
    private InMemoryAuditLogRepository $audit;
    private PriceResolver $resolver;
    private DeliveryNoteService $deliveryNotes;
    private SalesOrderService $salesOrders;
    /** @var array<int, Warehouse> */
    private array $warehouses;

    protected function setUp(): void
    {
        $GLOBALS['csrf_token'] = 'test-token';
        $this->products = new InMemoryProductRepository([new Product(10, 'SKU-010', 'Susu UHT', 'pcs', 1000.0, 1500.0, 5, 1, true)]);
        $this->units = new ProductUnitCatalog($this->products, new InMemoryProductUnitRepository([
            new ProductUnit(1, 10, 'pcs', 1, null, null, null, true),
            new ProductUnit(2, 10, 'karton', 12, null, 12000.0, 17000.0, false, true, true),
        ]));
        $this->customers = new InMemoryCustomerRepository([new Customer(1, 'Grosir Sinar Jaya', '', '0812', 'Jl. Merdeka 1', true, null, '0123456789012345', null, 'PT Sinar Jaya', 'Jl. Pajak 1')]);
        $this->lists = new InMemoryPriceListRepository($this->customers, $this->products, '2026-10-12');
        $this->orders = new InMemorySalesOrderRepository();
        $this->sequences = new InMemoryDocumentSequenceRepository(new \DateTimeImmutable('2026-10-12 03:00:00', new \DateTimeZone('UTC')));
        $this->notes = new InMemoryDeliveryNoteRepository($this->orders, $this->products);
        $this->invoices = new InMemoryInvoiceRepository($this->orders, '2026-10-12');
        $this->notes->attachInvoices($this->invoices);
        $this->quotations = new InMemoryQuotationRepository($this->orders, '2026-10-12');
        $this->audit = new InMemoryAuditLogRepository();
        $this->resolver = new PriceResolver($this->lists, $this->products, $this->units);
        $this->deliveryNotes = new DeliveryNoteService($this->notes, $this->sequences, $this->customers, $this->audit);
        $this->warehouses = [1 => new Warehouse(1, 'Gudang Pusat', 'Cikarang', true)];
        $stock = new InMemoryStockRepository('2026-10-12');
        $stock->seed(10, 1, 240);
        $this->salesOrders = new SalesOrderService($this->orders, $this->sequences, $this->products, [1 => $this->customers->findById(1)], $this->warehouses,
            new StockService($stock, new InMemoryStockLedgerRepository(), $this->audit), new \App\Service\OperationIdempotency(new \App\Repository\InMemory\InMemoryOperationRequestRepository()), new OrderLinePricing(), new InMemoryOrderExceptionRepository(), $this->units, $this->resolver, $this->deliveryNotes);
        $admin = $this->actor(1, User::ROLE_ADMIN);
        $lists = $this->priceListService();
        $grosir = $lists->create($admin, ['name' => 'Grosir', 'is_active' => '1']);
        $lists->addItem($admin, $grosir, ['product_id' => '10', 'unit_code' => 'karton', 'min_quantity' => '10', 'unit_price' => '16000']);
        $lists->assign($admin, 1, $grosir);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['csrf_token']);
    }

    public function testPriceListPagesAreAdminOnlyAndShowTiersAndCustomers(): void
    {
        $controller = new PriceListController($this->priceListService(), $this->guard(1, User::ROLE_ADMIN), $this->customers, $this->products);
        $index = $controller->index();
        self::assertSame(200, $index->statusCode());
        self::assertStringContainsString('data-price-lists', $index->body());
        self::assertStringContainsString('Grosir', $index->body());
        $show = $controller->show(new Request('GET', '/price-lists/show', ['id' => '1'], [], []));
        self::assertStringContainsString('data-price-list-items', $show->body());
        self::assertStringContainsString('Rp 16.000,00', $show->body());
        self::assertStringContainsString('Grosir Sinar Jaya', $show->body());
        self::assertStringContainsString('name="csrf_token" value="test-token"', $show->body());
        $refused = $controller->addItem(new Request('POST', '/price-lists/items', [], ['id' => '1', 'product_id' => '10', 'unit_code' => 'karton', 'min_quantity' => '50', 'unit_price' => '100'], []));
        self::assertSame(422, $refused->statusCode());
        self::assertStringContainsString('below its current purchase price', $refused->body());
        self::assertSame(302, $controller->addItem(new Request('POST', '/price-lists/items', [], ['id' => '1', 'product_id' => '10', 'unit_code' => 'karton', 'min_quantity' => '50', 'unit_price' => '15500'], []))->statusCode());
        $this->expectForbidden(fn () => (new PriceListController($this->priceListService(), $this->guard(2, User::ROLE_SALES), $this->customers, $this->products))->index());
    }

    public function testPriceResolutionApiStatuses(): void
    {
        $request = new Request('GET', '/api/price-resolution', ['customer_id' => '1', 'product_id' => '10', 'unit_code' => 'karton', 'quantity' => '10'], [], []);
        $ok = (new PriceResolutionController($this->resolver, $this->guard(2, User::ROLE_SALES)))->show($request);
        self::assertSame([200, 'application/json; charset=UTF-8'], [$ok->statusCode(), $this->header($ok, 'Content-Type')]);
        self::assertSame(['PRICE_LIST', '16000.00'], [json_decode($ok->body(), true)['source'], json_decode($ok->body(), true)['price']]);
        self::assertSame(403, (new PriceResolutionController($this->resolver, $this->guard(4, User::ROLE_WAREHOUSE_STAFF)))->show($request)->statusCode());
        self::assertSame(401, (new PriceResolutionController($this->resolver, new AuthGuard(new SessionManager())))->show($request)->statusCode());
        self::assertSame(422, (new PriceResolutionController($this->resolver, $this->guard(2, User::ROLE_SALES)))->show(new Request('GET', '/api/price-resolution', ['customer_id' => 'x'], [], []))->statusCode());
    }

    public function testSalesOrderFormResolvesListPricesAndTheDetailShowsSourcesAndDeliveryNotes(): void
    {
        $controller = $this->salesOrderController(1, User::ROLE_ADMIN);
        $form = $controller->create()->body();
        self::assertStringContainsString('data-price-resolution="/api/price-resolution"', $form);
        self::assertStringContainsString('data-price-customer', $form);
        self::assertStringContainsString('List price: <span data-order-master>', $form);
        self::assertStringContainsString('<span data-order-price-source>Master price</span>', $form);
        self::assertSame(302, $controller->store(new Request('POST', '/sales-orders', [], ['customer_id' => '1', 'warehouse_id' => '1', 'product_id' => '10', 'quantity' => '12', 'unit_code' => 'karton', 'selling_price' => '16000'], []))->statusCode());
        $order = $this->orders->all()[0];
        self::assertSame(['PRICE_LIST', 16000.0], [$order->items()[0]->priceSource(), $order->items()[0]->listPrice()]);
        $this->salesOrders->submit($this->actor(1, User::ROLE_ADMIN), $order->id());
        $this->salesOrders->approve($this->actor(1, User::ROLE_ADMIN), $order->id());
        $issued = $this->salesOrderController(4, User::ROLE_WAREHOUSE_STAFF)->issue(new Request('POST', '/sales-orders/issue', [], ['id' => (string) $order->id(), 'quantities' => [$order->items()[0]->id() => '5'], 'vehicle' => 'B 9123 KXT', 'driver' => 'Slamet', 'operation_key' => str_repeat('a', 32)], []));
        self::assertSame(302, $issued->statusCode(), strip_tags($issued->body()));
        $detail = $controller->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('Price Source', $detail);
        self::assertStringContainsString('Price list Grosir', $detail);
        self::assertStringContainsString('data-delivery-notes', $detail);
        self::assertStringContainsString('DO-2610-0001', $detail);
        self::assertStringContainsString('not invoiced', $detail);
        $issueForm = $this->salesOrderController(4, User::ROLE_WAREHOUSE_STAFF)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('name="vehicle"', $issueForm);
        self::assertStringContainsString('name="driver"', $issueForm);
        self::assertStringNotContainsString('not invoiced', $issueForm, 'Warehouse sees no invoice state');
        $tooLong = $this->salesOrderController(4, User::ROLE_WAREHOUSE_STAFF)->issue(new Request('POST', '/sales-orders/issue', [], ['id' => (string) $order->id(), 'vehicle' => str_repeat('X', 41), 'operation_key' => str_repeat('b', 32)], []));
        self::assertSame(422, $tooLong->statusCode());
        self::assertStringContainsString('Vehicle may have at most 40 characters', $tooLong->body());
    }

    public function testQuotationPagesFlowFromFormToConvertedOrder(): void
    {
        $controller = $this->quotationController(2, User::ROLE_SALES);
        $form = $controller->create()->body();
        self::assertStringContainsString('data-price-resolution="/api/price-resolution"', $form);
        self::assertStringContainsString('name="valid_until"', $form);
        self::assertStringContainsString('value="2026-10-26"', $form);
        $bad = $controller->store(new Request('POST', '/quotations', [], ['customer_id' => '1', 'warehouse_id' => '1', 'product_id' => '10', 'quantity' => '12', 'unit_code' => 'karton', 'selling_price' => '15000'], []));
        self::assertSame(422, $bad->statusCode());
        self::assertStringContainsString('differs from the list price', $bad->body());
        $created = $controller->store(new Request('POST', '/quotations', [], ['customer_id' => '1', 'warehouse_id' => '1', 'product_id' => '10', 'quantity' => '12', 'unit_code' => 'karton', 'notes' => 'Proyek'], []));
        self::assertSame(['/quotations/show?id=1'], [$this->header($created, 'Location')]);
        $show = $controller->show(new Request('GET', '/quotations/show', ['id' => '1'], [], []))->body();
        self::assertStringContainsString('QT-2610-0001', $show);
        self::assertStringContainsString('data-price-source', $show);
        self::assertStringContainsString('Mark as Sent', $show);
        self::assertSame(302, $controller->send(new Request('POST', '/quotations/send', [], ['id' => '1'], []))->statusCode());
        $rejected = $controller->reject(new Request('POST', '/quotations/reject', [], ['id' => '1', 'reason' => ''], []));
        self::assertSame(422, $rejected->statusCode());
        self::assertStringContainsString('reject-quotation', $rejected->body());
        self::assertSame(302, $controller->accept(new Request('POST', '/quotations/accept', [], ['id' => '1'], []))->statusCode());
        $print = $controller->print(new Request('GET', '/quotations/print', ['id' => '1'], [], []))->body();
        self::assertStringContainsString('QUOTATION / PENAWARAN HARGA', $print);
        self::assertStringContainsString('/assets/js/print.js', $print);
        self::assertStringContainsString('Rp 192.000,00', $print, '12 karton × 16.000');
        $converted = $controller->convert(new Request('POST', '/quotations/convert', [], ['id' => '1'], []));
        self::assertSame('/sales-orders/show?id=1', $this->header($converted, 'Location'));
        $again = $controller->convert(new Request('POST', '/quotations/convert', [], ['id' => '1'], []));
        self::assertSame(422, $again->statusCode());
        self::assertStringContainsString('already converted', $again->body());
        self::assertStringContainsString('data-quotations', $controller->index(new Request('GET', '/quotations', ['status' => 'Converted'], [], []))->body());
        self::assertSame(422, $controller->index(new Request('GET', '/quotations', ['status' => 'Bogus'], [], []))->statusCode());
        $this->expectForbidden(fn () => $this->quotationController(4, User::ROLE_WAREHOUSE_STAFF)->create());
    }

    public function testDeliveryNoteAndInvoicePagesPrintAndRecordPayments(): void
    {
        $admin = $this->actor(1, User::ROLE_ADMIN);
        $order = $this->salesOrders->createDraft($this->actor(2, User::ROLE_SALES), 1, 1, [['product_id' => 10, 'quantity' => 12, 'unit_code' => 'karton']]);
        $this->salesOrders->submit($admin, $order->id());
        $this->salesOrders->approve($admin, $order->id());
        $this->salesOrders->issue($this->actor(4, User::ROLE_WAREHOUSE_STAFF), $order->id(), null, null, ['vehicle' => 'B 9123 KXT', 'driver' => 'Slamet']);
        $notes = new DeliveryNoteController($this->deliveryNotes, $this->guard(4, User::ROLE_WAREHOUSE_STAFF), $this->customers);
        $print = $notes->show(new Request('GET', '/delivery-notes/show', ['id' => '1'], [], []))->body();
        foreach (['DELIVERY NOTE / SURAT JALAN', 'DO-2610-0001', 'data-vehicle>B 9123 KXT', 'data-driver>Slamet', '12 karton', '144 pcs', 'SEED', 'Received by (customer)'] as $text) {
            self::assertStringContainsString($text, $print);
        }
        self::assertStringNotContainsString('Create Invoice', $print, 'Warehouse cannot invoice');
        self::assertStringContainsString('data-delivery-notes', $notes->index(new Request('GET', '/delivery-notes', ['invoiced' => 'no'], [], []))->body());
        self::assertSame(422, $notes->index(new Request('GET', '/delivery-notes', ['invoiced' => 'perhaps'], [], []))->statusCode());
        $this->expectForbidden(fn () => (new DeliveryNoteController($this->deliveryNotes, $this->guard(3, User::ROLE_SALES), $this->customers))->show(new Request('GET', '/delivery-notes/show', ['id' => '1'], [], [])));

        $controller = new InvoiceController($this->invoiceService(), $this->guard(1, User::ROLE_ADMIN));
        self::assertSame('/invoices/show?id=1', $this->header($controller->store(new Request('POST', '/invoices', [], ['delivery_note_id' => '1'], [])), 'Location'));
        $twice = $controller->store(new Request('POST', '/invoices', [], ['delivery_note_id' => '1'], []));
        self::assertSame(422, $twice->statusCode());
        self::assertStringContainsString('already invoiced', $twice->body());
        $show = $controller->show(new Request('GET', '/invoices/show', ['id' => '1'], [], []))->body();
        // 12 × 16.000 = 192.000; DPP 176.000; PPN 21.120; total 213.120.
        foreach (['data-subtotal>Rp 192.000,00', 'data-dpp>Rp 176.000,00', 'data-ppn>Rp 21.120,00', 'data-total>Rp 213.120,00', 'data-payment-form', 'Void Invoice'] as $text) {
            self::assertStringContainsString($text, $show);
        }
        $over = $controller->payment(new Request('POST', '/invoices/payments', [], ['id' => '1', 'paid_on' => '2026-10-12', 'amount' => '213120.01', 'method' => 'Transfer'], []));
        self::assertSame(422, $over->statusCode());
        self::assertStringContainsString('Overpayment refused', $over->body());
        self::assertSame(302, $controller->payment(new Request('POST', '/invoices/payments', [], ['id' => '1', 'paid_on' => '2026-10-12', 'amount' => '100000', 'method' => 'Transfer'], []))->statusCode());
        $partly = $controller->show(new Request('GET', '/invoices/show', ['id' => '1'], [], []))->body();
        self::assertStringContainsString('PartiallyPaid', $partly);
        self::assertStringNotContainsString('Void Invoice', $partly, 'no void once a payment exists');
        $printed = $controller->print(new Request('GET', '/invoices/print', ['id' => '1'], [], []))->body();
        foreach (['INVOICE / FAKTUR PENJUALAN', 'NPWP 0123456789012345', 'PT Sinar Jaya', 'data-print-ppn>Rp 21.120,00', 'Outstanding'] as $text) {
            self::assertStringContainsString($text, $printed);
        }
        self::assertStringContainsString('data-ar-aging', $controller->receivables()->body());
        $csv = $controller->receivablesCsv();
        self::assertSame('text/csv; charset=UTF-8', $this->header($csv, 'Content-Type'));
        self::assertStringContainsString('"Grosir Sinar Jaya",113120.00', $csv->body());
        self::assertStringContainsString('data-sales-by-customer', $controller->salesByCustomer(new Request('GET', '/reports/sales-by-customer', ['from' => '2026-10-01', 'to' => '2026-10-31'], [], []))->body());
        self::assertSame(422, $controller->salesByCustomer(new Request('GET', '/reports/sales-by-customer', ['from' => 'x'], [], []))->statusCode());
        self::assertStringContainsString('TOTAL,1,192000.00,21120.00,213120.00,100000.00', $controller->salesByCustomerCsv(new Request('GET', '/reports/sales-by-customer.csv', ['from' => '2026-10-01', 'to' => '2026-10-31'], [], []))->body());
        $sales = new InvoiceController($this->invoiceService(), $this->guard(2, User::ROLE_SALES));
        self::assertStringNotContainsString('data-payment-form', $sales->show(new Request('GET', '/invoices/show', ['id' => '1'], [], []))->body(), 'Sales read-only');
        $this->expectForbidden(fn () => $sales->receivables());
    }

    public function testCustomerFormsCarryTaxFieldsAndRefuseAnInvalidNpwp(): void
    {
        $controller = new CustomerController(new CustomerService($this->customers, new MasterDataAuthorizationService()), $this->customers, $this->guard(1, User::ROLE_ADMIN));
        self::assertStringContainsString('name="npwp"', $controller->create()->body());
        $edit = $controller->edit(new Request('GET', '/customers/edit', ['id' => '1'], [], []))->body();
        self::assertStringContainsString('value="0123456789012345"', $edit);
        $bad = $controller->update(new Request('POST', '/customers/update', [], ['id' => '1', 'name' => 'Grosir Sinar Jaya', 'email' => '', 'phone' => '', 'address' => 'Jl. Merdeka 1', 'npwp' => '12.34'], []));
        self::assertSame(422, $bad->statusCode());
        self::assertStringContainsString('NPWP must have 15 or 16 digits', $bad->body());
        self::assertSame(302, $controller->update(new Request('POST', '/customers/update', [], ['id' => '1', 'name' => 'Grosir Sinar Jaya', 'email' => '', 'phone' => '', 'address' => 'Jl. Merdeka 1', 'npwp' => '01.234.567.8-901.234', 'nik' => '', 'tax_name' => '', 'tax_address' => ''], []))->statusCode());
        self::assertSame(['012345678901234', null, 1], [$this->customers->findById(1)?->npwp(), $this->customers->findById(1)?->taxName(), $this->customers->findById(1)?->priceListId()]);
        self::assertSame(302, $controller->update(new Request('POST', '/customers/update', [], ['id' => '1', 'name' => 'Grosir Sinar Jaya Baru', 'email' => '', 'phone' => '', 'address' => 'Jl. Merdeka 1'], []))->statusCode());
        self::assertSame('012345678901234', $this->customers->findById(1)?->npwp(), 'a form without tax fields leaves them unchanged');
    }

    private function salesOrderController(int $userId, string $role): SalesOrderController
    {
        return new SalesOrderController($this->salesOrders, $this->orders, $this->products, [1 => $this->customers->findById(1)], $this->warehouses, $this->guard($userId, $role),
            null, null, $this->units, $this->deliveryNotes, $this->lists, $this->quotations);
    }

    private function quotationController(int $userId, string $role): QuotationController
    {
        $warehouses = $this->createStub(WarehouseRepositoryInterface::class);
        $warehouses->method('findById')->willReturnCallback(fn (int $id): ?Warehouse => $this->warehouses[$id] ?? null);
        $warehouses->method('all')->willReturn(array_values($this->warehouses));

        return new QuotationController(new QuotationService($this->quotations, $this->sequences, $this->products, $this->customers, $warehouses, $this->salesOrders, new ImmediateTransactions(), $this->units, new OrderLinePricing(), $this->resolver, $this->audit),
            $this->guard($userId, $role), $this->customers, $warehouses, $this->products, $this->units);
    }

    private function priceListService(): PriceListService
    {
        return new PriceListService($this->lists, $this->customers, $this->products, $this->units, new ImmediateTransactions(), $this->audit);
    }

    private function invoiceService(): InvoiceService
    {
        return new InvoiceService($this->invoices, $this->notes, $this->orders, $this->customers, $this->sequences, new ImmediateTransactions(), new TaxSettings(), $this->audit);
    }

    private function actor(int $id, string $role): AuthContext
    {
        return new AuthContext($id, 'user' . $id . '@example.test', $role);
    }

    private function guard(int $id, string $role): AuthGuard
    {
        $session = new SessionManager();
        $session->login($this->actor($id, $role));

        return new AuthGuard($session);
    }

    private function header(\App\Http\Response $response, string $name): ?string
    {
        return $response->headers()[$name] ?? null;
    }

    private function expectForbidden(callable $action): void
    {
        try {
            $action();
            self::fail('Expected 403.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
        }
    }
}
