<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\PurchaseOrderController;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\PurchaseOrderService;
use App\Service\StockService;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderControllerTest extends TestCase
{
    public function testListRendersPurchaseOrders(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $controller = $this->controller($session);

        $response = $controller->index(new Request('GET', '/purchase-orders', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('PO-001', $response->body());
    }

    public function testCreateFormRequiresAuthenticatedPurchasingRole(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(2, 'sales@example.test', User::ROLE_SALES));
        $controller = $this->controller($session);

        $this->expectException(HttpException::class);

        $controller->create(new Request('GET', '/purchase-orders/create', [], [], []));
    }

    public function testCreatePrefillsSelectedReplenishmentLinesWithoutCreatingAnOrder(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $response = $this->controller($this->admin(), $orders)->create(new Request('GET', '/purchase-orders/create', ['warehouse_id' => '1', 'pick' => ['10:5', '11:3']], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertMatchesRegularExpression('/name="product_id"[^>]*>\s*<option value="10" selected/', $response->body());
        self::assertMatchesRegularExpression('/name="items\[1\]\[product_id\]".*?<option value="11" selected/s', $response->body());
        self::assertStringContainsString('name="items[1][quantity]" type="number" min="1" required value="3"', $response->body());
        self::assertStringContainsString('name="items[1][purchase_price]" type="number" min="0" step="0.01" required value=""', $response->body());
        self::assertCount(0, $orders->all(), 'Selection only prefills; it never creates a draft.');
        self::assertSame(1, substr_count($response->body(), 'name="product_id"'), 'The clone template must not add a second product field.');
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidSelections(): array
    {
        return [
            'duplicate product' => [['10:5', '10:2']],
            'zero quantity' => [['10:0']],
            'malformed' => [['10;5']],
            'not a list' => ['10:5'],
            'empty' => [[]],
            'over limit' => [array_map(static fn (int $id): string => $id . ':1', range(1, 101))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSelections')]
    public function testInvalidReplenishmentSelectionRendersErrorForReview(mixed $pick): void
    {
        $response = $this->controller($this->admin())->create(new Request('GET', '/purchase-orders/create', ['warehouse_id' => '1', 'pick' => $pick], [], []));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('alert-danger', $response->body());
    }

    public function testStoreCreatesOneDraftWithEveryReviewedItem(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $response = $this->controller($this->admin(), $orders)->store(new Request('POST', '/purchase-orders', [], [
            'order_number' => 'PO-MULTI', 'supplier_id' => '1', 'warehouse_id' => '1',
            'product_id' => '10', 'quantity' => '5', 'purchase_price' => '1000',
            'items' => [4 => ['product_id' => '11', 'quantity' => '3', 'purchase_price' => '250.50']],
        ], []));

        self::assertSame(302, $response->statusCode());
        $created = array_values($orders->all())[0];
        self::assertSame('PO-MULTI', $created->orderNumber());
        self::assertSame([[10, 5, 1000.0], [11, 3, 250.5]], array_map(static fn ($item): array => [$item->productId(), $item->quantity(), $item->purchasePrice()], $created->items()));
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidItems(): array
    {
        return [
            'duplicate product' => [[1 => ['product_id' => '10', 'quantity' => '1', 'purchase_price' => '1']]],
            'missing price' => [[1 => ['product_id' => '11', 'quantity' => '1']]],
            'scalar line' => [[1 => '11']],
            'not a list' => ['11'],
            'over limit' => [array_fill(1, 100, ['product_id' => '11', 'quantity' => '1', 'purchase_price' => '1'])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidItems')]
    public function testStoreRejectsInvalidItemsWithoutCreatingDraft(mixed $items): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $response = $this->controller($this->admin(), $orders)->store(new Request('POST', '/purchase-orders', [], [
            'order_number' => 'PO-BAD', 'supplier_id' => '1', 'warehouse_id' => '1',
            'product_id' => '10', 'quantity' => '5', 'purchase_price' => '1000', 'items' => $items,
        ], []));

        self::assertSame(422, $response->statusCode());
        self::assertCount(0, $orders->all());
        self::assertStringContainsString('data-order-form', $response->body());
    }

    public function testDetailShowsProductSkuAndNameInsteadOfId(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $order = $orders->createDraft('PO-LABEL', 1, 1, 1, [['product_id' => 11, 'quantity' => 2, 'purchase_price' => 10.0]]);
        $body = $this->controller($this->admin(), $orders)->show(new Request('GET', '/purchase-orders/show', ['id' => (string) $order->id()], [], []))->body();

        self::assertStringContainsString('SKU-002 — Widget B', $body);
        self::assertStringNotContainsString('Product ID', $body);
    }

    private function admin(): SessionManager
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));

        return $session;
    }

    private function controller(SessionManager $session, ?InMemoryPurchaseOrderRepository $repository = null): PurchaseOrderController
    {
        $orders = $repository ?? new InMemoryPurchaseOrderRepository();
        if ($repository === null) {
            $orders->createDraft('PO-001', 1, 1, 1, [
                ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
            ]);
        }

        $products = new InMemoryProductRepository([
            new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
            new Product(11, 'SKU-002', 'Widget B', 'pcs', 250.0, 400.0, 5, 1, true),
        ]);
        $suppliers = [1 => new Supplier(1, 'Demo Supplier', '', '', '', true)];
        $warehouses = [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)];

        return new PurchaseOrderController(
            new PurchaseOrderService(
                $orders,
                $products,
                $suppliers,
                $warehouses,
                new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository()),
            ),
            $orders,
            $products,
            $suppliers,
            $warehouses,
            new AuthGuard($session),
        );
    }
}
