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

    private function controller(SessionManager $session): SalesOrderController
    {
        $orders = new InMemorySalesOrderRepository();
        $orders->createDraft('SO-OWN', 1, 1, 7, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
        $orders->createDraft('SO-OTHER', 1, 1, 8, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
        $products = new InMemoryProductRepository([
            new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
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
