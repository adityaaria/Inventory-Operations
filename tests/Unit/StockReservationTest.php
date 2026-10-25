<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\SalesOrderController;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\ProductStock;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryBusinessOperationRepository;
use App\Repository\InMemory\InMemoryDocumentSequenceRepository;
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
use App\Service\BusinessOperationService;
use App\Service\OperationIdempotency;
use App\Service\OrderExceptionService;
use App\Service\SalesOrderService;
use App\Service\StockDelta;
use App\Service\StockMovement;
use App\Service\StockService;
use PHPUnit\Framework\TestCase;

/** D-12 / ADR-018: approval reserves, issue and cancellation release, and no other decrease may cut into reservations. */
final class StockReservationTest extends TestCase
{
    private InMemorySalesOrderRepository $orders;
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryAuditLogRepository $audit;
    private InMemoryProductRepository $products;
    private SalesOrderService $service;
    private AuthContext $admin;

    protected function setUp(): void
    {
        $this->orders = new InMemorySalesOrderRepository();
        $this->stock = new InMemoryStockRepository();
        $this->stock->seed(10, 1, 10);
        $this->stock->seed(11, 1, 3);
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->products = new InMemoryProductRepository([
            new Product(10, 'SKU-010', 'Widget', 'pcs', 1000.0, 1500.0, 5, 1, true),
            new Product(11, 'SKU-011', 'Gadget', 'pcs', 1000.0, 1500.0, 5, 1, true),
        ]);
        $this->service = $this->salesService();
        $this->admin = new AuthContext(1, 'admin@test', User::ROLE_ADMIN);
    }

    public function testApproveReservesEveryLineWithoutMovingGoodsAndAuditsTheReservation(): void
    {
        $order = $this->pending([[10, 4], [11, 3]]);

        $this->service->approve($this->admin, $order->id());

        self::assertSame(SalesOrder::STATUS_APPROVED, $this->orders->findById($order->id())?->status());
        self::assertSame([10, 4], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)]);
        self::assertSame([3, 3], [$this->stock->quantity(11, 1), $this->stock->reservedQuantity(11, 1)]);
        self::assertSame([], $this->ledger->entries(), 'A reservation is not a physical movement.');
        self::assertSame(['1:10', '1:11'], $this->stock->lockedKeys(), 'Stock rows are locked in product order.');
        $entry = $this->audit->entries()[0];
        self::assertSame('sales-orders.approve', $entry['action']);
        self::assertSame('SO', $entry['entity_type']);
        self::assertSame(7, $entry['metadata']['reserved_units']);
        self::assertSame([['product_id' => 10, 'quantity' => 4], ['product_id' => 11, 'quantity' => 3]], $entry['metadata']['reservation']);
    }

    public function testApproveIsRefusedWhenAnyLineIsShortAndNothingChanges(): void
    {
        $order = $this->pending([[10, 4], [11, 4]]);

        try {
            $this->service->approve($this->admin, $order->id());
            self::fail('A short line must refuse the approval.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->statusCode());
            self::assertStringContainsString('Insufficient available stock', $exception->getMessage());
        }

        self::assertSame(SalesOrder::STATUS_PENDING_APPROVAL, $this->orders->findById($order->id())?->status());
        self::assertSame([0, 0], [$this->stock->reservedQuantity(10, 1), $this->stock->reservedQuantity(11, 1)], 'The covered line was not reserved either.');
        self::assertSame([], $this->audit->entries());
    }

    public function testApproveCountsOnlyStockNotReservedByOtherOrders(): void
    {
        $first = $this->pending([[10, 7]]);
        $second = $this->pending([[10, 4]]);
        $this->service->approve($this->admin, $first->id());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('available 3');
        $this->service->approve($this->admin, $second->id());
    }

    public function testApprovalStaysAdminOnlyAndChecksRoleBeforeStock(): void
    {
        $order = $this->pending([[10, 1]]);
        foreach ([User::ROLE_SALES, User::ROLE_WAREHOUSE_STAFF] as $role) {
            try {
                $this->service->approve(new AuthContext(7, 'user@test', $role), $order->id());
                self::fail($role . ' must not approve.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->statusCode());
            }
        }
        self::assertSame(0, $this->stock->reservedQuantity(10, 1));
    }

    public function testIssueConsumesTheReservationAndTheGoods(): void
    {
        $order = $this->pending([[10, 4]]);
        $this->service->approve($this->admin, $order->id());

        $this->service->issue($this->admin, $order->id());

        self::assertSame([6, 0], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)]);
        self::assertCount(1, $this->ledger->entries());
        self::assertSame('Issue', $this->ledger->entries()[0]->movementType());
        self::assertSame(4, $this->audit->entries()[1]['metadata']['reservation_released_units']);
    }

    public function testIssueReplayWithTheSameKeyDoesNotReleaseTwice(): void
    {
        $order = $this->pending([[10, 4]]);
        $other = $this->pending([[10, 3]]);
        $this->service->approve($this->admin, $order->id());
        $this->service->approve($this->admin, $other->id());
        $key = str_repeat('a', 32);

        $this->service->issue($this->admin, $order->id(), $key);
        $this->service->issue($this->admin, $order->id(), $key);

        self::assertSame([6, 3], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)], 'The other order keeps its reservation.');
        self::assertCount(1, $this->ledger->entries());
    }

    public function testCancellingAnApprovedOrderReleasesItsReservation(): void
    {
        $order = $this->pending([[10, 4], [11, 2]]);
        $this->service->approve($this->admin, $order->id());

        $this->service->rejectOrCancel($this->admin, $order->id());

        self::assertSame(SalesOrder::STATUS_CANCELLED, $this->orders->findById($order->id())?->status());
        self::assertSame([10, 0, 3, 0], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1), $this->stock->quantity(11, 1), $this->stock->reservedQuantity(11, 1)]);
        self::assertSame([], $this->ledger->entries());
        $entry = $this->audit->entries()[1];
        self::assertSame('sales-orders.cancel', $entry['action']);
        self::assertSame('Approved', $entry['metadata']['previous_status']);
        self::assertSame(6, $entry['metadata']['reservation_released_units']);

        $this->service->rejectOrCancel($this->admin, $order->id());
        self::assertCount(2, $this->audit->entries(), 'Cancelling again is a no-op and releases nothing.');
        self::assertSame(0, $this->stock->reservedQuantity(10, 1));
    }

    public function testCancellingPendingOrDraftOrdersHasNothingToRelease(): void
    {
        $reserved = $this->pending([[10, 4]]);
        $this->service->approve($this->admin, $reserved->id());
        $pending = $this->pending([[10, 2]]);
        $draft = $this->orders->createDraft('SO-DRAFT', 1, 1, 1, [['product_id' => 10, 'quantity' => 1, 'selling_price' => 1500.0]]);

        $this->service->rejectOrCancel($this->admin, $pending->id());
        $this->service->rejectOrCancel($this->admin, $draft->id());

        self::assertSame(4, $this->stock->reservedQuantity(10, 1), 'Only the approved order holds stock.');
        self::assertSame(['PendingApproval', 'Draft'], array_map(static fn (array $entry): string => $entry['metadata']['previous_status'], array_slice($this->audit->entries(), 1)));
        self::assertSame(0, $this->audit->entries()[1]['metadata']['reservation_released_units']);
    }

    public function testRejectingAPendingOrderLeavesOtherReservationsUntouched(): void
    {
        $approved = $this->pending([[10, 4]]);
        $this->service->approve($this->admin, $approved->id());
        $pending = $this->pending([[10, 6]]);
        $exceptions = new OrderExceptionService(new InMemoryOrderExceptionRepository(), new InMemoryPurchaseOrderRepository(), $this->orders, $this->stockService(), $this->audit);

        $exceptions->reject($this->admin, $pending->id(), 'Customer withdrew');

        self::assertSame(SalesOrder::STATUS_CANCELLED, $this->orders->findById($pending->id())?->status());
        self::assertSame(4, $this->stock->reservedQuantity(10, 1));
    }

    public function testReleasingAMissingReservationRollsBack(): void
    {
        $order = $this->pending([[10, 4]]);
        $this->orders->approve($order->id(), 1); // approved outside the service: no reservation exists

        try {
            $this->service->rejectOrCancel($this->admin, $order->id());
            self::fail('A missing reservation is an invariant breach.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Reservation is missing', $exception->getMessage());
        }
        self::assertSame(0, $this->stock->reservedQuantity(10, 1));
        self::assertSame([], $this->audit->entries());
    }

    public function testUnreservedIssueCannotConsumeOtherOrdersReservations(): void
    {
        $order = $this->pending([[10, 8]]);
        $this->service->approve($this->admin, $order->id());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient available stock.');
        $this->stockService()->issue(1, [new StockMovement(10, 3)], 1, 'TEST', 1);
    }

    public function testAdjustmentDecreaseCannotGoBelowReservedButIncreaseIsUnaffected(): void
    {
        $order = $this->pending([[10, 8]]);
        $this->service->approve($this->admin, $order->id());

        try {
            $this->stockService()->adjust([new StockDelta(10, 1, -3)], 1, 'ADJUSTMENT', 1, static fn (): null => null);
            self::fail('Decrease below the reservation must fail.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->statusCode());
            self::assertStringContainsString('8 unit(s) of product #10 in warehouse #1 are reserved', $exception->getMessage());
        }
        self::assertSame(10, $this->stock->quantity(10, 1));

        $this->stockService()->adjust([new StockDelta(10, 1, -2)], 1, 'ADJUSTMENT', 2, static fn (): null => null);
        $this->stockService()->adjust([new StockDelta(10, 1, 5)], 1, 'ADJUSTMENT', 3, static fn (): null => null);
        self::assertSame([13, 8], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)]);
    }

    public function testTransferOutAndSupplierReturnRespectReservations(): void
    {
        $order = $this->pending([[10, 8]]);
        $this->service->approve($this->admin, $order->id());
        $this->stock->seed(10, 2, 0);
        $business = new BusinessOperationService(
            new InMemoryBusinessOperationRepository(['10:1' => ['quantity' => 10], '10:2' => ['quantity' => 0]], [50 => ['id' => 50, 'movement_type' => 'Receipt', 'reference_type' => 'PO', 'warehouse_id' => 1, 'product_id' => 10, 'quantity' => 5]]),
            $this->stockService(),
            $this->audit,
        );
        $reviewer = new AuthContext(2, 'reviewer@test', User::ROLE_ADMIN);
        $transfer = $business->propose($this->admin, 'Transfer', 1, 2, 'Move', [['product_id' => 10, 'quantity' => 3]]);
        $business->decide($reviewer, $transfer, 'Approved', 'Checked');
        $return = $business->propose($this->admin, 'SupplierReturn', 0, null, 'Damaged', [['quantity' => 3, 'source_ledger_id' => 50]]);
        $business->decide($reviewer, $return, 'Approved', 'Checked');

        foreach ([$transfer, $return] as $operation) {
            try {
                $business->post($this->admin, $operation);
                self::fail('Posting must not cut into reserved stock.');
            } catch (ValidationException $exception) {
                self::assertStringContainsString('reserved for approved sales orders', $exception->getMessage());
            }
        }
        self::assertSame([10, 8, 0], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1), $this->stock->quantity(10, 2)]);

        $this->service->rejectOrCancel($this->admin, $order->id());
        $business->post($this->admin, $transfer);
        // D-15 / ADR-021: posting dispatches the transfer (goods in transit); the receipt brings them in.
        self::assertSame([7, 0], [$this->stock->quantity(10, 1), $this->stock->quantity(10, 2)], 'After the release the transfer is dispatched.');
        $business->receive($this->admin, $transfer);
        self::assertSame([7, 3], [$this->stock->quantity(10, 1), $this->stock->quantity(10, 2)], 'After the receipt the goods are at the destination.');
    }

    public function testProductStockLowStockUsesAvailableQuantity(): void
    {
        $stock = new ProductStock(10, 1, 'SKU', 'Item', 'Main', 12, 10, null, true, true, 4);

        self::assertSame(4, $stock->reservedQuantity());
        self::assertSame(8, $stock->availableQuantity());
        self::assertTrue($stock->isLowStock(), '12 on hand but only 8 available is below 10.');
        self::assertFalse((new ProductStock(10, 1, 'SKU', 'Item', 'Main', 12, 10))->isLowStock());
    }

    public function testSourceAvailabilityExcludesOtherReservationsAndAddsBackTheOrdersOwn(): void
    {
        $this->products->setStockSnapshot(10, 1, 10, 0);
        $other = $this->pending([[10, 6]]);
        $this->service->approve($this->admin, $other->id());
        $this->products->setStockSnapshot(10, 1, 10, 6);
        $pending = $this->pending([[10, 5]]);
        self::assertSame([10 => 4], $this->service->sourceAvailability($this->admin, $pending), '10 on hand − 6 reserved elsewhere.');

        $approvedView = $this->orders->findById($other->id()) ?? self::fail('order');
        self::assertSame([10 => 10], $this->service->sourceAvailability($this->admin, $approvedView), 'Its own 6 reserved units count for itself.');

        $body = $this->controller($this->admin)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $pending->id()], [], []))->body();
        self::assertMatchesRegularExpression('/<td>4 [^<\s]+ <span class="status-badge status-warning">Short 1 [^<\s]+<\/span><\/td>/', $body);
        self::assertStringContainsString('Approval is refused until enough stock is available.', $body);
        self::assertStringContainsString('Not reserved yet (reserved at approval)', $body);

        $body = $this->controller($this->admin)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $other->id()], [], []))->body();
        self::assertStringNotContainsString('Short ', $body, 'An Approved order never shows a shortage.');
        self::assertStringContainsString('Reserved: 6 units held at the source since approval', $body);
        self::assertStringContainsString('includes the quantity reserved for this order', $body);
    }

    public function testControllerApprovalShortageRendersDetailWith422(): void
    {
        $order = $this->pending([[11, 9]]);

        $response = $this->controller($this->admin)->approve(new Request('POST', '/sales-orders/approve', [], ['id' => (string) $order->id()], []));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('role="alert"', $response->body());
        self::assertStringContainsString('Insufficient available stock', $response->body());
        self::assertSame(SalesOrder::STATUS_PENDING_APPROVAL, $this->orders->findById($order->id())?->status());
    }

    /** @param list<array{0: int, 1: int}> $lines */
    private function pending(array $lines): SalesOrder
    {
        $order = $this->orders->createDraft('SO-R' . (count($this->orders->all()) + 1), 1, 1, 1, array_map(
            static fn (array $line): array => ['product_id' => $line[0], 'quantity' => $line[1], 'selling_price' => 1500.0],
            $lines,
        ));
        $this->orders->submit($order->id());

        return $this->orders->findById($order->id()) ?? self::fail('order');
    }

    private function stockService(): StockService
    {
        return new StockService($this->stock, $this->ledger, $this->audit);
    }

    private function salesService(): SalesOrderService
    {
        return new SalesOrderService(
            $this->orders,
            new InMemoryDocumentSequenceRepository(),
            $this->products,
            [1 => new Customer(1, 'Customer', '', '', '', true)],
            [1 => new Warehouse(1, 'Main', '', true), 2 => new Warehouse(2, 'Second', '', true)],
            $this->stockService(),
            new OperationIdempotency(new InMemoryOperationRequestRepository()),
        );
    }

    private function controller(AuthContext $actor): SalesOrderController
    {
        $session = new SessionManager();
        $session->login($actor);

        return new SalesOrderController(
            $this->service,
            $this->orders,
            $this->products,
            [1 => new Customer(1, 'Customer', '', '', '', true)],
            [1 => new Warehouse(1, 'Main', '', true)],
            new AuthGuard($session),
        );
    }
}
