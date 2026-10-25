<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\PurchaseOrderController;
use App\Controller\SalesOrderController;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryDocumentSequenceRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\OrderLinePricing;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use PHPUnit\Framework\TestCase;

/** Negotiated line prices in the PO/SO forms and detail pages (D-13, ADR-019). */
final class NegotiatedPriceControllerTest extends TestCase
{
    public function testSalesFormHasEditableMasterPrefilledPriceResetAndReason(): void
    {
        $body = $this->sales($this->session(7, User::ROLE_SALES), new InMemorySalesOrderRepository())->create()->body();

        self::assertMatchesRegularExpression('/name="selling_price" type="number" min="0" step="0.01" inputmode="decimal" value="1500.00"/', $body);
        self::assertStringContainsString('<option value="10" data-price="1500.00"', $body, 'data-price keeps the master value');
        self::assertStringContainsString('name="price_reason"', $body);
        self::assertStringContainsString('data-order-price-reset>Use master price</button>', $body);
        self::assertStringContainsString('Master price: <span data-order-master>Rp 1.500,00</span>', $body);
        self::assertStringContainsString('Sales may discount at most 15% below master', $body, 'the configured cap is shown');
        self::assertStringNotContainsString('readonly', $body);
        self::assertStringNotContainsString('list_price', $body, 'the master snapshot is never a form field');
        self::assertSame(1, substr_count($body, 'name="selling_price"'), 'the clone template stays unnamed');
        self::assertSame(1, substr_count($body, 'name="price_reason"'));
    }

    public function testMissingReasonIs422AndKeepsTypedPriceWithRequiredReason(): void
    {
        $orders = new InMemorySalesOrderRepository();
        $response = $this->sales($this->session(7, User::ROLE_SALES), $orders)->store(new Request('POST', '/sales-orders', [], [
            'customer_id' => '1', 'warehouse_id' => '1', 'product_id' => '10', 'quantity' => '2', 'selling_price' => '1400', 'price_reason' => ' ',
        ], []));

        self::assertSame(422, $response->statusCode());
        self::assertCount(0, $orders->all());
        self::assertStringContainsString('A reason of 3–255 characters is required when the price of SKU-001 differs from the master price.', $response->body());
        self::assertMatchesRegularExpression('/name="selling_price" type="number"[^>]*value="1400"/', $response->body());
        self::assertMatchesRegularExpression('/<label class="field" data-order-reason-field><span class="field-label">Reason for price change<\/span><input[^>]*name="price_reason"[^>]*required minlength="3"/', $response->body());
    }

    public function testSalesOrderDetailShowsOverrideAndApprovalNotice(): void
    {
        $orders = new InMemorySalesOrderRepository();
        $sales = $this->sales($this->session(7, User::ROLE_SALES), $orders);
        $sales->store(new Request('POST', '/sales-orders', [], [
            'customer_id' => '1', 'warehouse_id' => '1', 'product_id' => '10', 'quantity' => '2', 'selling_price' => '1400', 'price_reason' => 'Promo akhir bulan',
            'items' => [1 => ['product_id' => '11', 'quantity' => '1', 'selling_price' => '90.00', 'price_reason' => 'ignored when equal']],
        ], []));
        $order = array_values($orders->all())[0];
        self::assertNull($order->items()[1]->priceReason(), 'equal to master keeps no reason');
        $sales->submit(new Request('POST', '/sales-orders/submit', [], ['id' => (string) $order->id()], []));

        $admin = $this->sales($this->session(1, User::ROLE_ADMIN), $orders)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('<th scope="col">Master Price</th><th scope="col">Difference vs Master</th><th scope="col">Price Reason</th>', $admin);
        // D-16/ADR-022: the line price is per the line's unit, named next to it.
        self::assertMatchesRegularExpression('/Rp 1\.400,00 <small>per [^<]+<\/small> <span class="status-badge status-warning">Negotiated<\/span>/', $admin);
        self::assertStringContainsString('<td>Rp 1.500,00</td>', $admin);
        self::assertStringContainsString('-6,67% · -Rp 100,00 per unit · -Rp 200,00 line', $admin);
        self::assertStringContainsString('Promo akhir bulan', $admin);
        self::assertStringContainsString('<td>Master price</td>', $admin, 'the master-priced line is labelled');
        self::assertMatchesRegularExpression('/data-price-override-notice>1 line priced away from master; total difference -Rp 200,00\..*?action="\/sales-orders\/approve"/s', $admin, 'the notice sits above the Approve button');

        $owner = $this->sales($this->session(7, User::ROLE_SALES), $orders)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('-6,67% · -Rp 100,00 per unit', $owner, 'the Sales owner sees the override too');
    }

    public function testSalesCannotSeeAnotherUsersOverriddenOrder(): void
    {
        $orders = new InMemorySalesOrderRepository();
        $this->sales($this->session(8, User::ROLE_SALES), $orders)->store(new Request('POST', '/sales-orders', [], [
            'customer_id' => '1', 'warehouse_id' => '1', 'product_id' => '10', 'quantity' => '1', 'selling_price' => '1600', 'price_reason' => 'Pengiriman ekspres',
        ], []));
        $order = array_values($orders->all())[0];

        $this->expectException(HttpException::class);
        $this->sales($this->session(7, User::ROLE_SALES), $orders)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []));
    }

    public function testMasterPricedSalesOrderKeepsTheOriginalTableAndNoNotice(): void
    {
        $orders = new InMemorySalesOrderRepository();
        $sales = $this->sales($this->session(7, User::ROLE_SALES), $orders);
        $sales->store(new Request('POST', '/sales-orders', [], ['customer_id' => '1', 'warehouse_id' => '1', 'product_id' => '10', 'quantity' => '1'], []));
        $order = array_values($orders->all())[0];
        $sales->submit(new Request('POST', '/sales-orders/submit', [], ['id' => (string) $order->id()], []));
        $body = $this->sales($this->session(1, User::ROLE_ADMIN), $orders)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();

        self::assertStringNotContainsString('Master Price', $body);
        self::assertStringNotContainsString('data-price-override-notice', $body);
    }

    public function testPurchaseOrderDetailShowsNegotiatedPriceAndDraftNotice(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $controller = $this->purchase($this->session(4, User::ROLE_WAREHOUSE_STAFF), $orders);
        $response = $controller->store(new Request('POST', '/purchase-orders', [], [
            'supplier_id' => '1', 'warehouse_id' => '1', 'product_id' => '10', 'quantity' => '10', 'purchase_price' => '950', 'price_reason' => 'Diskon principal',
        ], []));
        self::assertSame(302, $response->statusCode());
        $order = array_values($orders->all())[0];

        $body = $this->purchase($this->session(1, User::ROLE_ADMIN), $orders)->show(new Request('GET', '/purchase-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertMatchesRegularExpression('/Rp 950,00 <small>per [^<]+<\/small> <span class="status-badge status-warning">Negotiated<\/span>/', $body);
        self::assertStringContainsString('-5,00% · -Rp 50,00 per unit · -Rp 500,00 line', $body);
        self::assertStringContainsString('Diskon principal', $body);
        self::assertMatchesRegularExpression('/data-price-override-notice>1 line priced away from master; total difference -Rp 500,00\..*?action="\/purchase-orders\/order"/s', $body);

        $form = $controller->create(new Request('GET', '/purchase-orders/create', [], [], []))->body();
        self::assertMatchesRegularExpression('/name="purchase_price" type="number" min="0" step="0.01" inputmode="decimal" value="1000.00"/', $form);
        self::assertStringContainsString('A negotiated supplier price must be greater than zero and needs a reason.', $form);
    }

    public function testPurchaseOrderWithoutReasonIs422(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $response = $this->purchase($this->session(1, User::ROLE_ADMIN), $orders)->store(new Request('POST', '/purchase-orders', [], [
            'supplier_id' => '1', 'warehouse_id' => '1', 'product_id' => '10', 'quantity' => '1', 'purchase_price' => '900',
        ], []));

        self::assertSame(422, $response->statusCode());
        self::assertCount(0, $orders->all());
    }

    private function session(int $userId, string $role): SessionManager
    {
        $session = new SessionManager();
        $session->login(new AuthContext($userId, 'user' . $userId . '@example.test', $role));

        return $session;
    }

    private function products(): InMemoryProductRepository
    {
        return new InMemoryProductRepository([
            new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
            new Product(11, 'SKU-002', 'Widget B', 'pcs', 50.0, 90.0, 5, 1, true),
        ]);
    }

    private function sales(SessionManager $session, InMemorySalesOrderRepository $orders): SalesOrderController
    {
        $products = $this->products();
        $customers = [1 => new Customer(1, 'Demo Customer', '', '', '', true)];
        $warehouses = [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)];
        $stock = new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository());

        return new SalesOrderController(
            new SalesOrderService($orders, new InMemoryDocumentSequenceRepository(), $products, $customers, $warehouses, $stock, null, new OrderLinePricing(15)),
            $orders,
            $products,
            $customers,
            $warehouses,
            new AuthGuard($session),
        );
    }

    private function purchase(SessionManager $session, InMemoryPurchaseOrderRepository $orders): PurchaseOrderController
    {
        $products = $this->products();
        $suppliers = [1 => new Supplier(1, 'Demo Supplier', '', '', '', true)];
        $warehouses = [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)];

        return new PurchaseOrderController(
            new PurchaseOrderService($orders, new InMemoryDocumentSequenceRepository(), $products, $suppliers, $warehouses, new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository())),
            $orders,
            $products,
            $suppliers,
            $warehouses,
            new AuthGuard($session),
        );
    }
}
