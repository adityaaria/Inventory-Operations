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
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryOperationRequestRepository;
use App\Repository\InMemory\InMemoryOrderExceptionRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\OperationIdempotency;
use App\Service\OrderExceptionService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\OrderSearchCriteria;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** SO lifecycle through the HTTP controller: ownership, approve, cancel, issue (stock + ledger) and rendered list/detail states. */
final class SalesOrderWorkflowControllerCoverageTest extends TestCase
{
    private InMemorySalesOrderRepository $orders;
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryProductRepository $products;

    protected function setUp(): void
    {
        $this->orders = new InMemorySalesOrderRepository();
        $this->stock = new InMemoryStockRepository();
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->products = new InMemoryProductRepository([
            new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
        ]);
    }

    public function testSalesUserCannotOpenAnotherSalesUsersOrder(): void
    {
        $other = $this->draft('SO-OTHER', 8, 1);
        try {
            $this->controller(new AuthContext(7, 'sales@test', User::ROLE_SALES))->show(new Request('GET', '/sales-orders/show', ['id' => (string) $other->id()], [], []));
            self::fail('Foreign order must be forbidden');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
        }
        $own = $this->controller(new AuthContext(8, 'owner@test', User::ROLE_SALES))->show(new Request('GET', '/sales-orders/show', ['id' => (string) $other->id()], [], []));
        self::assertSame(200, $own->statusCode());
        self::assertStringContainsString('SO-OTHER', $own->body());
    }

    public function testWarehouseStaffCannotOpenCreateForm(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Forbidden');
        $this->controller(new AuthContext(4, 'warehouse@test', User::ROLE_WAREHOUSE_STAFF))->create();
    }

    public function testAdminApprovesAndWarehouseIssuesWithStockAndLedgerEffects(): void
    {
        $this->stock->seed(10, 1, 5);
        $order = $this->draft('SO-ISSUE', 1, 3);
        $this->orders->submit($order->id());
        $admin = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));

        $response = $admin->approve(new Request('POST', '/sales-orders/approve', [], ['id' => (string) $order->id()], []));
        self::assertSame(302, $response->statusCode());
        self::assertSame('/sales-orders', $response->headers()['Location']);
        self::assertSame(SalesOrder::STATUS_APPROVED, $this->orders->findById($order->id())?->status());

        $warehouse = $this->controller(new AuthContext(4, 'warehouse@test', User::ROLE_WAREHOUSE_STAFF));
        $body = $warehouse->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('status-success status-approved', $body);
        self::assertStringContainsString('action="/sales-orders/issue"', $body);
        self::assertMatchesRegularExpression('/name="operation_key" value="[a-f0-9]{32}"/', $body);

        $response = $warehouse->issue(new Request('POST', '/sales-orders/issue', [], ['id' => (string) $order->id(), 'operation_key' => str_repeat('d', 32)], []));
        self::assertSame(302, $response->statusCode());
        self::assertSame('/sales-orders/show?id=' . $order->id(), $response->headers()['Location']);
        self::assertSame(SalesOrder::STATUS_FULFILLED, $this->orders->findById($order->id())?->status());
        self::assertSame(2, $this->stock->quantity(10, 1));
        $movements = $this->ledger->forReference('SO', $order->id());
        self::assertCount(1, $movements);
        self::assertSame('Issue', $movements[0]->movementType());
        self::assertSame(3, $movements[0]->quantity());
    }

    public function testInsufficientStockIssueRendersDetailWith422AndNoLedger(): void
    {
        $this->stock->seed(10, 1, 1);
        $order = $this->draft('SO-SHORT', 1, 3);
        $this->orders->submit($order->id());
        $this->orders->approve($order->id(), 1);

        $response = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN))->issue(new Request('POST', '/sales-orders/issue', [], ['id' => (string) $order->id(), 'operation_key' => str_repeat('e', 32)], []));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('role="alert"', $response->body());
        self::assertStringContainsString('SO-SHORT', $response->body());
        self::assertSame(1, $this->stock->quantity(10, 1));
        self::assertSame([], $this->ledger->entries());
        self::assertSame(SalesOrder::STATUS_APPROVED, $this->orders->findById($order->id())?->status());
    }

    public function testIssueOfUnknownOrderIs404(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Sales order not found.');
        $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN))->issue(new Request('POST', '/sales-orders/issue', [], ['id' => '42'], []));
    }

    public function testAdminCancelsApprovedOrderButSalesCannot(): void
    {
        $order = $this->draft('SO-CANCEL', 7, 1);
        $this->orders->submit($order->id());
        $this->orders->approve($order->id(), 1);
        try {
            $this->controller(new AuthContext(7, 'sales@test', User::ROLE_SALES))->cancel(new Request('POST', '/sales-orders/cancel', [], ['id' => (string) $order->id()], []));
            self::fail('Sales must not cancel');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
        }

        $response = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN))->cancel(new Request('POST', '/sales-orders/cancel', [], ['id' => (string) $order->id()], []));
        self::assertSame(302, $response->statusCode());
        self::assertSame(SalesOrder::STATUS_CANCELLED, $this->orders->findById($order->id())?->status());
    }

    public function testRejectingNoLongerPendingOrderShowsErrorOnPageWithoutDialog(): void
    {
        $order = $this->draft('SO-LATE', 1, 1);
        $this->orders->submit($order->id());
        $this->orders->approve($order->id(), 1);

        $response = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN))->reject(new Request('POST', '/sales-orders/reject', [], ['id' => (string) $order->id(), 'reason' => 'Too late'], []));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Only PendingApproval SO can be rejected.', $response->body());
        self::assertDoesNotMatchRegularExpression('/id="reject-dialog" >/', $response->body());
        self::assertSame(SalesOrder::STATUS_APPROVED, $this->orders->findById($order->id())?->status());
    }

    public function testRejectRequiresExceptionService(): void
    {
        $order = $this->draft('SO-NOEXC', 1, 1);
        $this->expectException(\LogicException::class);
        $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN), false)->reject(new Request('POST', '/sales-orders/reject', [], ['id' => (string) $order->id(), 'reason' => 'x'], []));
    }

    public function testListRendersEveryStatusToneAndEmptySearch(): void
    {
        $this->draft('SO-S-DRAFT', 1, 1);
        $pending = $this->draft('SO-S-PENDING', 1, 1);
        $this->orders->submit($pending->id());
        $approved = $this->draft('SO-S-APPROVED', 1, 1);
        $this->orders->submit($approved->id());
        $this->orders->approve($approved->id(), 1);
        $fulfilled = $this->draft('SO-S-FULFILLED', 1, 1);
        $this->orders->submit($fulfilled->id());
        $this->orders->approve($fulfilled->id(), 1);
        $this->orders->markFulfilled($fulfilled->id());
        $cancelled = $this->draft('SO-S-CANCELLED', 1, 1);
        $this->orders->cancel($cancelled->id());
        $controller = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));

        $body = $controller->index(new Request('GET', '/sales-orders', [], [], []))->body();
        self::assertStringContainsString('status-normal status-draft', $body);
        self::assertStringContainsString('status-warning status-pending', $body);
        self::assertStringContainsString('status-success status-approved', $body);
        self::assertStringContainsString('status-success status-fulfilled', $body);
        self::assertStringContainsString('status-danger status-cancelled', $body);

        $empty = $controller->index(new Request('GET', '/sales-orders', ['q' => 'NOTHING-HERE'], [], []));
        self::assertSame(200, $empty->statusCode());
        self::assertStringContainsString('No sales orders found.', $empty->body());
        self::assertSame(1, $this->orders->search(OrderSearchCriteria::fromArray(['q' => 's-fulf']))->total());
    }

    public function testRepositoryGuardsAndCreatorFilter(): void
    {
        $mine = $this->draft('SO-MINE', 7, 1);
        $this->draft('SO-THEIRS', 8, 1);
        self::assertSame([$mine->id()], array_map(static fn (SalesOrder $order): int => $order->id(), $this->orders->forCreator(7)));
        self::assertSame([], $this->orders->forCreator(99));

        try {
            $this->orders->approve($mine->id(), 1);
            self::fail('Draft must not be approved');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Only PendingApproval sales orders can be approved.', $exception->getMessage());
        }
        $this->orders->submit($mine->id());
        $this->orders->approve($mine->id(), 1);
        $this->orders->markFulfilled($mine->id());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fulfilled sales orders cannot be cancelled.');
        $this->orders->cancel($mine->id());
    }

    private function draft(string $number, int $createdBy, int $quantity): SalesOrder
    {
        return $this->orders->createDraft($number, 1, 1, $createdBy, [['product_id' => 10, 'quantity' => $quantity, 'selling_price' => 1500.0]]);
    }

    private function controller(AuthContext $actor, bool $withExceptions = true): SalesOrderController
    {
        $session = new SessionManager();
        $session->login($actor);
        $customers = [1 => new Customer(1, 'Demo Customer', '', '', '', true)];
        $warehouses = [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)];
        $stock = new StockService($this->stock, $this->ledger);
        $exceptions = $withExceptions
            ? new OrderExceptionService(new InMemoryOrderExceptionRepository(), new InMemoryPurchaseOrderRepository(), $this->orders, $stock, new InMemoryAuditLogRepository())
            : null;

        return new SalesOrderController(
            new SalesOrderService($this->orders, $this->products, $customers, $warehouses, $stock, new OperationIdempotency(new InMemoryOperationRequestRepository())),
            $this->orders,
            $this->products,
            $customers,
            $warehouses,
            new AuthGuard($session),
            $exceptions,
            $this->ledger,
        );
    }
}
