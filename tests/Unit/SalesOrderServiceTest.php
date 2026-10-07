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

    public function testSubmitRejectsNonDraftOrder(): void
    {
        [$service] = $this->service();
        $sales = new AuthContext(7, 'sales@example.test', User::ROLE_SALES);
        $order = $service->createDraft($sales, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
        $service->submit($sales, $order->id());

        $this->expectException(InvalidArgumentException::class);

        $service->submit($sales, $order->id());
    }

    public function testApproveRejectsOrderNotPendingApproval(): void
    {
        [$service] = $this->service();
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $order = $service->createDraft($admin, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $service->approve($admin, $order->id());
    }

    public function testAdminCanSubmitAnyonesOrder(): void
    {
        [$service, $orders] = $this->service();
        $sales = new AuthContext(7, 'sales@example.test', User::ROLE_SALES);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $order = $service->createDraft($sales, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);

        $service->submit($admin, $order->id());

        self::assertSame(SalesOrder::STATUS_PENDING_APPROVAL, $orders->findById($order->id())?->status());
    }

    public function testRejectOrCancelMarksOrderCancelled(): void
    {
        [$service, $orders] = $this->service();
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $order = $service->createDraft($admin, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);

        $service->rejectOrCancel($admin, $order->id());

        self::assertSame(SalesOrder::STATUS_CANCELLED, $orders->findById($order->id())?->status());
    }

    public function testRejectOrCancelRejectsFulfilledOrder(): void
    {
        [$service] = $this->service(stockQuantity: 5);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $order = $service->createDraft($admin, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
        $service->submit($admin, $order->id());
        $service->approve($admin, $order->id());
        $service->issue($admin, $order->id());

        $this->expectException(InvalidArgumentException::class);

        $service->rejectOrCancel($admin, $order->id());
    }

    public function testNonAdminCannotRejectOrCancel(): void
    {
        [$service] = $this->service();
        $sales = new AuthContext(7, 'sales@example.test', User::ROLE_SALES);
        $order = $service->createDraft($sales, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);

        $this->expectException(HttpException::class);

        $service->rejectOrCancel($sales, $order->id());
    }

    public function testWarehouseStaffCannotCreateSalesOrder(): void
    {
        [$service] = $this->service();

        $this->expectException(HttpException::class);

        $service->createDraft(new AuthContext(3, 'warehouse@example.test', User::ROLE_WAREHOUSE_STAFF), 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
    }

    public function testSalesCannotIssueOwnApprovedOrder(): void
    {
        [$service] = $this->service(stockQuantity: 5);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $sales = new AuthContext(7, 'sales@example.test', User::ROLE_SALES);
        $order = $service->createDraft($sales, 'SO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
        $service->submit($sales, $order->id());
        $service->approve($admin, $order->id());

        $this->expectException(HttpException::class);

        $service->issue($sales, $order->id());
    }

    public function testOperationsOnUnknownOrderAreRejected(): void
    {
        [$service] = $this->service();
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        $this->expectException(InvalidArgumentException::class);

        $service->approve($admin, 999);
    }

    public function testRejectsBlankOrderNumber(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), '   ', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
    }

    public function testRejectsUnknownCustomer(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 'SO-002', 999, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
    }

    public function testRejectsUnknownWarehouse(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 'SO-003', 1, 999, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
    }

    public function testRejectsEmptyItemList(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 'SO-004', 1, 1, []);
    }

    public function testRejectsUnknownProduct(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 'SO-005', 1, 1, [
            ['product_id' => 999, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
    }

    public function testRejectsDuplicateProductLines(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 'SO-006', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => 2000.0],
        ]);
    }

    public function testRejectsNonPositiveItemQuantity(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 'SO-007', 1, 1, [
            ['product_id' => 10, 'quantity' => 0, 'selling_price' => 2000.0],
        ]);
    }

    public function testRejectsNegativeItemPrice(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 'SO-008', 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'selling_price' => -1.0],
        ]);
    }

    /**
     * @return array{0: SalesOrderService, 1: InMemorySalesOrderRepository}
     */
    public function testRepeatedApprovalIsAnHttp422ValidationFailure(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $order = $service->createDraft($actor, 'SO-STATE', 1, 1, [['product_id'=>10,'quantity'=>1,'selling_price'=>20.0]]);
        $service->submit($actor, $order->id()); $service->approve($actor, $order->id());
        try { $service->approve($actor, $order->id()); self::fail('Transition must fail.'); }
        catch (\App\Exception\ValidationException $exception) {
            self::assertSame(422, $exception->statusCode());
            self::assertSame(422, \App\Http\ErrorResponder::browser($exception, false)->statusCode());
        }
    }

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
