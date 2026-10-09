<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\SalesOrderController;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryOperationRequestRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\OperationIdempotency;
use App\Service\SalesOrderService;
use App\Service\StockService;
use PHPUnit\Framework\TestCase;

/** SO detail shows read-only stock at the source warehouse and warns when a line exceeds it. */
final class SalesOrderSourceAvailabilityTest extends TestCase
{
    private InMemorySalesOrderRepository $orders;
    private InMemoryProductRepository $products;

    protected function setUp(): void
    {
        $this->orders = new InMemorySalesOrderRepository();
        $this->products = new InMemoryProductRepository([
            new Product(10, 'KES-0001', 'Balsem', 'karton', 1000.0, 1500.0, 8, 1, true),
            new Product(11, 'KTE-0025', 'Teh Celup', 'karton', 1000.0, 1500.0, 18, 1, true),
        ]);
        $this->products->setStockSnapshot(10, 1, 83);
        $this->products->setStockSnapshot(10, 2, 56);
        $this->products->setStockSnapshot(11, 1, 40);
    }

    public function testServiceReturnsStockAtSourceWarehouseOnlyForOpenOrders(): void
    {
        $order = $this->draft(8, [[10, 100], [11, 5]]);
        $service = $this->service();
        $admin = new AuthContext(1, 'admin@test', User::ROLE_ADMIN);

        self::assertSame([10 => 83, 11 => 40], $service->sourceAvailability($admin, $order), 'Other warehouses are ignored');

        $this->orders->submit($order->id());
        $this->orders->approve($order->id(), 1);
        $this->orders->markFulfilled($order->id());
        self::assertSame([], $service->sourceAvailability($admin, $this->orders->findById($order->id()) ?? self::fail('order')));
    }

    public function testProductWithoutStockRowAtSourceCountsAsZero(): void
    {
        $order = $this->orders->createDraft('SO-W2', 1, 2, 8, [['product_id' => 11, 'quantity' => 3, 'selling_price' => 1500.0]]);

        self::assertSame([11 => 0], $this->service()->sourceAvailability(new AuthContext(1, 'admin@test', User::ROLE_ADMIN), $order));
    }

    public function testSalesCannotReadAvailabilityOfAnotherSalesUsersOrder(): void
    {
        $order = $this->draft(8, [[10, 1]]);

        $this->expectException(HttpException::class);
        $this->service()->sourceAvailability(new AuthContext(7, 'other@test', User::ROLE_SALES), $order);
    }

    public function testDetailWarnsWhenALineExceedsSourceStock(): void
    {
        $order = $this->draft(8, [[10, 100], [11, 5]]);
        $this->orders->submit($order->id());

        $body = $this->show(new AuthContext(1, 'admin@test', User::ROLE_ADMIN), $order);

        self::assertStringContainsString('Available at Source', $body);
        self::assertStringContainsString('1 line exceeds the stock currently available at the source warehouse', $body);
        self::assertStringContainsString('<td>83 <span class="status-badge status-warning">Short 17</span></td>', $body);
        self::assertStringContainsString('<td>40</td>', $body, 'Covered line has no shortage badge');
    }

    public function testDetailHasNoWarningWhenStockIsSufficient(): void
    {
        $order = $this->draft(8, [[10, 83]]);

        $body = $this->show(new AuthContext(8, 'owner@test', User::ROLE_SALES), $order);

        self::assertStringContainsString('Available at Source', $body);
        self::assertStringNotContainsString('alert alert-warning', $body);
        self::assertStringNotContainsString('Short ', $body);
    }

    public function testClosedOrderHidesAvailabilityColumn(): void
    {
        $order = $this->draft(8, [[10, 100]]);
        $this->orders->cancel($order->id());

        $body = $this->show(new AuthContext(1, 'admin@test', User::ROLE_ADMIN), $order);

        self::assertStringNotContainsString('Available at Source', $body);
        self::assertStringNotContainsString('alert alert-warning', $body);
    }

    /** @param list<array{0: int, 1: int}> $lines */
    private function draft(int $createdBy, array $lines): SalesOrder
    {
        return $this->orders->createDraft('SO-AVAIL', 1, 1, $createdBy, array_map(
            static fn (array $line): array => ['product_id' => $line[0], 'quantity' => $line[1], 'selling_price' => 1500.0],
            $lines,
        ));
    }

    private function service(): SalesOrderService
    {
        return new SalesOrderService(
            $this->orders,
            $this->products,
            [1 => new Customer(1, 'Demo Customer', '', '', '', true)],
            [1 => new Warehouse(1, 'Gudang Surabaya', 'Surabaya', true), 2 => new Warehouse(2, 'Gudang Cikarang', 'Cikarang', true)],
            new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository()),
            new OperationIdempotency(new InMemoryOperationRequestRepository()),
        );
    }

    private function show(AuthContext $actor, SalesOrder $order): string
    {
        $session = new SessionManager();
        $session->login($actor);
        $controller = new SalesOrderController(
            $this->service(),
            $this->orders,
            $this->products,
            [1 => new Customer(1, 'Demo Customer', '', '', '', true)],
            [1 => new Warehouse(1, 'Gudang Surabaya', 'Surabaya', true), 2 => new Warehouse(2, 'Gudang Cikarang', 'Cikarang', true)],
            new AuthGuard($session),
        );
        $response = $controller->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []));
        self::assertSame(200, $response->statusCode());

        return $response->body();
    }
}
