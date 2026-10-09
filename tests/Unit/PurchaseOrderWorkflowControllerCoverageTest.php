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
use App\Service\PurchaseOrderService;
use App\Service\StockService;
use App\Support\OrderSearchCriteria;
use PHPUnit\Framework\TestCase;

/** PO lifecycle through the HTTP controller: order, receive (stock + ledger), cancel, close remainder and the rendered list/detail states. */
final class PurchaseOrderWorkflowControllerCoverageTest extends TestCase
{
    private InMemoryPurchaseOrderRepository $orders;
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryOrderExceptionRepository $exceptions;
    private InMemoryProductRepository $products;
    /** @var array<int, Supplier> */
    private array $suppliers;
    /** @var array<int, Warehouse> */
    private array $warehouses;

    protected function setUp(): void
    {
        $this->orders = new InMemoryPurchaseOrderRepository();
        $this->stock = new InMemoryStockRepository();
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->exceptions = new InMemoryOrderExceptionRepository();
        $this->products = new InMemoryProductRepository([
            new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
        ]);
        $this->suppliers = [1 => new Supplier(1, 'Demo Supplier', '', '', '', true)];
        $this->warehouses = [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)];
    }

    public function testAdminOrdersDraftAndWarehouseReceivesPartiallyWithLedgerAndStockEffects(): void
    {
        $order = $this->draft('PO-RECV', 5);
        $admin = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));

        $response = $admin->order(new Request('POST', '/purchase-orders/order', [], ['id' => (string) $order->id()], []));
        self::assertSame(302, $response->statusCode());
        self::assertSame('/purchase-orders', $response->headers()['Location']);
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $this->orders->findById($order->id())?->status());

        $warehouse = $this->controller(new AuthContext(4, 'warehouse@test', User::ROLE_WAREHOUSE_STAFF));
        $itemId = $order->items()[0]->id();
        $response = $warehouse->receive(new Request('POST', '/purchase-orders/receive', [], [
            'id' => (string) $order->id(), 'item_id' => (string) $itemId, 'quantity' => '2', 'operation_key' => str_repeat('a', 32),
        ], []));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/purchase-orders/show?id=' . $order->id(), $response->headers()['Location']);
        self::assertSame('PartiallyReceived', $this->orders->findById($order->id())?->status());
        self::assertSame(2, $this->stock->quantity(10, 1));
        $movements = $this->ledger->forReference('PO', $order->id());
        self::assertCount(1, $movements);
        self::assertSame(2, $movements[0]->quantity());
        self::assertSame([], $this->ledger->forReference('SO', $order->id()));

        // The detail page shows the warning badge, the receive form for the remaining 3 and the receipt movement.
        $body = $warehouse->show(new Request('GET', '/purchase-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('status-warning status-partial', $body);
        self::assertStringContainsString('action="/purchase-orders/receive"', $body);
        self::assertStringContainsString('max="3"', $body);
        self::assertStringContainsString('Receipt #1 · quantity 2', $body);
        self::assertStringContainsString('source_ledger_id=1', $body);
    }

    public function testOverReceiptRendersDetailWith422AndLeavesStockUntouched(): void
    {
        $order = $this->draft('PO-OVER', 5);
        $this->orders->markOrdered($order->id());
        $warehouse = $this->controller(new AuthContext(4, 'warehouse@test', User::ROLE_WAREHOUSE_STAFF));

        $response = $warehouse->receive(new Request('POST', '/purchase-orders/receive', [], [
            'id' => (string) $order->id(), 'item_id' => (string) $order->items()[0]->id(), 'quantity' => '9', 'operation_key' => str_repeat('b', 32),
        ], []));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Receipt quantity cannot exceed remaining quantity.', $response->body());
        self::assertStringContainsString('status-warning status-ordered', $response->body());
        self::assertSame(0, $this->stock->quantity(10, 1));
        self::assertSame([], $this->ledger->entries());
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $this->orders->findById($order->id())?->status());
    }

    public function testReceiptWithoutOperationKeyIsRejected(): void
    {
        $order = $this->draft('PO-NOKEY', 5);
        $this->orders->markOrdered($order->id());
        $response = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN))->receive(new Request('POST', '/purchase-orders/receive', [], [
            'id' => (string) $order->id(), 'item_id' => (string) $order->items()[0]->id(), 'quantity' => '1',
        ], []));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('A valid operation key is required.', $response->body());
        self::assertSame(0, $this->stock->quantity(10, 1));
    }

    public function testReceiveOnUnknownOrderIs404(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Purchase order not found.');
        $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN))->receive(new Request('POST', '/purchase-orders/receive', [], ['id' => '99', 'item_id' => '1', 'quantity' => '1'], []));
    }

    public function testAdminCancelsOrderedPurchaseOrderAndDetailShowsDangerBadge(): void
    {
        $order = $this->draft('PO-CANCEL', 5);
        $this->orders->markOrdered($order->id());
        $admin = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));

        $response = $admin->cancel(new Request('POST', '/purchase-orders/cancel', [], ['id' => (string) $order->id()], []));

        self::assertSame(302, $response->statusCode());
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $this->orders->findById($order->id())?->status());
        $body = $admin->show(new Request('GET', '/purchase-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('status-danger status-cancelled', $body);
        self::assertStringNotContainsString('action="/purchase-orders/receive"', $body);
    }

    public function testWarehouseStaffCannotCancelOrOrder(): void
    {
        $order = $this->draft('PO-DENY', 5);
        $warehouse = $this->controller(new AuthContext(4, 'warehouse@test', User::ROLE_WAREHOUSE_STAFF));
        foreach (['cancel', 'order'] as $action) {
            try {
                $warehouse->{$action}(new Request('POST', '/purchase-orders/' . $action, [], ['id' => (string) $order->id()], []));
                self::fail($action . ' must be forbidden');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->statusCode());
            }
        }
        self::assertSame(PurchaseOrder::STATUS_DRAFT, $this->orders->findById($order->id())?->status());
    }

    public function testFullyReceivedOrderShowsSuccessBadgeWithoutReceiveForm(): void
    {
        $order = $this->draft('PO-FULL', 2);
        $this->orders->markOrdered($order->id());
        $admin = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));
        $admin->receive(new Request('POST', '/purchase-orders/receive', [], [
            'id' => (string) $order->id(), 'item_id' => (string) $order->items()[0]->id(), 'quantity' => '2', 'operation_key' => str_repeat('c', 32),
        ], []));

        self::assertSame(PurchaseOrder::STATUS_RECEIVED, $this->orders->findById($order->id())?->status());
        self::assertSame(2, $this->stock->quantity(10, 1));
        $body = $admin->show(new Request('GET', '/purchase-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('status-success status-received', $body);
        self::assertStringNotContainsString('action="/purchase-orders/receive"', $body);
    }

    public function testCloseRemainderRecordsClosureAndRequiresExceptionService(): void
    {
        $order = $this->draft('PO-CLOSE', 5);
        $this->orders->markOrdered($order->id());
        $this->orders->recordReceipt($order->id(), [$order->items()[0]->id() => 1], 'PartiallyReceived');
        $admin = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));

        $response = $admin->closeRemainder(new Request('POST', '/purchase-orders/close-remainder', [], ['id' => (string) $order->id(), 'reason' => 'Supplier discontinued'], []));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/purchase-orders/show?id=' . $order->id(), $response->headers()['Location']);
        self::assertSame('Supplier discontinued', $this->exceptions->closure($order->id())['reason'] ?? null);
        $body = $admin->show(new Request('GET', '/purchase-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('Remaining supply closed: Supplier discontinued', $body);

        $this->expectException(\LogicException::class);
        $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN), false)->closeRemainder(new Request('POST', '/purchase-orders/close-remainder', [], ['id' => (string) $order->id(), 'reason' => 'x'], []));
    }

    public function testAdminSeesCloseRemainderFormOnlyWhileRemainderIsOpen(): void
    {
        $order = $this->draft('PO-OPEN-REMAINDER', 5);
        $this->orders->markOrdered($order->id());
        $this->orders->recordReceipt($order->id(), [$order->items()[0]->id() => 2], 'PartiallyReceived');
        $show = new Request('GET', '/purchase-orders/show', ['id' => (string) $order->id()], [], []);

        $admin = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN))->show($show)->body();
        self::assertStringContainsString('action="/purchase-orders/close-remainder"', $admin);
        self::assertStringContainsString('Reason to close remaining supply', $admin);

        $warehouse = $this->controller(new AuthContext(4, 'warehouse@test', User::ROLE_WAREHOUSE_STAFF))->show($show)->body();
        self::assertStringNotContainsString('action="/purchase-orders/close-remainder"', $warehouse);
    }

    public function testPlainCreateFormKeepsOnlyKnownPrefillFieldsWithoutError(): void
    {
        $response = $this->controller(new AuthContext(4, 'warehouse@test', User::ROLE_WAREHOUSE_STAFF))->create(new Request('GET', '/purchase-orders/create', ['warehouse_id' => '1', 'product_id' => '10', 'quantity' => '7', 'supplier_id' => '1'], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringNotContainsString('alert-danger', $response->body());
        self::assertMatchesRegularExpression('/name="product_id"[^>]*>\s*<option value="10" data-price="1000.00" selected/', $response->body());
        self::assertStringContainsString('value="7"', $response->body());
        self::assertCount(0, $this->orders->all());
    }

    public function testListRendersEveryStatusToneAndEmptySearch(): void
    {
        $draft = $this->draft('PO-S-DRAFT', 5);
        $ordered = $this->draft('PO-S-ORDERED', 5);
        $this->orders->markOrdered($ordered->id());
        $partial = $this->draft('PO-S-PARTIAL', 5);
        $this->orders->markOrdered($partial->id());
        $this->orders->recordReceipt($partial->id(), [$partial->items()[0]->id() => 1], 'PartiallyReceived');
        $received = $this->draft('PO-S-RECEIVED', 1);
        $this->orders->markOrdered($received->id());
        $this->orders->recordReceipt($received->id(), [$received->items()[0]->id() => 1], 'Received');
        $cancelled = $this->draft('PO-S-CANCELLED', 1);
        $this->orders->cancel($cancelled->id());
        $controller = $this->controller(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));

        $body = $controller->index(new Request('GET', '/purchase-orders', [], [], []))->body();
        self::assertStringContainsString('status-normal status-draft', $body);
        self::assertStringContainsString('status-warning status-ordered', $body);
        self::assertStringContainsString('status-warning status-partial', $body);
        self::assertStringContainsString('status-success status-received', $body);
        self::assertStringContainsString('status-danger status-cancelled', $body);

        $empty = $controller->index(new Request('GET', '/purchase-orders', ['q' => 'NO-SUCH-ORDER'], [], []));
        self::assertSame(200, $empty->statusCode());
        self::assertStringContainsString('No purchase orders found.', $empty->body());
        self::assertStringNotContainsString('PO-S-DRAFT', $empty->body());
        self::assertSame(1, $this->orders->search(OrderSearchCriteria::fromArray(['q' => 'po-s-recei']))->total());
        self::assertSame($draft->id(), $this->orders->search(OrderSearchCriteria::fromArray(['q' => 'DRAFT', 'status' => 'Draft']))->items()[0]->id());
    }

    private function draft(string $number, int $quantity): PurchaseOrder
    {
        return $this->orders->createDraft($number, 1, 1, 1, [['product_id' => 10, 'quantity' => $quantity, 'purchase_price' => 1000.0]]);
    }

    private function controller(AuthContext $actor, bool $withExceptions = true): PurchaseOrderController
    {
        $session = new SessionManager();
        $session->login($actor);
        $stock = new StockService($this->stock, $this->ledger);
        $service = new PurchaseOrderService($this->orders, $this->products, $this->suppliers, $this->warehouses, $stock, new OperationIdempotency(new InMemoryOperationRequestRepository()), $this->exceptions);
        $exceptions = $withExceptions
            ? new OrderExceptionService($this->exceptions, $this->orders, new InMemorySalesOrderRepository(), $stock, new InMemoryAuditLogRepository())
            : null;

        return new PurchaseOrderController($service, $this->orders, $this->products, $this->suppliers, $this->warehouses, new AuthGuard($session), $exceptions, $this->ledger);
    }
}
