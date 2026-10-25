<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\ProductUnit;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Exception\ValidationException;
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
use App\Service\DeliveryNoteService;
use App\Service\InvoiceService;
use App\Service\OrderLinePricing;
use App\Service\PriceListService;
use App\Service\PriceResolver;
use App\Service\ProductUnitCatalog;
use App\Service\QuotationService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\InvoiceStatus;
use App\Support\QuotationStatus;
use App\Support\TaxSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImmediateTransactions;

/**
 * D-19 / ADR-025 (unit, in memory): price lists with tiers and validity, prices measured against the resolved list price,
 * quotations and their conversion, delivery notes created inside the issue transaction, invoices with PPN, payments and
 * void, reports and authorization.
 */
final class SalesDocumentsTest extends TestCase
{
    private const TODAY = '2026-10-12';

    private InMemoryProductRepository $products;
    private InMemoryProductUnitRepository $unitRepository;
    private ProductUnitCatalog $units;
    private InMemoryCustomerRepository $customers;
    private InMemoryPriceListRepository $lists;
    private InMemorySalesOrderRepository $orders;
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryAuditLogRepository $audit;
    private InMemoryDocumentSequenceRepository $sequences;
    private InMemoryDeliveryNoteRepository $notes;
    private InMemoryInvoiceRepository $invoiceRepository;
    private InMemoryQuotationRepository $quotationRepository;
    private PriceResolver $resolver;
    private PriceListService $priceLists;
    private SalesOrderService $salesOrders;
    private DeliveryNoteService $deliveryNotes;
    private QuotationService $quotations;
    private InvoiceService $invoices;
    private AuthContext $admin;
    private AuthContext $sales;
    private AuthContext $otherSales;
    private AuthContext $warehouse;
    private int $grosir;

    protected function setUp(): void
    {
        // Product 10: base pcs (cost 1.000, master 1.500), karton of 12 with own prices (cost 12.000, master 17.000).
        $this->products = new InMemoryProductRepository([
            new Product(10, 'SKU-010', 'Susu UHT', 'pcs', 1000.0, 1500.0, 5, 1, true),
            new Product(11, 'SKU-011', 'Kopi', 'pcs', 2000.0, 3000.0, 5, 1, true),
        ]);
        $this->unitRepository = new InMemoryProductUnitRepository([
            new ProductUnit(1, 10, 'pcs', 1, null, null, null, true),
            new ProductUnit(2, 10, 'karton', 12, null, 12000.0, 17000.0, false, true, true),
            new ProductUnit(3, 11, 'pcs', 1, null, null, null, true),
        ]);
        $this->units = new ProductUnitCatalog($this->products, $this->unitRepository);
        $this->customers = new InMemoryCustomerRepository([
            new Customer(1, 'Grosir Sinar Jaya', '', '', 'Jl. Merdeka 1, Bekasi', true, null, '0123456789012345', null, 'PT Sinar Jaya', 'Jl. Pajak 1'),
            new Customer(2, 'Toko Tanpa Daftar', '', '', 'Jl. Pasar 2', true),
        ]);
        $this->lists = new InMemoryPriceListRepository($this->customers, $this->products, self::TODAY);
        $this->orders = new InMemorySalesOrderRepository();
        $this->stock = new InMemoryStockRepository(self::TODAY);
        $this->stock->seed(10, 1, 600);
        $this->stock->seed(11, 1, 100);
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->sequences = new InMemoryDocumentSequenceRepository(new \DateTimeImmutable('2026-10-12 03:00:00', new \DateTimeZone('UTC')));
        $this->notes = new InMemoryDeliveryNoteRepository($this->orders, $this->products);
        $this->invoiceRepository = new InMemoryInvoiceRepository($this->orders, self::TODAY);
        $this->notes->attachInvoices($this->invoiceRepository);
        $this->quotationRepository = new InMemoryQuotationRepository($this->orders, self::TODAY);
        $this->admin = new AuthContext(1, 'admin@test', User::ROLE_ADMIN);
        $this->sales = new AuthContext(2, 'sales@test', User::ROLE_SALES);
        $this->otherSales = new AuthContext(3, 'sales2@test', User::ROLE_SALES);
        $this->warehouse = new AuthContext(4, 'wh@test', User::ROLE_WAREHOUSE_STAFF);
        $this->resolver = new PriceResolver($this->lists, $this->products, $this->units);
        $this->priceLists = new PriceListService($this->lists, $this->customers, $this->products, $this->units, new ImmediateTransactions(), $this->audit);
        $this->deliveryNotes = new DeliveryNoteService($this->notes, $this->sequences, $this->customers, $this->audit);
        $this->salesOrders = $this->salesOrderService();
        $warehouses = $this->createStub(WarehouseRepositoryInterface::class);
        $warehouses->method('findById')->willReturnCallback(static fn (int $id): ?Warehouse => $id === 1 ? new Warehouse(1, 'Gudang Pusat', 'Cikarang', true) : null);
        $this->quotations = new QuotationService($this->quotationRepository, $this->sequences, $this->products, $this->customers, $warehouses, $this->salesOrders, new ImmediateTransactions(), $this->units, new OrderLinePricing(), $this->resolver, $this->audit);
        $this->invoices = new InvoiceService($this->invoiceRepository, $this->notes, $this->orders, $this->customers, $this->sequences, new ImmediateTransactions(), new TaxSettings(), $this->audit);

        // Grosir: karton 16.500 from 1, 16.000 from 10, 15.500 from 50; pcs 1.450 from 24.
        $this->grosir = $this->priceLists->create($this->admin, ['name' => 'Grosir', 'is_active' => '1', 'valid_from' => '2026-01-01', 'valid_to' => '']);
        foreach ([['karton', 1, '16500'], ['karton', 10, '16000'], ['karton', 50, '15500'], ['pcs', 24, '1450']] as [$unit, $minimum, $price]) {
            $this->priceLists->addItem($this->admin, $this->grosir, ['product_id' => '10', 'unit_code' => $unit, 'min_quantity' => (string) $minimum, 'unit_price' => $price]);
        }
        $this->priceLists->assign($this->admin, 1, $this->grosir);
    }

    // ---- F1: price lists and resolution -------------------------------------------------------------------------------

    public function testResolutionPicksTheHighestTierNotAboveTheQuantityPerUnit(): void
    {
        $product = $this->products->findById(10);
        $karton = $this->units->forProduct($product)->find('karton');
        $pcs = $this->units->forProduct($product)->find('pcs');
        self::assertSame([1650000, 'PRICE_LIST', 1], $this->resolved(1, $karton, 1));
        self::assertSame([1650000, 'PRICE_LIST', 1], $this->resolved(1, $karton, 9));
        self::assertSame([1600000, 'PRICE_LIST', 10], $this->resolved(1, $karton, 10));
        self::assertSame([1550000, 'PRICE_LIST', 50], $this->resolved(1, $karton, 500));
        self::assertSame([150000, 'MASTER', null], $this->resolved(1, $pcs, 23), 'below the first pcs tier: master price');
        self::assertSame([145000, 'PRICE_LIST', 24], $this->resolved(1, $pcs, 24));
        self::assertSame([1700000, 'MASTER', null], $this->resolved(2, $karton, 100), 'customer without a list');
        $next = $this->resolver->resolve(1, $product, $karton, 9);
        self::assertSame(['min_quantity' => 10, 'price_cents' => 1600000], $next['next_tier'], 'the next cheaper tier is offered');
        self::assertSame('Price list Grosir, from 1 karton', PriceResolver::label($next, 'karton'));
    }

    public function testResolutionRespectsActiveFlagAndValidityDates(): void
    {
        $karton = $this->units->forProduct($this->products->findById(10))->find('karton');
        $this->priceLists->update($this->admin, $this->grosir, ['name' => 'Grosir', 'is_active' => '1', 'valid_from' => '2026-10-13', 'valid_to' => '']);
        self::assertSame('MASTER', $this->resolved(1, $karton, 10)[1], 'not valid yet');
        $this->priceLists->update($this->admin, $this->grosir, ['name' => 'Grosir', 'is_active' => '1', 'valid_from' => '2026-01-01', 'valid_to' => '2026-10-11']);
        self::assertSame('MASTER', $this->resolved(1, $karton, 10)[1], 'expired yesterday');
        $this->priceLists->update($this->admin, $this->grosir, ['name' => 'Grosir', 'is_active' => '1', 'valid_from' => '2026-10-12', 'valid_to' => '2026-10-12']);
        self::assertSame('PRICE_LIST', $this->resolved(1, $karton, 10)[1], 'valid on both boundary days');
        $this->priceLists->update($this->admin, $this->grosir, ['name' => 'Grosir', 'description' => '', 'valid_from' => '', 'valid_to' => '']);
        self::assertSame('MASTER', $this->resolved(1, $karton, 10)[1], 'inactive list');
        $this->expectValidation(fn () => $this->priceLists->update($this->admin, $this->grosir, ['name' => 'Grosir', 'is_active' => '1', 'valid_from' => '2026-10-12', 'valid_to' => '2026-10-01']), 'Valid to cannot be before valid from');
    }

    public function testPriceListMaintenanceIsAdminOnlyAppliesTheCostFloorAndIsAudited(): void
    {
        foreach ([$this->sales, $this->warehouse] as $actor) {
            $this->expectStatus(403, fn () => $this->priceLists->create($actor, ['name' => 'X']));
            $this->expectStatus(403, fn () => $this->priceLists->addItem($actor, $this->grosir, []));
            $this->expectStatus(403, fn () => $this->priceLists->assign($actor, 2, $this->grosir));
        }
        $this->expectValidation(fn () => $this->priceLists->addItem($this->admin, $this->grosir, ['product_id' => '10', 'unit_code' => 'karton', 'min_quantity' => '100', 'unit_price' => '11999.99']), 'below its current purchase price');
        $this->expectValidation(fn () => $this->priceLists->addItem($this->admin, $this->grosir, ['product_id' => '10', 'unit_code' => 'pallet', 'min_quantity' => '1', 'unit_price' => '99999']), 'not an active unit');
        $this->expectValidation(fn () => $this->priceLists->addItem($this->admin, $this->grosir, ['product_id' => '10', 'unit_code' => 'karton', 'min_quantity' => '0', 'unit_price' => '20000']), 'at least 1');
        $this->expectValidation(fn () => $this->priceLists->addItem($this->admin, $this->grosir, ['product_id' => '10', 'unit_code' => 'karton', 'min_quantity' => '10', 'unit_price' => '15900']), 'unique value');
        $item = $this->lists->items($this->grosir)[0];
        $this->expectValidation(fn () => $this->priceLists->updateItem($this->admin, $item['id'], ['min_quantity' => '1', 'unit_price' => '100']), 'below its current purchase price');
        $actions = array_column($this->audit->entries(), 'action');
        self::assertContains('price-lists.create', $actions);
        self::assertContains('price-lists.item-add', $actions);
        self::assertContains('customers.price-list', $actions);
        self::assertSame($this->grosir, $this->customers->findById(1)?->priceListId());
        $this->priceLists->assign($this->admin, 1, null);
        self::assertNull($this->customers->findById(1)?->priceListId(), 'unassigning returns the customer to master prices');
    }

    public function testATierThatFellBelowCostIsRefusedWhenUsed(): void
    {
        $this->products->update(10, new \App\Support\ProductInput('SKU-010', 'Susu UHT', 'pcs', 1400.0, 1500.0, 5, 1));
        $karton = $this->units->forProduct($this->products->findById(10))->find('karton');
        $this->expectValidation(fn () => $this->resolver->resolve(1, $this->products->findById(10), $karton, 60), 'An Admin must update the price list');
    }

    public function testSalesOrderLinesUseTheResolvedPriceWithItsSourceAndTheCapIsMeasuredAgainstIt(): void
    {
        $order = $this->salesOrders->createDraft($this->sales, 1, 1, [['product_id' => 10, 'quantity' => 10, 'unit_code' => 'karton'], ['product_id' => 11, 'quantity' => 2]]);
        [$karton, $kopi] = $order->items();
        self::assertSame([16000.0, 16000.0, null, 'PRICE_LIST', $this->grosir], [$karton->sellingPrice(), $karton->listPrice(), $karton->priceReason(), $karton->priceSource(), $karton->priceListId()]);
        self::assertSame([3000.0, 'MASTER', null], [$kopi->listPrice(), $kopi->priceSource(), $kopi->priceListId()]);

        // Submitting the resolved price needs no reason; a client "list_price" is never read.
        $same = $this->salesOrders->createDraft($this->sales, 1, 1, [['product_id' => 10, 'quantity' => 10, 'unit_code' => 'karton', 'price' => '16000', 'list_price' => '1']]);
        self::assertSame([16000.0, null], [$same->items()[0]->listPrice(), $same->items()[0]->priceReason()]);
        // Sales cap (10 %) is measured against 16.000: 14.400 is allowed, 14.399,99 is not; the message names the list price.
        $capped = $this->salesOrders->createDraft($this->sales, 1, 1, [['product_id' => 10, 'quantity' => 10, 'unit_code' => 'karton', 'price' => '14400', 'price_reason' => 'Volume order']]);
        self::assertSame([14400.0, 16000.0], [$capped->items()[0]->sellingPrice(), $capped->items()[0]->listPrice()]);
        $this->expectValidation(fn () => $this->salesOrders->createDraft($this->sales, 1, 1, [['product_id' => 10, 'quantity' => 10, 'unit_code' => 'karton', 'price' => '14399.99', 'price_reason' => 'Volume order']]), 'below the list price');
        $this->expectValidation(fn () => $this->salesOrders->createDraft($this->sales, 1, 1, [['product_id' => 10, 'quantity' => 10, 'unit_code' => 'karton', 'price' => '15000']]), 'differs from the list price');
        $this->expectValidation(fn () => $this->salesOrders->createDraft($this->admin, 1, 1, [['product_id' => 10, 'quantity' => 10, 'unit_code' => 'karton', 'price' => '11999', 'price_reason' => 'Cuci gudang']]), 'below its current purchase price');
    }

    public function testPriceResolutionApiAuthorizationAndValidation(): void
    {
        $answer = $this->resolver->lookup($this->sales, ['customer_id' => '1', 'product_id' => '10', 'unit_code' => 'karton', 'quantity' => '12']);
        self::assertSame(['PRICE_LIST', '16000.00', '17000.00', 'Price list Grosir, from 10 karton'], [$answer['source'], $answer['price'], $answer['master_price'], $answer['label']]);
        self::assertSame(['min_quantity' => 50, 'price' => '15500.00'], $answer['next_tier']);
        self::assertSame('MASTER', $this->resolver->lookup($this->admin, ['customer_id' => '2', 'product_id' => '10', 'unit_code' => 'karton', 'quantity' => ''])['source']);
        $this->expectStatus(403, fn () => $this->resolver->lookup($this->warehouse, ['customer_id' => '1', 'product_id' => '10']));
        $this->expectValidation(fn () => $this->resolver->lookup($this->sales, ['customer_id' => 'x', 'product_id' => '10']), 'customer id');
        $this->expectValidation(fn () => $this->resolver->lookup($this->sales, ['customer_id' => '1', 'product_id' => '99']), 'Active product');
    }

    // ---- F2: quotations -----------------------------------------------------------------------------------------------

    public function testQuotationLifecycleAndConversionToOneSalesOrder(): void
    {
        $id = $this->quotations->create($this->sales, 1, 1, '2026-10-20', 'Proyek kantin', [
            ['product_id' => 10, 'quantity' => 10, 'unit_code' => 'karton', 'price' => '15500', 'price_reason' => 'Harga proyek'],
            ['product_id' => 11, 'quantity' => 5],
        ]);
        $quotation = $this->quotations->show($this->sales, $id);
        self::assertSame(['QT-2610-0001', 'Draft', 'Draft'], [$quotation['quotation_number'], $quotation['status'], $quotation['effective_status']]);
        self::assertSame(['15500.00', '16000.00', 'PRICE_LIST'], [$quotation['lines'][0]['unit_price'], $quotation['lines'][0]['list_price'], $quotation['lines'][0]['price_source']]);
        $this->expectValidation(fn () => $this->quotations->accept($this->sales, $id), 'Only a Sent quotation');
        $this->expectValidation(fn () => $this->quotations->convert($this->sales, $id), 'Only an Accepted quotation');
        $this->quotations->send($this->sales, $id);
        $this->expectStatus(403, fn () => $this->quotations->accept($this->otherSales, $id));
        $this->quotations->accept($this->sales, $id);

        // Prices change after the quote: the quoted prices still carry over.
        $this->priceLists->updateItem($this->admin, $this->lists->items($this->grosir)[1]['id'], ['min_quantity' => '10', 'unit_price' => '16800']);
        $orderId = $this->quotations->convert($this->sales, $id);
        $order = $this->orders->findById($orderId);
        self::assertInstanceOf(SalesOrder::class, $order);
        self::assertSame([SalesOrder::STATUS_DRAFT, $id, 2], [$order->status(), $order->quotationId(), $order->createdBy()]);
        $line = $order->items()[0];
        self::assertSame([15500.0, 16000.0, 'Harga proyek', 'PRICE_LIST', $quotation['lines'][0]['id']], [$line->sellingPrice(), $line->listPrice(), $line->priceReason(), $line->priceSource(), $line->quotationItemId()]);
        self::assertSame('Accepted', $this->quotations->show($this->admin, $id)['effective_status']);
        self::assertFalse($this->quotations->show($this->admin, $id)['can_convert']);
        $this->expectValidation(fn () => $this->quotations->convert($this->admin, $id), 'already converted');
        $this->expectValidation(fn () => $this->quotations->cancel($this->admin, $id), 'unconverted');
        self::assertContains('quotations.convert', array_column($this->audit->entries(), 'action'));
    }

    public function testExpiryIsComputedAndBlocksSendingAcceptingAndConverting(): void
    {
        $draft = $this->quotations->create($this->sales, 1, 1, '2026-10-12', '', [['product_id' => 11, 'quantity' => 1]]);
        $sent = $this->quotations->create($this->sales, 1, 1, '2026-10-12', '', [['product_id' => 11, 'quantity' => 1]]);
        $accepted = $this->quotations->create($this->sales, 1, 1, '2026-10-12', '', [['product_id' => 11, 'quantity' => 1]]);
        $this->quotations->send($this->sales, $sent);
        $this->quotations->send($this->sales, $accepted);
        $this->quotations->accept($this->sales, $accepted);
        $this->quotationRepository->setToday('2026-10-13');
        self::assertSame('Expired', $this->quotations->show($this->sales, $draft)['effective_status']);
        self::assertSame('Expired', $this->quotations->show($this->sales, $sent)['effective_status']);
        self::assertSame('Accepted', $this->quotations->show($this->sales, $accepted)['effective_status'], 'Accepted keeps its status after validity');
        $this->expectValidation(fn () => $this->quotations->send($this->sales, $draft), 'expired');
        $this->expectValidation(fn () => $this->quotations->accept($this->sales, $sent), 'expired');
        $this->expectValidation(fn () => $this->quotations->convert($this->sales, $accepted), 'can no longer be used');
        self::assertSame(1, $this->quotations->search($this->sales, ['status' => 'Expired', 'q' => 'QT-2610-0001'])->total());
        $this->quotations->cancel($this->sales, $draft);
        self::assertSame('Cancelled', $this->quotations->show($this->sales, $draft)['effective_status']);
        self::assertSame('Expired', QuotationStatus::effective(['status' => 'Sent', 'valid_until' => '2026-10-11'], '2026-10-12'));
        self::assertSame('Sent', QuotationStatus::effective(['status' => 'Sent', 'valid_until' => '2026-10-12'], '2026-10-12'));
    }

    public function testQuotationRulesAndAuthorization(): void
    {
        $this->expectStatus(403, fn () => $this->quotations->create($this->warehouse, 1, 1, null, '', [['product_id' => 11, 'quantity' => 1]]));
        $this->expectValidation(fn () => $this->quotations->create($this->sales, 1, 1, '2026-10-11', '', [['product_id' => 11, 'quantity' => 1]]), 'Valid until');
        $this->expectValidation(fn () => $this->quotations->create($this->sales, 1, 1, '2027-12-01', '', [['product_id' => 11, 'quantity' => 1]]), 'at most 365 days');
        $this->expectValidation(fn () => $this->quotations->create($this->sales, 1, 1, null, '', [['product_id' => 10, 'quantity' => 1, 'unit_code' => 'karton', 'price' => '1000', 'price_reason' => 'Murah']]), 'below its current purchase price');
        $id = $this->quotations->create($this->sales, 1, 1, null, '', [['product_id' => 11, 'quantity' => 1]]);
        self::assertSame('2026-10-26', $this->quotations->show($this->sales, $id)['valid_until'], 'default validity is 14 days');
        $this->expectStatus(403, fn () => $this->quotations->show($this->otherSales, $id));
        $this->expectStatus(403, fn () => $this->quotations->show($this->warehouse, $id));
        self::assertSame(0, $this->quotations->search($this->otherSales, [])->total(), 'Sales list only their own quotations');
        self::assertSame(1, $this->quotations->search($this->admin, [])->total());
        $this->expectValidation(fn () => $this->quotations->reject($this->sales, $id, 'Mahal'), 'Only a Sent quotation');
        $this->quotations->send($this->sales, $id);
        $this->expectValidation(fn () => $this->quotations->reject($this->sales, $id, ''), 'reason');
        $this->quotations->reject($this->admin, $id, 'Harga pesaing lebih murah');
        self::assertSame(['Rejected', 'Harga pesaing lebih murah'], [$this->quotations->show($this->sales, $id)['status'], $this->quotations->show($this->sales, $id)['rejection_reason']]);
        $this->expectValidation(fn () => $this->quotations->search($this->admin, ['status' => 'Invented']), 'Unknown quotation status');
    }

    public function testConversionRevalidatesTheCostFloorAgainstTheCurrentPurchasePrice(): void
    {
        $id = $this->quotations->create($this->admin, 2, 1, null, '', [['product_id' => 11, 'quantity' => 2, 'price' => '2100', 'price_reason' => 'Harga khusus']]);
        $this->quotations->send($this->admin, $id);
        $this->quotations->accept($this->admin, $id);
        $this->products->update(11, new \App\Support\ProductInput('SKU-011', 'Kopi', 'pcs', 2500.0, 3000.0, 5, 1));
        $this->expectValidation(fn () => $this->quotations->convert($this->admin, $id), 'below its current purchase price');
        self::assertNull($this->quotations->show($this->admin, $id)['sales_order_id'], 'nothing was converted');
    }

    // ---- F3: delivery notes -------------------------------------------------------------------------------------------

    public function testEveryShipmentCreatesItsDeliveryNoteWithLinesLotsVehicleAndDriver(): void
    {
        $order = $this->approvedOrder([['product_id' => 10, 'quantity' => 3, 'unit_code' => 'karton'], ['product_id' => 11, 'quantity' => 4]]);
        [$karton, $kopi] = $order->items();
        $this->salesOrders->issue($this->warehouse, $order->id(), null, [$karton->id() => 2, $kopi->id() => 0], ['vehicle' => ' B 9123  KXT ', 'driver' => 'Slamet']);
        $this->salesOrders->issue($this->warehouse, $order->id(), null, null, []);
        $notes = $this->deliveryNotes->forOrder($this->warehouse, $this->orders->findById($order->id()));
        self::assertCount(2, $notes);
        self::assertSame(['DO-2610-0001', 1, 'B 9123 KXT', 'Slamet', 'Grosir Sinar Jaya', 'Jl. Merdeka 1, Bekasi', 4], [$notes[0]['delivery_number'], $notes[0]['shipment_number'], $notes[0]['vehicle'], $notes[0]['driver'], $notes[0]['ship_to_name'], $notes[0]['ship_to_address'], $notes[0]['prepared_by']]);
        self::assertSame(['DO-2610-0002', 2, null, null], [$notes[1]['delivery_number'], $notes[1]['shipment_number'], $notes[1]['vehicle'], $notes[1]['driver']]);
        $first = $this->deliveryNotes->show($this->admin, $notes[0]['id']);
        self::assertCount(1, $first['lines'], 'a zero line is not on the note');
        self::assertSame(['karton', 12, 2, 24], [$first['lines'][0]['unit_code'], $first['lines'][0]['unit_factor'], $first['lines'][0]['unit_quantity'], $first['lines'][0]['quantity']]);
        self::assertSame([['lot_code' => 'SEED', 'quantity' => 24]], array_map(static fn (array $lot): array => ['lot_code' => $lot['lot_code'], 'quantity' => $lot['quantity']], $first['lines'][0]['lots']));
        $second = $this->deliveryNotes->show($this->admin, $notes[1]['id']);
        self::assertSame([12, 4], array_column($second['lines'], 'quantity'), 'the second note ships the remainder');
        self::assertSame(2, count(array_filter($this->audit->entries(), static fn (array $entry): bool => $entry['action'] === 'delivery-notes.create')));
    }

    public function testDeliveryNoteFailureRollsTheShipmentBackAndInvalidDetailsAreRefusedFirst(): void
    {
        $order = $this->approvedOrder([['product_id' => 11, 'quantity' => 4]]);
        $this->expectValidation(fn () => $this->salesOrders->issue($this->warehouse, $order->id(), null, null, ['vehicle' => str_repeat('B', 41)]), 'Vehicle may have at most 40');
        $this->notes->failOnCreate = new \App\Exception\PersistenceException('disk full');
        try {
            $this->salesOrders->issue($this->warehouse, $order->id());
            self::fail('The issue must fail with its delivery note.');
        } catch (\App\Exception\PersistenceException) {
        }
        // The in-memory stock repository rolls back like a transaction; order, ledger and audit repositories do not, so the
        // complete rollback (order lines, ledger rows, lots, audit, number) is proven on MySQL in SalesDocumentsIntegrationTest.
        self::assertSame([100, 4], [$this->stock->quantity(11, 1), $this->stock->reservedQuantity(11, 1)], 'stock and reservation rolled back with the note');
        self::assertSame([], $this->notes->forSalesOrder($order->id()), 'no delivery note was stored');
    }

    public function testDeliveryNoteAccessIsLimitedToOwnOrdersForSales(): void
    {
        $order = $this->approvedOrder([['product_id' => 11, 'quantity' => 1]]);
        $this->salesOrders->issue($this->warehouse, $order->id());
        $note = $this->notes->forSalesOrder($order->id())[0];
        self::assertSame($note['id'], $this->deliveryNotes->show($this->sales, $note['id'])['id'], 'the order owner sees the note');
        self::assertSame($note['id'], $this->deliveryNotes->show($this->warehouse, $note['id'])['id']);
        $this->expectStatus(403, fn () => $this->deliveryNotes->show($this->otherSales, $note['id']));
        self::assertSame(0, $this->deliveryNotes->search($this->otherSales, [])->total());
        self::assertSame(1, $this->deliveryNotes->search($this->sales, ['invoiced' => 'no'])->total());
        $this->expectValidation(fn () => $this->deliveryNotes->search($this->admin, ['invoiced' => 'maybe']), 'Unknown invoice filter');
    }

    // ---- F4: invoices with PPN ----------------------------------------------------------------------------------------

    public function testInvoiceArithmeticAndRoundingEdges(): void
    {
        $tax = new TaxSettings();
        self::assertSame(['subtotal_cents' => 100000000, 'dpp_cents' => 91666700, 'vat_cents' => 11000000, 'total_cents' => 111000000], $tax->totals(100000000), '12 % of 11/12 = 11 %');
        self::assertSame(['subtotal_cents' => 600, 'dpp_cents' => 600, 'vat_cents' => 100, 'total_cents' => 700], $tax->totals(600), 'DPP 5,50 rounds half up to 6; PPN 0,72 to 1');
        self::assertSame(['subtotal_cents' => 49, 'dpp_cents' => 0, 'vat_cents' => 0, 'total_cents' => 49], $tax->totals(49), 'below half a Rupiah of DPP');
        self::assertSame(['subtotal_cents' => 5000, 'dpp_cents' => 5000, 'vat_cents' => 600, 'total_cents' => 5600], TaxSettings::compute(5000, 11, 1, 1), 'PPN 5,50 rounds half up to 6');
        self::assertSame(['subtotal_cents' => 4900, 'dpp_cents' => 4900, 'vat_cents' => 500, 'total_cents' => 5400], TaxSettings::compute(4900, 11, 1, 1), 'PPN 5,39 rounds down');
        self::assertSame(['subtotal_cents' => 0, 'dpp_cents' => 0, 'vat_cents' => 0, 'total_cents' => 0], $tax->totals(0));
        self::assertSame('11,00%', $tax->effectiveRateLabel());
        self::assertSame([1234567, '12345.67', -5, '-0.05'], [TaxSettings::cents('12345.67'), TaxSettings::decimal(1234567), TaxSettings::cents('-0.05'), TaxSettings::decimal(-5)]);
        foreach ([[101, 11, 12, 30], [-1, 11, 12, 30], [12, 13, 12, 30], [12, 0, 12, 30], [12, 1, 1001, 30], [12, 11, 12, 366]] as $invalid) {
            try {
                new TaxSettings(...$invalid);
                self::fail('Invalid tax settings accepted: ' . json_encode($invalid));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $config = new \App\Support\Config(['VAT_RATE_PERCENT' => '11', 'VAT_DPP_NUMERATOR' => '1', 'VAT_DPP_DENOMINATOR' => '1', 'INVOICE_PAYMENT_TERMS_DAYS' => '14']);
        self::assertSame([11, 1, 1, 14], [TaxSettings::fromConfig($config)->ratePercent(), TaxSettings::fromConfig($config)->dppNumerator(), TaxSettings::fromConfig($config)->dppDenominator(), TaxSettings::fromConfig($config)->paymentTermsDays()]);
        $this->expectException(\InvalidArgumentException::class);
        TaxSettings::fromConfig(new \App\Support\Config(['VAT_RATE_PERCENT' => 'twelve', 'VAT_DPP_NUMERATOR' => '11', 'VAT_DPP_DENOMINATOR' => '12', 'INVOICE_PAYMENT_TERMS_DAYS' => '30']));
    }

    public function testInvoiceBillsWhatWasShippedAtTheSalesOrderPriceWithASnapshot(): void
    {
        $order = $this->approvedOrder([['product_id' => 10, 'quantity' => 3, 'unit_code' => 'karton', 'price' => '15999.99', 'price_reason' => 'Promo'], ['product_id' => 11, 'quantity' => 7]]);
        [$karton, $kopi] = $order->items();
        $this->salesOrders->issue($this->warehouse, $order->id(), null, [$karton->id() => 2, $kopi->id() => 7]);
        $note = $this->notes->forSalesOrder($order->id())[0];
        $this->expectStatus(403, fn () => $this->invoices->createFromDeliveryNote($this->sales, $note['id']));
        $id = $this->invoices->createFromDeliveryNote($this->admin, $note['id']);
        $invoice = $this->invoices->show($this->admin, $id);
        // 2 × 15.999,99 + 7 × 3.000 = 52.999,98; DPP = 48.583,315 → 48.583; PPN = 5.829,96 → 5.830; total 58.829,98.
        self::assertSame(['INV-2610-0001', '52999.98', '48583.00', '5830.00', '58829.98'], [$invoice['invoice_number'], $invoice['subtotal'], $invoice['dpp_amount'], $invoice['vat_amount'], $invoice['total_amount']]);
        self::assertSame([[2, '15999.99', '31999.98'], [7, '3000.00', '21000.00']], array_map(static fn (array $line): array => [$line['unit_quantity'], $line['unit_price'], $line['amount']], $invoice['lines']));
        self::assertSame([12, 11, 12, '2026-10-12', '2026-11-11'], [$invoice['vat_rate_percent'], $invoice['vat_dpp_numerator'], $invoice['vat_dpp_denominator'], $invoice['invoice_date'], $invoice['due_date']]);
        self::assertSame(['0123456789012345', 'PT Sinar Jaya', 'Jl. Pajak 1', 'Grosir Sinar Jaya'], [$invoice['customer_npwp'], $invoice['customer_tax_name'], $invoice['customer_tax_address'], $invoice['customer_name']]);
        self::assertSame('Unpaid', $invoice['state']['status']);
        $this->expectValidation(fn () => $this->invoices->createFromDeliveryNote($this->admin, $note['id']), 'already invoiced');

        // A later settings change does not alter the stored invoice; its own snapshot still recomputes exactly.
        $otherTax = new InvoiceService($this->invoiceRepository, $this->notes, $this->orders, $this->customers, $this->sequences, new ImmediateTransactions(), new TaxSettings(11, 1, 1, 7));
        self::assertSame('5830.00', $otherTax->show($this->admin, $id)['vat_amount']);
        self::assertSame(TaxSettings::cents($invoice['total_amount']), TaxSettings::compute(TaxSettings::cents($invoice['subtotal']), $invoice['vat_rate_percent'], $invoice['vat_dpp_numerator'], $invoice['vat_dpp_denominator'])['total_cents']);
        $this->salesOrders->issue($this->warehouse, $order->id());
        $secondNote = $this->notes->forSalesOrder($order->id())[1];
        $second = $otherTax->show($this->admin, $otherTax->createFromDeliveryNote($this->admin, $secondNote['id']));
        self::assertSame(['15999.99', '1760.00', '17759.99', 11, 1, 1, '2026-10-19'], [$second['subtotal'], $second['vat_amount'], $second['total_amount'], $second['vat_rate_percent'], $second['vat_dpp_numerator'], $second['vat_dpp_denominator'], $second['due_date']]);
    }

    public function testPaymentStatusMachineOverpaymentAndPaymentRules(): void
    {
        $id = $this->invoicedNote([['product_id' => 11, 'quantity' => 10]]); // 30.000 + PPN 3.300 = 33.300
        $this->expectStatus(403, fn () => $this->invoices->recordPayment($this->sales, $id, ['paid_on' => self::TODAY, 'amount' => '1', 'method' => 'Cash']));
        $this->expectValidation(fn () => $this->invoices->recordPayment($this->admin, $id, ['paid_on' => self::TODAY, 'amount' => '0', 'method' => 'Cash']), 'greater than zero');
        $this->expectValidation(fn () => $this->invoices->recordPayment($this->admin, $id, ['paid_on' => self::TODAY, 'amount' => '1.234', 'method' => 'Cash']), 'at most 2 decimals');
        $this->expectValidation(fn () => $this->invoices->recordPayment($this->admin, $id, ['paid_on' => self::TODAY, 'amount' => '1', 'method' => 'Barter']), 'Payment method');
        $this->expectValidation(fn () => $this->invoices->recordPayment($this->admin, $id, ['paid_on' => '2026-10-11', 'amount' => '1', 'method' => 'Cash']), 'before the invoice date');
        $this->expectValidation(fn () => $this->invoices->recordPayment($this->admin, $id, ['paid_on' => '2026-10-13', 'amount' => '1', 'method' => 'Cash']), 'in the future');
        $this->expectValidation(fn () => $this->invoices->recordPayment($this->admin, $id, ['paid_on' => self::TODAY, 'amount' => '33300.01', 'method' => 'Transfer']), 'Overpayment refused');

        $this->invoices->recordPayment($this->admin, $id, ['paid_on' => self::TODAY, 'amount' => '10000', 'method' => 'Giro', 'reference' => 'GR-1']);
        self::assertSame(['PartiallyPaid', 2330000], [$this->invoices->show($this->admin, $id)['state']['status'], $this->invoices->show($this->admin, $id)['state']['outstanding_cents']]);
        $this->expectValidation(fn () => $this->invoices->recordPayment($this->admin, $id, ['paid_on' => self::TODAY, 'amount' => '23300.01', 'method' => 'Transfer']), 'Overpayment refused');
        $this->invoices->recordPayment($this->admin, $id, ['paid_on' => self::TODAY, 'amount' => '23300', 'method' => 'Transfer']);
        $paid = $this->invoices->show($this->admin, $id);
        self::assertSame(['Paid', '33300.00', 2], [$paid['state']['status'], $paid['paid_amount'], count($paid['payments'])]);
        $this->expectValidation(fn () => $this->invoices->recordPayment($this->admin, $id, ['paid_on' => self::TODAY, 'amount' => '0.01', 'method' => 'Cash']), 'Overpayment refused');
        $this->expectValidation(fn () => $this->invoices->void($this->admin, $id, 'Salah'), 'has payments');
        $statuses = array_column(array_filter($this->audit->entries(), static fn (array $entry): bool => $entry['action'] === 'invoices.payment'), 'metadata');
        self::assertSame(['PartiallyPaid', 'Paid'], array_column($statuses, 'status_after'));
    }

    public function testComputedStatusAndOverdue(): void
    {
        $base = ['total_amount' => '100.00', 'paid_amount' => '0.00', 'voided_at' => null, 'due_date' => '2026-10-10'];
        self::assertSame(['status' => 'Unpaid', 'overdue' => true, 'outstanding_cents' => 10000, 'days_overdue' => 2], InvoiceStatus::of($base, self::TODAY));
        self::assertSame(['status' => 'Unpaid', 'overdue' => false, 'outstanding_cents' => 10000, 'days_overdue' => 0], InvoiceStatus::of(['due_date' => '2026-10-12'] + $base, self::TODAY), 'due today is not overdue');
        self::assertSame('PartiallyPaid', InvoiceStatus::of(['paid_amount' => '0.01'] + $base, self::TODAY)['status']);
        self::assertSame(['status' => 'Paid', 'overdue' => false, 'outstanding_cents' => 0, 'days_overdue' => 0], InvoiceStatus::of(['paid_amount' => '100.00'] + $base, self::TODAY));
        self::assertSame('Void', InvoiceStatus::of(['voided_at' => '2026-10-11 10:00:00'] + $base, self::TODAY)['status']);
    }

    public function testVoidNeedsAReasonAndNoPaymentNeverTouchesStockAndFreesTheDeliveryNote(): void
    {
        $id = $this->invoicedNote([['product_id' => 11, 'quantity' => 2]]);
        $noteId = $this->invoices->show($this->admin, $id)['delivery_note_id'];
        $stockBefore = [$this->stock->quantity(11, 1), count($this->ledger->entries())];
        $this->expectStatus(403, fn () => $this->invoices->void($this->sales, $id, 'Salah'));
        $this->expectValidation(fn () => $this->invoices->void($this->admin, $id, ''), 'reason');
        $this->invoices->void($this->admin, $id, 'NPWP salah');
        $void = $this->invoices->show($this->admin, $id);
        self::assertSame(['Void', 'NPWP salah'], [$void['state']['status'], $void['void_reason']]);
        self::assertSame($stockBefore, [$this->stock->quantity(11, 1), count($this->ledger->entries())], 'voiding never moves stock');
        $this->expectValidation(fn () => $this->invoices->void($this->admin, $id, 'Lagi'), 'already void');
        $this->expectValidation(fn () => $this->invoices->recordPayment($this->admin, $id, ['paid_on' => self::TODAY, 'amount' => '1', 'method' => 'Cash']), 'is void');
        $again = $this->invoices->createFromDeliveryNote($this->admin, $noteId);
        self::assertNotSame($id, $again, 'the delivery note can be invoiced again after a void');
        self::assertSame('INV-2610-0002', $this->invoices->show($this->admin, $again)['invoice_number']);
    }

    public function testInvoiceAccessAndReports(): void
    {
        $paid = $this->invoicedNote([['product_id' => 11, 'quantity' => 10]]);
        $open = $this->invoicedNote([['product_id' => 11, 'quantity' => 1]], 2);
        $this->invoices->recordPayment($this->admin, $paid, ['paid_on' => self::TODAY, 'amount' => '33300', 'method' => 'Transfer']);
        $this->expectStatus(403, fn () => $this->invoices->show($this->warehouse, $paid));
        $this->expectStatus(403, fn () => $this->invoices->show($this->otherSales, $paid));
        self::assertSame($paid, $this->invoices->show($this->sales, $paid)['id'], 'Sales sees invoices of their own orders');
        self::assertSame(0, $this->invoices->search($this->otherSales, [])->total());
        self::assertSame(1, $this->invoices->search($this->admin, ['state' => 'Paid'])->total());
        self::assertSame(1, $this->invoices->search($this->admin, ['state' => 'Unpaid'])->total());
        $this->expectValidation(fn () => $this->invoices->search($this->admin, ['state' => 'Lost']), 'Unknown invoice status');

        $this->invoiceRepository->setToday('2026-12-20'); // 69 days after the invoice date, 39 days overdue
        $aging = $this->invoices->agingReport($this->admin);
        self::assertSame(['Toko Tanpa Daftar', 333000, 333000, 333000, 1], [$aging['rows'][0]['customer_name'], $aging['rows'][0]['61-90'], $aging['rows'][0]['outstanding'], $aging['rows'][0]['overdue'], $aging['rows'][0]['invoices']]);
        self::assertSame(333000, $aging['totals']['outstanding']);
        self::assertStringContainsString('"Toko Tanpa Daftar",0.00,0.00,3330.00,0.00,3330.00,3330.00,1', $this->invoices->agingCsv($this->admin));
        self::assertSame(['outstanding_cents' => 333000, 'overdue_cents' => 333000, 'open_invoices' => 1], $this->invoices->receivablesSummary());
        $sales = $this->invoices->salesByCustomer($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        self::assertSame([2, 3300000, 363000, 3663000], [$sales['totals']['invoices'], $sales['totals']['net'], $sales['totals']['vat'], $sales['totals']['total']]);
        self::assertStringContainsString('TOTAL,2,33000.00,3630.00,36630.00', $this->invoices->salesByCustomerCsv($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-31']));
        $this->expectValidation(fn () => $this->invoices->salesByCustomer($this->admin, ['from' => '2026-10-31', 'to' => '2026-10-01']), 'valid date range');
        $this->expectStatus(403, fn () => $this->invoices->agingReport($this->sales));
        $this->expectStatus(403, fn () => $this->invoices->salesByCustomer($this->warehouse, []));
        self::assertSame(['0-30', '31-60', '61-90', '>90', '>90'], [InvoiceService::bucket(30), InvoiceService::bucket(31), InvoiceService::bucket(90), InvoiceService::bucket(91), InvoiceService::bucket(400)]);
        unset($open);
    }

    public function testCustomerTaxProfileIsNormalizedAndValidatedLoosely(): void
    {
        self::assertSame(['0123456789012345', null, 'PT A', null], \App\Service\CustomerService::taxProfile(['npwp' => '01.234.567.8-901.2345', 'nik' => ' ', 'tax_name' => ' PT A ', 'tax_address' => '']));
        self::assertSame(['012345678901234', '3171234567890123', null, null], \App\Service\CustomerService::taxProfile(['npwp' => '01.234.567.8-901.234', 'nik' => '3171 2345 6789 0123']));
        foreach ([['npwp' => '1234'], ['npwp' => '12345678901234567'], ['npwp' => '01.234.567.8-901.23A'], ['nik' => '123456789012345']] as $invalid) {
            try {
                \App\Service\CustomerService::taxProfile($invalid);
                self::fail('Accepted ' . json_encode($invalid));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    private function salesOrderService(): SalesOrderService
    {
        return new SalesOrderService(
            $this->orders,
            $this->sequences,
            $this->products,
            [1 => $this->customers->findById(1), 2 => $this->customers->findById(2)],
            [1 => new Warehouse(1, 'Gudang Pusat', 'Cikarang', true)],
            new StockService($this->stock, $this->ledger, $this->audit),
            null,
            new OrderLinePricing(),
            new InMemoryOrderExceptionRepository(),
            $this->units,
            $this->resolver,
            $this->deliveryNotes,
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function approvedOrder(array $lines, int $customerId = 1): SalesOrder
    {
        $order = $this->salesOrders->createDraft($this->sales, $customerId, 1, $lines);
        $this->salesOrders->submit($this->sales, $order->id());
        $this->salesOrders->approve($this->admin, $order->id());

        return $this->orders->findById($order->id()) ?? throw new \LogicException('Order missing.');
    }

    /** @param list<array<string, mixed>> $lines */
    private function invoicedNote(array $lines, int $customerId = 2): int
    {
        $order = $this->approvedOrder($lines, $customerId);
        $this->salesOrders->issue($this->warehouse, $order->id());

        return $this->invoices->createFromDeliveryNote($this->admin, $this->notes->forSalesOrder($order->id())[0]['id']);
    }

    /** @return array{0: int, 1: string, 2: int|null} */
    private function resolved(int $customerId, ?ProductUnit $unit, int $quantity): array
    {
        $resolved = $this->resolver->resolve($customerId, $this->products->findById($unit?->productId() ?? 0) ?? throw new \LogicException('Product missing.'), $unit ?? throw new \LogicException('Unit missing.'), $quantity);

        return [$resolved['price_cents'], $resolved['source'], $resolved['min_quantity']];
    }

    private function expectValidation(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('Expected a validation error containing "' . $message . '".');
        } catch (ValidationException|\InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function expectStatus(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('Expected HTTP ' . $status . '.');
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->statusCode());
        }
    }
}
