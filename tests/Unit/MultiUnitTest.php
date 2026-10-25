<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\ProductController;
use App\Controller\PurchaseOrderController;
use App\Controller\SalesOrderController;
use App\Entity\Category;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\ProductUnit;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryBusinessOperationRepository;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Repository\InMemory\InMemoryDocumentSequenceRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryProductUnitRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\BusinessOperationService;
use App\Service\MasterDataAuthorizationService;
use App\Service\OrderLinePricing;
use App\Service\ProductService;
use App\Service\ProductUnitCatalog;
use App\Service\ProductUnitService;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\QuantityFormatter;
use App\Support\UnitSet;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImmediateTransactions;

/** D-16 / ADR-022: units of measure; stock in base units, conversion only at input and display. */
final class MultiUnitTest extends TestCase
{
    private InMemoryProductRepository $products;
    private InMemoryProductUnitRepository $units;
    private ProductUnitCatalog $catalog;
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryAuditLogRepository $audit;
    private AuthContext $admin;
    private AuthContext $sales;
    private AuthContext $warehouse;

    protected function setUp(): void
    {
        // Product 10: base pcs at Rp 2.000 / Rp 2.600, karton = 48 pcs with own prices, box = 12 pcs priced base × 12.
        // Product 11: no stored units (only its base unit "botol").
        $this->products = new InMemoryProductRepository([
            new Product(10, 'MIE-0001', 'Mi Goreng (48 pcs)', 'pcs', 2000.0, 2600.0, 96, 1, true),
            new Product(11, 'MIN-0001', 'Air Mineral', 'botol', 3000.0, 4000.0, 10, 1, true),
        ]);
        $this->units = new InMemoryProductUnitRepository([
            new ProductUnit(1, 10, 'pcs', 1, '8991000202612', null, null, true),
            new ProductUnit(2, 10, 'karton', 48, null, 95000.0, 120000.0, false, true, true),
            new ProductUnit(3, 10, 'box', 12),
        ]);
        $this->catalog = new ProductUnitCatalog($this->products, $this->units);
        $this->stock = new InMemoryStockRepository();
        $this->stock->seed(10, 1, 200);
        $this->stock->seed(11, 1, 50);
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->admin = new AuthContext(1, 'admin@test', User::ROLE_ADMIN);
        $this->sales = new AuthContext(2, 'sales@test', User::ROLE_SALES);
        $this->warehouse = new AuthContext(4, 'warehouse@test', User::ROLE_WAREHOUSE_STAFF);
    }

    public function testConversionToBaseAndRoundingUpToWholeUnits(): void
    {
        self::assertSame(96, UnitSet::toBase(2, 48));
        self::assertSame(7, UnitSet::toBase(7, 1));
        self::assertSame(3, UnitSet::unitsCovering(130, 48), '130 pcs need 3 karton');
        self::assertSame(2, UnitSet::unitsCovering(96, 48), 'an exact multiple is not rounded up');
        self::assertSame(0, UnitSet::unitsCovering(0, 48));
        self::assertSame(5, UnitSet::unitsCovering(5, 1));
        $this->expectException(ValidationException::class);
        UnitSet::toBase(100000000, 48);
    }

    public function testFormatterShowsBreakdownAndBaseTotal(): void
    {
        $set = $this->catalog->forProduct($this->product(10));
        self::assertSame('2 karton + 5 pcs (101 pcs)', QuantityFormatter::format(101, $set));
        self::assertSame('2 karton (96 pcs)', $set->format(96));
        self::assertSame('2 karton + 2 box + 5 pcs (125 pcs)', $set->format(125));
        self::assertSame('1 box (12 pcs)', $set->format(12));
        self::assertSame('5 pcs', $set->format(5));
        self::assertSame('0 pcs', $set->format(0));
        self::assertSame('-1 karton (-48 pcs)', $set->format(-48), 'signed adjustments keep the sign');
        self::assertSame('20 karton + 10 pcs (970 pcs)', $set->format(970));
        self::assertSame('1.200 botol', $this->catalog->forProduct($this->product(11))->format(1200), 'base-only products show the base total');
        // An inactive unit is not used for the breakdown.
        $inactive = new UnitSet([new ProductUnit(1, 10, 'pcs', 1, null, null, null, true), new ProductUnit(2, 10, 'karton', 48, null, null, null, false, false, false, false)]);
        self::assertSame('101 pcs', $inactive->format(101));
    }

    public function testResolveUsesBaseForEmptyAndRefusesUnknownOrInactiveUnits(): void
    {
        $set = $this->catalog->forProduct($this->product(10));
        self::assertSame('pcs', $set->resolve(null, 'MIE-0001')->code());
        self::assertSame('pcs', $set->resolve('  ', 'MIE-0001')->code());
        self::assertSame(48, $set->resolve('KARTON', 'MIE-0001')->factor(), 'codes compare case-insensitively like the column collation');
        self::assertSame('karton', $set->purchaseDefault()->code());
        self::assertSame('karton', $set->salesDefault()->code());
        self::assertSame('botol', $this->catalog->forProduct($this->product(11))->purchaseDefault()->code(), 'no default: the base unit');
        $this->assertRejected(fn () => $set->resolve('lusin', 'MIE-0001'), 'not an active unit of MIE-0001');
        $this->unitService()->update($this->admin, 10, 3, ['is_active' => '0']);
        $this->assertRejected(fn () => (new ProductUnitCatalog($this->products, $this->units))->forProduct($this->product(10))->resolve('box', 'MIE-0001'), 'not an active unit');
    }

    public function testUnitPricesUseOwnPriceElseBaseTimesFactorAndCostIsBaseTimesFactor(): void
    {
        $product = $this->product(10);
        $set = $this->catalog->forProduct($product);
        self::assertSame([95000.0, 120000.0], [$set->find('karton')?->unitPurchasePrice($product), $set->find('karton')?->unitSellingPrice($product)]);
        self::assertSame([24000.0, 31200.0], [$set->find('box')?->unitPurchasePrice($product), $set->find('box')?->unitSellingPrice($product)]);
        self::assertSame(9600000, $set->find('karton')?->costCents($product), 'cost floor = base purchase × factor, not the karton purchase price');
        $describe = $set->describe($product);
        self::assertSame(['karton', 'box', 'pcs'], array_column($describe, 'code'));
        self::assertSame('120000.00', $describe[0]['selling_price']);
    }

    public function testSalesLinePricingPerUnitWithDiscountCapAndCostFloor(): void
    {
        $pricing = new OrderLinePricing(10);
        $product = $this->product(10);
        $karton = $this->catalog->forProduct($product)->find('karton') ?? self::fail('unit');
        self::assertEquals(['price' => 120000.0, 'list_price' => 120000.0, 'price_reason' => null], $pricing->salesLine($this->sales, $product, '', null, $karton));
        self::assertEquals(108000.0, $pricing->salesLine($this->sales, $product, '108000', 'Volume order', $karton)['price'], 'exactly 10% below the karton master price');
        $this->assertRejected(fn () => $pricing->salesLine($this->sales, $product, '107999.99', 'Volume order', $karton), 'at most 10%');
        // Admin has no cap but never sells a karton below 48 × Rp 2.000 = Rp 96.000.
        self::assertEquals(96000.0, $pricing->salesLine($this->admin, $product, '96000', 'Cuci gudang', $karton)['price']);
        $this->assertRejected(fn () => $pricing->salesLine($this->admin, $product, '95999.99', 'Cuci gudang', $karton), 'below its current purchase price');
        // The same rules per piece.
        $this->assertRejected(fn () => $pricing->salesLine($this->sales, $product, '2339', 'Promo', null), 'at most 10%');
        self::assertEquals(['price' => 24000.0, 'list_price' => 24000.0, 'price_reason' => null], $pricing->purchaseLine($product, null, null, $this->catalog->forProduct($product)->find('box')));
    }

    public function testOrdersStoreBaseQuantityUnitSnapshotAndPricePerUnit(): void
    {
        $po = $this->purchaseService()->createDraft($this->warehouse, 1, 1, [
            ['product_id' => 10, 'quantity' => 3, 'unit_code' => 'karton', 'price' => '93000', 'price_reason' => 'Diskon principal'],
            ['product_id' => 11, 'quantity' => 24],
        ]);
        [$karton, $botol] = $po->items();
        self::assertSame([144, 3, 'karton', 48, 93000.0, 95000.0, 279000.0], [$karton->quantity(), $karton->unitQuantity(), $karton->unitCode(), $karton->unitFactor(), $karton->purchasePrice(), $karton->listPrice(), $karton->lineTotal()]);
        self::assertSame([24, 24, 'botol', 1, 3000.0, 72000.0], [$botol->quantity(), $botol->unitQuantity(), $botol->unitCode(), $botol->unitFactor(), $botol->purchasePrice(), $botol->lineTotal()]);
        self::assertEquals(['lines' => 1, 'total' => -6000.0], \App\Support\PriceOverrides::summary($po->items()), 'difference = (93.000 − 95.000) × 3 karton');

        $so = $this->salesService()->createDraft($this->sales, 1, 1, [
            ['product_id' => 10, 'quantity' => 2, 'unit_code' => 'box'],
            ['product_id' => 11, 'quantity' => 5, 'unit_code' => 'botol'],
        ]);
        self::assertSame([24, 2, 'box', 31200.0, 62400.0], [$so->items()[0]->quantity(), $so->items()[0]->unitQuantity(), $so->items()[0]->unitCode(), $so->items()[0]->sellingPrice(), $so->items()[0]->lineTotal()]);
        $this->assertRejected(fn () => $this->salesService()->createDraft($this->sales, 1, 1, [['product_id' => 10, 'quantity' => 1, 'unit_code' => 'palet']]), 'not an active unit');
    }

    public function testPartialReceiptAndIssueAreEnteredInLineUnitsAndStoredInBase(): void
    {
        $purchase = $this->purchaseService();
        $po = $purchase->createDraft($this->warehouse, 1, 1, [['product_id' => 10, 'quantity' => 3, 'unit_code' => 'karton']]);
        $purchase->markOrdered($this->admin, $po->id());
        $item = $po->items()[0];
        $purchase->receive($this->warehouse, $po->id(), [$item->id() => 1]);
        self::assertSame(248, $this->stock->quantity(10, 1), '200 + 1 karton (48 pcs)');
        $this->assertRejected(fn () => $purchase->receive($this->warehouse, $po->id(), [$item->id() => 3]), 'cannot exceed remaining');
        $purchase->receive($this->warehouse, $po->id(), [$item->id() => 2]);
        self::assertSame(344, $this->stock->quantity(10, 1));
        self::assertSame([48, 96], array_map(static fn ($entry): int => $entry->quantity(), array_values(array_filter($this->ledger->entries(), static fn ($entry): bool => $entry->movementType() === 'Receipt'))));

        $sales = $this->salesService();
        $so = $sales->createDraft($this->sales, 1, 1, [['product_id' => 10, 'quantity' => 4, 'unit_code' => 'box']]);
        $sales->submit($this->sales, $so->id());
        $sales->approve($this->admin, $so->id());
        self::assertSame(48, $this->stock->reservedQuantity(10, 1), 'reservation is in base units');
        $line = $so->items()[0];
        $sales->issue($this->warehouse, $so->id(), null, [$line->id() => 1]);
        self::assertSame([332, 36], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)], 'one box = 12 pcs left stock and the reservation');
        $this->assertRejected(fn () => $sales->issue($this->warehouse, $so->id(), null, [$line->id() => 4]), '3 left on line');
        $sales->issue($this->warehouse, $so->id());
        self::assertSame([296, 0], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)], 'the rest of the remaining boxes (36 pcs)');
    }

    public function testStockOperationLinesAcceptAUnitAndStoreBase(): void
    {
        $pairs = ['10:1' => ['quantity' => 200, 'reserved_quantity' => 0], '10:2' => ['quantity' => 0, 'reserved_quantity' => 0], '11:1' => ['quantity' => 50, 'reserved_quantity' => 0]];
        $operations = new InMemoryBusinessOperationRepository($pairs);
        $service = new BusinessOperationService($operations, new StockService($this->stock, $this->ledger, $this->audit), $this->audit, $this->catalog, $this->products);
        $id = $service->propose($this->warehouse, 'Transfer', 1, 2, 'Rebalance', [['product_id' => 10, 'quantity' => 2, 'unit_code' => 'karton']]);
        $line = $operations->find($id)['items'][0] ?? self::fail('line');
        self::assertSame([96, 'karton', 48, 2], [$line['quantity'], $line['unit_code'], $line['unit_factor'], $line['unit_quantity']]);
        $count = $service->propose($this->warehouse, 'Adjustment', 1, null, 'Stock count', [['product_id' => 11, 'quantity' => 48, 'unit_code' => 'botol', 'baseline' => '50']]);
        self::assertSame(48, $operations->find($count)['items'][0]['quantity']);
        $this->assertRejected(fn () => $service->propose($this->warehouse, 'Transfer', 1, 2, 'Rebalance', [['product_id' => 10, 'quantity' => 1, 'unit_code' => 'peti']]), 'not an active unit');
    }

    public function testAdminManagesUnitsWithValidationDefaultsAndAudit(): void
    {
        $service = $this->unitService();
        $lusin = $service->add($this->admin, 10, ['unit_code' => 'lusin', 'factor_to_base' => '12', 'barcode' => '8991000202629', 'selling_price' => '30000', 'is_sales_default' => '1']);
        self::assertSame(['lusin', 12, 30000.0, true], [$lusin->code(), $lusin->factor(), $lusin->sellingPrice(), $lusin->isSalesDefault()]);
        self::assertFalse($this->units->find(2)?->isSalesDefault(), 'at most one sales default: karton lost it');
        self::assertTrue($this->units->find(2)?->isPurchaseDefault(), 'the purchase default is untouched');
        self::assertSame('products.units', $this->audit->entries()[0]['action']);
        self::assertSame(['add', 'lusin', 12], [$this->audit->entries()[0]['metadata']['change'], $this->audit->entries()[0]['metadata']['unit_code'], $this->audit->entries()[0]['metadata']['factor_to_base']]);

        $this->assertRejected(fn () => $service->add($this->admin, 10, ['unit_code' => 'karton', 'factor_to_base' => '24']), 'already exists');
        $this->assertRejected(fn () => $service->add($this->admin, 10, ['unit_code' => 'PCS', 'factor_to_base' => '2']), 'already the base unit');
        $this->assertRejected(fn () => $service->add($this->admin, 10, ['unit_code' => 'pak', 'factor_to_base' => '1']), 'from 2 to');
        $this->assertRejected(fn () => $service->add($this->admin, 10, ['unit_code' => 'pak', 'factor_to_base' => '2.5']), 'from 2 to');
        $this->assertRejected(fn () => $service->add($this->admin, 10, ['unit_code' => '', 'factor_to_base' => '6']), 'Unit code');
        $this->assertRejected(fn () => $service->add($this->admin, 10, ['unit_code' => 'pak', 'factor_to_base' => '6', 'barcode' => '8991000202615']), 'check digit');
        $this->assertRejected(fn () => $service->add($this->admin, 10, ['unit_code' => 'pak', 'factor_to_base' => '6', 'selling_price' => '-1']), 'per unit');
        $this->assertRejected(fn () => $service->add($this->admin, 10, ['unit_code' => 'pak', 'factor_to_base' => '6', 'barcode' => '8991000202612']), 'unique value');
        self::assertSame(2, \App\Service\ProductUnitService::ean13CheckDigit('899100020261'));

        // Deactivating clears the defaults; the base unit cannot be deactivated.
        $off = $service->update($this->admin, 10, $lusin->id(), ['is_active' => '0', 'is_sales_default' => '1']);
        self::assertSame([false, false], [$off->isActive(), $off->isSalesDefault()]);
        $this->assertRejected(fn () => $service->update($this->admin, 10, 1, ['is_active' => '0']), 'base unit cannot be deactivated');
        $base = $service->update($this->admin, 10, 1, ['is_purchase_default' => '1', 'barcode' => '8991000202612']);
        self::assertSame(['pcs', 1, null, true], [$base->code(), $base->factor(), $base->purchasePrice(), $base->isPurchaseDefault()]);
        self::assertFalse($this->units->find(2)?->isPurchaseDefault());
    }

    public function testFactorAndCodeAreImmutableOnceAUnitIsUsed(): void
    {
        $service = $this->unitService();
        // Unused: code and factor may still be corrected.
        $box = $service->update($this->admin, 10, 3, ['unit_code' => 'box', 'factor_to_base' => '6']);
        self::assertSame(6, $box->factor());
        $this->units->markUsed(10, 'box');
        $this->assertRejected(fn () => $service->update($this->admin, 10, 3, ['unit_code' => 'box', 'factor_to_base' => '12']), 'Deactivate it and add a new unit');
        $this->assertRejected(fn () => $service->update($this->admin, 10, 3, ['unit_code' => 'dus', 'factor_to_base' => '6']), 'cannot change');
        $kept = $service->update($this->admin, 10, 3, ['unit_code' => 'box', 'factor_to_base' => '6', 'barcode' => '8991000202636', 'is_active' => '0']);
        self::assertSame([6, '8991000202636', false], [$kept->factor(), $kept->barcode(), $kept->isActive()], 'barcode, prices and status may still change');
        self::assertSame(12, $service->add($this->admin, 10, ['unit_code' => 'box12', 'factor_to_base' => '12'])->factor(), 'the replacement is a new unit');
    }

    public function testOnlyAdminManagesUnitsAndForbiddenRequestsChangeNothing(): void
    {
        foreach ([$this->sales, $this->warehouse] as $actor) {
            try {
                $this->unitService()->add($actor, 10, ['unit_code' => 'pak', 'factor_to_base' => '6']);
                self::fail('Only Admin may manage units.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->statusCode());
            }
            $response = null;
            try {
                $this->productController($actor)->addUnit(new Request('POST', '/products/units', [], ['id' => '10', 'unit_code' => 'pak', 'factor_to_base' => '6'], []));
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->statusCode());
            }
            self::assertNull($response);
        }
        self::assertCount(3, $this->units->forProduct(10));
        self::assertSame([], $this->audit->entries());
        $this->expectException(HttpException::class);
        $this->unitService()->add($this->admin, 999, ['unit_code' => 'pak', 'factor_to_base' => '6']);
    }

    public function testProductPageShowsUnitsBreakdownAndAdminForms(): void
    {
        $admin = $this->productController($this->admin)->show(new Request('GET', '/products/show', ['id' => '10'], [], []))->body();
        self::assertStringContainsString('Units of Measure', $admin);
        self::assertStringContainsString('1 karton = 48 pcs', $admin);
        self::assertStringContainsString('Rp 24.000,00 (base × factor)', $admin, 'box price follows base × factor');
        self::assertStringContainsString('action="/products/units"', $admin);
        self::assertStringContainsString('action="/products/units/update"', $admin);
        self::assertStringContainsString('<dd>2 karton (96 pcs)</dd>', $admin, 'default reorder point 96 pcs shown with the breakdown');

        $sales = $this->productController($this->sales)->show(new Request('GET', '/products/show', ['id' => '10'], [], []))->body();
        self::assertStringContainsString('1 karton = 48 pcs', $sales);
        self::assertStringNotContainsString('action="/products/units', $sales, 'only Admin sees the unit forms');

        $refused = $this->productController($this->admin)->addUnit(new Request('POST', '/products/units', [], ['id' => '10', 'unit_code' => 'karton', 'factor_to_base' => '24'], []));
        self::assertSame(422, $refused->statusCode());
        self::assertStringContainsString('already exists', $refused->body());
        $added = $this->productController($this->admin)->addUnit(new Request('POST', '/products/units', [], ['id' => '10', 'unit_code' => 'pak', 'factor_to_base' => '6'], []));
        self::assertSame([302, '/products/show?id=10'], [$added->statusCode(), $added->headers()['Location'] ?? '']);
    }

    public function testOrderFormsOfferUnitsWithPerUnitMasterPricesAndReplenishmentPrefill(): void
    {
        $guard = $this->guard($this->warehouse);
        $controller = new PurchaseOrderController($this->purchaseService(), new InMemoryPurchaseOrderRepository(), $this->products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], $guard, null, null, $this->catalog);
        $body = $controller->create(new Request('GET', '/purchase-orders/create', ['warehouse_id' => '1', 'pick' => ['10:3:karton', '11:24']], [], []))->body();
        self::assertMatchesRegularExpression('/name="unit_code"[^>]*>\s*<option value="karton" data-factor="48" data-price="95000.00" selected>karton \(48 pcs\)<\/option>/', $body);
        self::assertStringContainsString('name="quantity" type="number" min="1" required value="3"', $body);
        self::assertStringContainsString('name="purchase_price" type="number" min="0" step="0.01" inputmode="decimal" value="95000.00"', $body, 'prefilled with the karton master price');
        self::assertMatchesRegularExpression('/name="items\[1\]\[unit_code\]"[^>]*>\s*<option value="botol" data-factor="1" data-price="3000.00" selected>/', $body);
        self::assertStringContainsString('data-default-unit="karton"', $body);
        self::assertStringContainsString('&quot;code&quot;:&quot;box&quot;,&quot;factor&quot;:12', $body);

        $salesController = new SalesOrderController($this->salesService(), new InMemorySalesOrderRepository(), $this->products, [1 => new Customer(1, 'Customer', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], $this->guard($this->sales), null, null, $this->catalog);
        $form = $salesController->create()->body();
        // Product 10's option carries its sales default unit and per-unit selling prices for the line script.
        self::assertMatchesRegularExpression('/<option value="10" data-price="2600.00"\s+data-base-unit="pcs" data-default-unit="karton" data-units="[^"]*&quot;code&quot;:&quot;karton&quot;,&quot;factor&quot;:48,&quot;base&quot;:false,&quot;purchase_price&quot;:&quot;95000.00&quot;,&quot;selling_price&quot;:&quot;120000.00&quot;/', $form);
        self::assertStringContainsString('Master price: <span data-order-master>Rp 4.000,00</span><span data-order-master-unit> per botol</span>', $form, 'the first product (base unit only) is preselected');
    }

    public function testOrderDetailShowsLineUnitsTotalsAndIssueInputsInLineUnits(): void
    {
        $sales = $this->salesService();
        $orders = (new \ReflectionProperty(SalesOrderService::class, 'orders'))->getValue($sales);
        self::assertInstanceOf(InMemorySalesOrderRepository::class, $orders);
        $so = $sales->createDraft($this->sales, 1, 1, [['product_id' => 10, 'quantity' => 3, 'unit_code' => 'karton']]);
        $sales->submit($this->sales, $so->id());
        $sales->approve($this->admin, $so->id());
        $controller = new SalesOrderController($sales, $orders, $this->products, [1 => new Customer(1, 'Customer', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], $this->guard($this->warehouse), null, null, $this->catalog);
        $body = $controller->show(new Request('GET', '/sales-orders/show', ['id' => (string) $so->id()], [], []))->body();
        self::assertStringContainsString('<td data-line-quantity>3 karton (144 pcs)</td>', $body);
        self::assertStringContainsString('<td data-line-total>Rp 360.000,00</td>', $body);
        self::assertStringContainsString('Rp 120.000,00 <small>per karton</small>', $body);
        self::assertMatchesRegularExpression('/name="quantities\[\d+\]" type="number" min="0" max="3" step="1" value="3"/', $body, 'issue quantities are whole karton');
    }

    private function product(int $id): Product
    {
        return $this->products->findById($id) ?? self::fail('product');
    }

    private function unitService(): ProductUnitService
    {
        return new ProductUnitService($this->products, $this->units, new MasterDataAuthorizationService(), $this->audit, new ImmediateTransactions());
    }

    private function purchaseService(): PurchaseOrderService
    {
        return new PurchaseOrderService(new InMemoryPurchaseOrderRepository(), new InMemoryDocumentSequenceRepository(), $this->products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], new StockService($this->stock, $this->ledger, $this->audit), null, null, new OrderLinePricing(), $this->catalog);
    }

    private function salesService(): SalesOrderService
    {
        return new SalesOrderService(new InMemorySalesOrderRepository(), new InMemoryDocumentSequenceRepository(), $this->products, [1 => new Customer(1, 'Customer', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], new StockService($this->stock, $this->ledger, $this->audit), null, new OrderLinePricing(), null, $this->catalog);
    }

    private function guard(AuthContext $actor): AuthGuard
    {
        $session = new SessionManager();
        $session->login($actor);

        return new AuthGuard($session);
    }

    private function productController(AuthContext $actor): ProductController
    {
        return new ProductController(
            new ProductService($this->products, new MasterDataAuthorizationService()),
            $this->products,
            new InMemoryCategoryRepository([new Category(1, 'Mi Instan', 'Mi', true)]),
            $this->guard($actor),
            null,
            null,
            $this->unitService(),
            new ProductUnitCatalog($this->products, $this->units),
        );
    }

    private function assertRejected(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('Expected a 422 containing: ' . $message);
        } catch (ValidationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
