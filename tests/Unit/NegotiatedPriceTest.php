<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\PurchaseOrderItem;
use App\Entity\SalesOrderItem;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\ValidationException;
use App\Repository\InMemory\InMemoryDocumentSequenceRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Service\OrderLinePricing;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\PriceOverrides;
use App\Validation\OrderItemsInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Negotiated line prices (D-13, ADR-019): master price by default, a different price needs a reason, no below-cost
 * sale, Sales discount cap (Admin uncapped), above master allowed, client list_price never trusted.
 * Product 10: purchase 1000, selling 1500. Default Sales cap 10% → lowest Sales price 1350.
 */
final class NegotiatedPriceTest extends TestCase
{
    private InMemorySalesOrderRepository $salesOrders;
    private InMemoryPurchaseOrderRepository $purchaseOrders;

    public function testPriceEqualToMasterUsesMasterAndDropsTheReason(): void
    {
        foreach (['1500', '1500.00', ' 1500.0 ', 1500, 1500.0] as $price) {
            $line = $this->salesLine($this->admin(), $price, 'Alasan yang tidak diperlukan');
            self::assertSame([1500.0, 1500.0, null], [$line->sellingPrice(), $line->listPrice(), $line->priceReason()], var_export($price, true));
            self::assertFalse($line->isPriceOverridden());
        }
    }

    public function testEmptyOrMissingPriceUsesMaster(): void
    {
        self::assertSame(1500.0, $this->salesLine($this->sales(), '', null)->sellingPrice());
        self::assertSame(1500.0, $this->salesLine($this->sales(), null, null)->sellingPrice());
        self::assertSame(1000.0, $this->purchaseLine($this->admin(), '  ', null)->purchasePrice());
    }

    public function testDifferentPriceWithoutValidReasonIs422AndCreatesNothing(): void
    {
        foreach ([null, '', '   ', 'ab', str_repeat('x', 256), ['array']] as $reason) {
            $this->assertRejected(fn () => $this->salesLine($this->sales(), '1400', $reason), 'reason of 3–255 characters');
            $this->assertRejected(fn () => $this->purchaseLine($this->admin(), '950', $reason), 'reason of 3–255 characters');
        }
        self::assertCount(0, $this->salesOrders->all());
        self::assertCount(0, $this->purchaseOrders->all());
    }

    public function testReasonIsTrimmedAndLimitsAreInclusive(): void
    {
        self::assertSame('abc', $this->salesLine($this->sales(), '1400', '  abc  ')->priceReason());
        self::assertSame(str_repeat('é', 255), $this->salesLine($this->sales(), '1400', str_repeat('é', 255))->priceReason(), '255 multibyte characters fit VARCHAR(255)');
    }

    /** @return array<string, array{0: mixed}> */
    public static function malformedPrices(): array
    {
        return ['negative' => ['-1'], 'three decimals' => ['1400.123'], 'text' => ['abc'], 'exponent' => ['1e3'], 'rupiah text' => ['Rp 1.400,00'], 'too large' => ['1000000000000'], 'array' => [['1400']], 'negative float' => [-5.0], 'bool' => [true]];
    }

    #[DataProvider('malformedPrices')]
    public function testMalformedPriceIs422(mixed $price): void
    {
        $this->assertRejected(fn () => $this->salesLine($this->admin(), $price, 'Harga khusus'), 'non-negative amount with at most 2 decimals');
        $this->assertRejected(fn () => $this->purchaseLine($this->admin(), $price, 'Harga khusus'), 'non-negative amount with at most 2 decimals');
    }

    public function testLargestColumnValueIsAccepted(): void
    {
        self::assertSame(999999999999.99, $this->purchaseLine($this->admin(), '999999999999.99', 'Kontrak khusus')->purchasePrice());
    }

    public function testSellingBelowCurrentPurchasePriceIs422EvenForAdmin(): void
    {
        $this->assertRejected(fn () => $this->salesLine($this->admin(), '999.99', 'Cuci gudang'), 'below its current purchase price');
        self::assertSame(1000.0, $this->salesLine($this->admin(), '1000', 'Harga pokok untuk pelanggan strategis')->sellingPrice(), 'equal to cost is allowed');
    }

    public function testSalesDiscountIsCappedWhileAdminIsNot(): void
    {
        self::assertSame(1350.0, $this->salesLine($this->sales(), '1350', 'Promo akhir bulan')->sellingPrice(), 'exactly 10% below master');
        $this->assertRejected(fn () => $this->salesLine($this->sales(), '1349.99', 'Promo akhir bulan'), 'at most 10% below the master price; the lowest selling price for SKU-001 is Rp 1.350,00');
        self::assertSame(1100.0, $this->salesLine($this->admin(), '1100', 'Persetujuan direksi')->sellingPrice(), 'Admin may discount beyond the Sales cap');
    }

    public function testDiscountCapComesFromConfiguration(): void
    {
        $this->assertRejected(fn () => $this->salesLine($this->sales(), '1499', 'Diskon kecil', new OrderLinePricing(0)), 'at most 0%');
        self::assertSame(1050.0, $this->salesLine($this->sales(), '1050', 'Program loyalitas', new OrderLinePricing(30))->sellingPrice());
        foreach ([-1, 101] as $invalid) {
            try {
                new OrderLinePricing($invalid);
                self::fail('Out-of-range cap must be refused at boot.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('SALES_MAX_DISCOUNT_PERCENT', $exception->getMessage());
            }
        }
    }

    public function testPriceAboveMasterIsAllowedWithReasonForSalesAndAdmin(): void
    {
        $line = $this->salesLine($this->sales(), '1700.50', 'Pengiriman ekspres');
        self::assertSame([1700.5, 1500.0, 'Pengiriman ekspres', true], [$line->sellingPrice(), $line->listPrice(), $line->priceReason(), $line->isPriceOverridden()]);
        self::assertSame(1800.0, $this->salesLine($this->admin(), '1800', 'Kemasan khusus')->sellingPrice());
        $this->assertRejected(fn () => $this->salesLine($this->sales(), '1700', null), 'reason');
    }

    public function testClientListPriceIsNeverTrusted(): void
    {
        $order = $this->salesService()->createDraft($this->sales(), 1, 1, [
            ['product_id' => 10, 'quantity' => 2, 'price' => '1400', 'price_reason' => 'Promo akhir bulan', 'list_price' => 1400.0],
        ]);
        // The server snapshot (1500) is stored, so the line still shows as a 6.67% discount with its reason.
        self::assertSame([1400.0, 1500.0, true], [$order->items()[0]->sellingPrice(), $order->items()[0]->listPrice(), $order->items()[0]->isPriceOverridden()]);

        $parsed = OrderItemsInput::sales(['product_id' => '10', 'quantity' => '2', 'selling_price' => '1400', 'price_reason' => 'x', 'list_price' => '1400']);
        self::assertSame([['product_id' => 10, 'quantity' => 2, 'unit_code' => null, 'price' => '1400', 'price_reason' => 'x']], $parsed, 'list_price is not even parsed');
    }

    public function testPurchaseOrderOverrideByAdminOrWarehouseNeedsPositivePriceAndReason(): void
    {
        $warehouse = new AuthContext(4, 'warehouse@example.test', User::ROLE_WAREHOUSE_STAFF);
        $line = $this->purchaseLine($warehouse, '950', 'Diskon principal');
        self::assertSame([950.0, 1000.0, 'Diskon principal'], [$line->purchasePrice(), $line->listPrice(), $line->priceReason()]);
        self::assertSame(1250.0, $this->purchaseLine($this->admin(), '1250', 'Kenaikan harga principal')->purchasePrice(), 'above master allowed');
        $this->assertRejected(fn () => $this->purchaseLine($this->admin(), '0', 'Barang promosi gratis'), 'greater than zero');
        $this->assertRejected(fn () => $this->purchaseLine($this->admin(), '0.00', 'Barang promosi gratis'), 'greater than zero');
    }

    public function testRejectedPriceDoesNotClaimAnOrderNumber(): void
    {
        $sequences = new InMemoryDocumentSequenceRepository();
        $service = $this->salesService(null, $sequences);
        $this->assertRejected(fn () => $service->createDraft($this->sales(), 1, 1, [['product_id' => 10, 'quantity' => 1, 'price' => '1000', 'price_reason' => 'Terlalu murah']]), 'at most 10%');
        $order = $service->createDraft($this->sales(), 1, 1, [['product_id' => 10, 'quantity' => 1]]);
        self::assertStringEndsWith('-0001', $order->orderNumber());
    }

    public function testOrderItemsInputReadsTheFormPriceFieldPerOrderType(): void
    {
        $post = ['product_id' => '10', 'quantity' => '1', 'purchase_price' => '950', 'price_reason' => 'Diskon', 'items' => [2 => ['product_id' => '11', 'quantity' => '3']]];
        self::assertSame([
            ['product_id' => 10, 'quantity' => 1, 'unit_code' => null, 'price' => '950', 'price_reason' => 'Diskon'],
            ['product_id' => 11, 'quantity' => 3, 'unit_code' => null, 'price' => null, 'price_reason' => null],
        ], OrderItemsInput::purchase($post));
        self::assertNull(OrderItemsInput::sales($post)[0]['price'], 'a PO price field is not a SO price');
        $this->assertRejected(fn () => OrderItemsInput::purchase(['product_id' => '10', 'quantity' => '1', 'purchase_price' => ['950']]), 'Price must be text');
    }

    public function testOverrideSummaryAndLabelsUseWholeCents(): void
    {
        $discount = new SalesOrderItem(1, 1, 10, 2, 1400.0, 1500.0, 'Promo akhir bulan');
        $surcharge = new PurchaseOrderItem(2, 1, 11, 3, 0, 262.5, 250.0, 'Harga naik');
        $master = new SalesOrderItem(3, 1, 12, 5, 90.0, 90.0, null);
        $legacy = new SalesOrderItem(4, 1, 13, 1, 90.0);

        self::assertSame('-6,67%', PriceOverrides::percentLabel($discount));
        self::assertSame('+5,00%', PriceOverrides::percentLabel($surcharge));
        self::assertSame('-Rp 100,00', PriceOverrides::signedRupiah(PriceOverrides::unitDifference($discount)));
        self::assertSame('+Rp 37,50', PriceOverrides::signedRupiah(PriceOverrides::lineDifference($surcharge)));
        self::assertSame(['lines' => 2, 'total' => -162.5], PriceOverrides::summary([$discount, $surcharge, $master, $legacy]));
        self::assertFalse($master->isPriceOverridden());
        self::assertSame(90.0, $legacy->listPrice(), 'a line without snapshot is its own list price');
        self::assertSame('n/a', PriceOverrides::percentLabel(new SalesOrderItem(5, 1, 14, 1, 5.0, 0.0, 'Biaya')));
        self::assertSame(250.0, $surcharge->withReceivedQuantity(1)->listPrice(), 'receipt keeps the price snapshot');
    }

    private function assertRejected(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('Expected a 422 validation failure: ' . $message);
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->statusCode());
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function salesLine(AuthContext $actor, mixed $price, mixed $reason, ?OrderLinePricing $pricing = null): SalesOrderItem
    {
        return $this->salesService($pricing)->createDraft($actor, 1, 1, [['product_id' => 10, 'quantity' => 1, 'price' => $price, 'price_reason' => $reason]])->items()[0];
    }

    private function purchaseLine(AuthContext $actor, mixed $price, mixed $reason): PurchaseOrderItem
    {
        $this->purchaseOrders ??= new InMemoryPurchaseOrderRepository();
        $service = new PurchaseOrderService(
            $this->purchaseOrders,
            new InMemoryDocumentSequenceRepository(),
            $this->products(),
            [1 => new Supplier(1, 'Demo Supplier', '', '', '', true)],
            [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)],
            new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository()),
        );

        return $service->createDraft($actor, 1, 1, [['product_id' => 10, 'quantity' => 1, 'price' => $price, 'price_reason' => $reason]])->items()[0];
    }

    private function salesService(?OrderLinePricing $pricing = null, ?InMemoryDocumentSequenceRepository $sequences = null): SalesOrderService
    {
        $this->salesOrders ??= new InMemorySalesOrderRepository();

        return new SalesOrderService(
            $this->salesOrders,
            $sequences ?? new InMemoryDocumentSequenceRepository(),
            $this->products(),
            [1 => new Customer(1, 'Demo Customer', '', '', '', true)],
            [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)],
            new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository()),
            null,
            $pricing ?? new OrderLinePricing(),
        );
    }

    private function products(): InMemoryProductRepository
    {
        return new InMemoryProductRepository([new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true)]);
    }

    private function admin(): AuthContext
    {
        return new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
    }

    private function sales(): AuthContext
    {
        return new AuthContext(7, 'sales@example.test', User::ROLE_SALES);
    }

    protected function setUp(): void
    {
        $this->salesOrders = new InMemorySalesOrderRepository();
        $this->purchaseOrders = new InMemoryPurchaseOrderRepository();
    }
}
