<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\SalesOrderController;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\SalesOrderService;
use App\Service\StockService;
use PHPUnit\Framework\TestCase;

final class SalesOrderControllerTest extends TestCase
{
    public function testSalesListRendersOnlyOwnOrders(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(7, 'sales@example.test', User::ROLE_SALES));
        $controller = $this->controller($session);

        $response = $controller->index(new Request('GET', '/sales-orders', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('SO-OWN', $response->body());
        self::assertStringNotContainsString('SO-OTHER', $response->body());
    }

    public function testApproveRequiresAdmin(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(7, 'sales@example.test', User::ROLE_SALES));
        $controller = $this->controller($session);

        $this->expectException(HttpException::class);

        $controller->approve(new Request('POST', '/sales-orders/approve', [], ['id' => 1], []));
    }

    public function testSalesCreatesOneOwnDraftWithEveryLineAndTemplateHasNoFieldNames(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(7, 'sales@example.test', User::ROLE_SALES));
        $orders = new InMemorySalesOrderRepository();
        $controller = $this->controller($session, $orders);

        $form = $controller->create(new Request('GET', '/sales-orders/create', [], [], []))->body();
        self::assertSame(1, substr_count($form, 'name="product_id"'), 'The clone template must not add a second product field.');
        self::assertStringContainsString('data-order-template', $form);

        $response = $controller->store(new Request('POST', '/sales-orders', [], [
            'order_number' => 'SO-MULTI', 'customer_id' => '1', 'warehouse_id' => '1',
            'product_id' => '10', 'quantity' => '2', 'selling_price' => '1500',
            'items' => [3 => ['product_id' => '11', 'quantity' => '4', 'selling_price' => '90.25']],
        ], []));

        self::assertSame(302, $response->statusCode());
        $created = array_values($orders->all())[0];
        self::assertSame(7, $created->createdBy());
        self::assertSame([[10, 2, 1500.0], [11, 4, 90.25]], array_map(static fn ($item): array => [$item->productId(), $item->quantity(), $item->sellingPrice()], $created->items()));
    }

    public function testDuplicateSalesLinesAreRejectedAndRetainedForCorrection(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(7, 'sales@example.test', User::ROLE_SALES));
        $orders = new InMemorySalesOrderRepository();

        $response = $this->controller($session, $orders)->store(new Request('POST', '/sales-orders', [], [
            'order_number' => 'SO-DUP', 'customer_id' => '1', 'warehouse_id' => '1',
            'product_id' => '10', 'quantity' => '2', 'selling_price' => '1500',
            'items' => [1 => ['product_id' => '10', 'quantity' => '1', 'selling_price' => '1']],
        ], []));

        self::assertSame(422, $response->statusCode());
        self::assertCount(0, $orders->all());
        self::assertStringContainsString('name="items[1][product_id]"', $response->body());
    }

    public function testDetailShowsProductSkuAndNameInsteadOfId(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(7, 'sales@example.test', User::ROLE_SALES));
        $orders = new InMemorySalesOrderRepository();
        $order = $orders->createDraft('SO-LABEL', 1, 1, 7, [['product_id' => 11, 'quantity' => 1, 'selling_price' => 90.0]]);
        $body = $this->controller($session, $orders)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();

        self::assertStringContainsString('SKU-002 — Widget B', $body);
        self::assertStringNotContainsString('Product ID', $body);
    }

    private function controller(SessionManager $session, ?InMemorySalesOrderRepository $repository = null): SalesOrderController
    {
        $orders = $repository ?? new InMemorySalesOrderRepository();
        if ($repository !== null) {
            return $this->build($session, $orders);
        }
        $orders->createDraft('SO-OWN', 1, 1, 7, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
        $orders->createDraft('SO-OTHER', 1, 1, 8, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);

        return $this->build($session, $orders);
    }

    private function build(SessionManager $session, InMemorySalesOrderRepository $orders): SalesOrderController
    {
        $products = new InMemoryProductRepository([
            new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
            new Product(11, 'SKU-002', 'Widget B', 'pcs', 50.0, 90.0, 5, 1, true),
        ]);
        $customers = [1 => new Customer(1, 'Demo Customer', '', '', '', true)];
        $warehouses = [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)];

        return new SalesOrderController(
            new SalesOrderService(
                $orders,
                $products,
                $customers,
                $warehouses,
                new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository()),
            ),
            $orders,
            $products,
            $customers,
            $warehouses,
            new AuthGuard($session),
        );
    }
}
