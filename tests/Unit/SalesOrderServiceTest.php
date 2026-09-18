<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Service\SalesOrderService;
use App\Service\StockService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SalesOrderServiceTest extends TestCase
{
    public function testSalesCannotApproveOrder(): void
    {
        [$service, $orders] = $this->service();
        $sales = new AuthContext(7, 'sales@example.test', User::ROLE_SALES);
        $order = $service->createDraft($sales, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
        $service->submit($sales, $order->id());

        $this->expectException(HttpException::class);

        $service->approve($sales, $orders->findById($order->id())?->id() ?? 0);
    }

    public function testSalesCanManageOnlyOwnOrders(): void
    {
        [$service] = $this->service();
        $owner = new AuthContext(7, 'sales@example.test', User::ROLE_SALES);
        $other = new AuthContext(8, 'sales2@example.test', User::ROLE_SALES);
        $order = $service->createDraft($owner, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);

        $this->expectException(HttpException::class);

        $service->submit($other, $order->id());
    }

    public function testCannotIssueUnlessApproved(): void
    {
        [$service] = $this->service();
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $order = $service->createDraft($admin, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $service->issue($admin, $order->id());
    }

    public function testIssueMarksApprovedOrderFulfilled(): void
    {
        [$service, $orders] = $this->service(stockQuantity: 5);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $order = $service->createDraft($admin, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 2, 'selling_price' => 2000.0],
        ]);
        $service->submit($admin, $order->id());
        $service->approve($admin, $order->id());

        $service->issue($admin, $order->id());

        self::assertSame(SalesOrder::STATUS_FULFILLED, $orders->findById($order->id())?->status());
    }

    public function testCannotTransitionFulfilledBackToDraft(): void
    {
        [$service, $orders] = $this->service(stockQuantity: 5);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $order = $service->createDraft($admin, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
        $service->submit($admin, $order->id());
        $service->approve($admin, $order->id());
        $service->issue($admin, $order->id());

        $this->expectException(InvalidArgumentException::class);

        $orders->submit($order->id());
    }

    /**
     * @return array{0: SalesOrderService, 1: InMemorySalesOrderRepository}
     */
    private function service(int $stockQuantity = 0): array
    {
        $orders = new InMemorySalesOrderRepository();
        $stock = new InMemoryStockRepository();
        $stock->seed(10, 1, $stockQuantity);

        return [
            new SalesOrderService(
                $orders,
                new InMemoryProductRepository([new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true)]),
                [1 => new Customer(1, 'Demo Customer', '', '', '', true)],
                [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)],
                new StockService($stock, new InMemoryStockLedgerRepository()),
            ),
            $orders,
        ];
    }
}
