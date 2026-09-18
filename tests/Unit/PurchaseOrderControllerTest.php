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

    private function controller(SessionManager $session): PurchaseOrderController
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $orders->createDraft('PO-001', 1, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);

        $products = new InMemoryProductRepository([
            new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
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
