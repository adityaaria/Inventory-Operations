<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\SalesOrderController;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\StockLedgerEntry;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\Contract\DocumentTimelineRepositoryInterface;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryDocumentSequenceRepository;
use App\Repository\InMemory\InMemoryOperationRequestRepository;
use App\Repository\InMemory\InMemoryOrderExceptionRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\DocumentTimelineService;
use App\Service\OperationIdempotency;
use App\Service\OrderLinePricing;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\ShipmentHistory;
use PHPUnit\Framework\TestCase;

/** D-14 / ADR-020: sales orders ship in several shipments; the remainder stays reserved until shipped or closed. */
final class PartialFulfilmentTest extends TestCase
{
    private InMemorySalesOrderRepository $orders;
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryAuditLogRepository $audit;
    private InMemoryOrderExceptionRepository $exceptions;
    private InMemoryProductRepository $products;
    private SalesOrderService $service;
    private AuthContext $admin;
    private AuthContext $warehouse;

    protected function setUp(): void
    {
        $this->orders = new InMemorySalesOrderRepository();
        $this->stock = new InMemoryStockRepository();
        $this->stock->seed(10, 1, 20);
        $this->stock->seed(11, 1, 10);
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->exceptions = new InMemoryOrderExceptionRepository();
        $this->products = new InMemoryProductRepository([
            new Product(10, 'SKU-010', 'Widget', 'pcs', 1000.0, 1500.0, 5, 1, true),
            new Product(11, 'SKU-011', 'Gadget', 'pcs', 1000.0, 1500.0, 5, 1, true),
        ]);
        $this->service = new SalesOrderService(
            $this->orders,
            new InMemoryDocumentSequenceRepository(),
            $this->products,
            [1 => new Customer(1, 'Customer', '', '', '', true)],
            [1 => new Warehouse(1, 'Main', '', true)],
            new StockService($this->stock, $this->ledger, $this->audit),
            new OperationIdempotency(new InMemoryOperationRequestRepository()),
            new OrderLinePricing(),
            $this->exceptions,
        );
        $this->admin = new AuthContext(1, 'admin@test', User::ROLE_ADMIN);
        $this->warehouse = new AuthContext(5, 'wh@test', User::ROLE_WAREHOUSE_STAFF);
    }

    public function testPartialThenPartialThenFullShipment(): void
    {
        $order = $this->approved([[10, 8], [11, 4]]);
        [$a, $b] = $this->itemIds($order);

        $this->service->issue($this->warehouse, $order->id(), null, [$a => 3, $b => 0]);
        $after = $this->order($order->id());
        self::assertSame(SalesOrder::STATUS_PARTIALLY_FULFILLED, $after->status());
        self::assertSame([3, 0], array_map(static fn ($item): int => $item->issuedQuantity(), $after->items()));
        self::assertSame([17, 5], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)]);
        self::assertSame([10, 4], [$this->stock->quantity(11, 1), $this->stock->reservedQuantity(11, 1)]);
        self::assertCount(1, $this->ledger->entries(), 'A zero line writes no ledger row.');

        $this->service->issue($this->warehouse, $order->id(), null, [$a => 2, $b => 4]);
        self::assertSame(SalesOrder::STATUS_PARTIALLY_FULFILLED, $this->order($order->id())->status());
        self::assertSame([15, 3, 6, 0], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1), $this->stock->quantity(11, 1), $this->stock->reservedQuantity(11, 1)]);

        $this->service->issue($this->warehouse, $order->id(), null, [$a => 3]);
        $done = $this->order($order->id());
        self::assertSame(SalesOrder::STATUS_FULFILLED, $done->status());
        self::assertSame(0, $done->remainingUnits());
        self::assertSame([12, 0], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)]);
        self::assertSame(['Issue'], array_values(array_unique(array_map(static fn (StockLedgerEntry $entry): string => $entry->movementType(), $this->ledger->entries()))));
        self::assertSame([3, 2, 4, 3], array_map(static fn (StockLedgerEntry $entry): int => $entry->quantity(), $this->ledger->entries()));

        $issues = array_values(array_filter($this->audit->entries(), static fn (array $entry): bool => $entry['action'] === 'sales-orders.issue'));
        self::assertCount(3, $issues, 'One strict audit row per shipment.');
        self::assertSame([3, 9, 'PartiallyFulfilled'], [$issues[0]['metadata']['shipment_units'], $issues[0]['metadata']['remaining_units'], $issues[0]['metadata']['status_after']]);
        self::assertSame([3, 0, 'Fulfilled'], [$issues[2]['metadata']['shipment_units'], $issues[2]['metadata']['remaining_units'], $issues[2]['metadata']['status_after']]);
        self::assertSame([['product_id' => 10, 'quantity' => 2], ['product_id' => 11, 'quantity' => 4]], $issues[1]['metadata']['shipment']);
    }

    public function testIssueWithoutQuantitiesShipsEveryRemainingUnit(): void
    {
        $order = $this->approved([[10, 8], [11, 4]]);
        [$a] = $this->itemIds($order);
        $this->service->issue($this->warehouse, $order->id(), null, [$a => 5]);

        $this->service->issue($this->warehouse, $order->id());

        self::assertSame(SalesOrder::STATUS_FULFILLED, $this->order($order->id())->status());
        self::assertSame([12, 0, 6, 0], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1), $this->stock->quantity(11, 1), $this->stock->reservedQuantity(11, 1)]);
    }

    public function testOverRemainingIsRefusedWith422AndChangesNothing(): void
    {
        $order = $this->approved([[10, 8], [11, 4]]);
        [$a, $b] = $this->itemIds($order);
        $this->service->issue($this->warehouse, $order->id(), null, [$a => 6]);

        try {
            $this->service->issue($this->warehouse, $order->id(), null, [$a => 3, $b => 1]);
            self::fail('Over-remaining issue must be refused.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->statusCode());
            self::assertStringContainsString('cannot exceed the remaining quantity (2 left', $exception->getMessage());
        }
        self::assertSame([6, 0], array_map(static fn ($item): int => $item->issuedQuantity(), $this->order($order->id())->items()));
        self::assertSame([14, 2, 10, 4], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1), $this->stock->quantity(11, 1), $this->stock->reservedQuantity(11, 1)]);
        self::assertCount(1, $this->ledger->entries());
    }

    public function testZeroNegativeAndForeignLinesAreRefusedWith422(): void
    {
        $order = $this->approved([[10, 8]]);
        [$a] = $this->itemIds($order);
        foreach ([[$a => 0], [], [$a => -1], [$a + 100 => 1]] as $payload) {
            try {
                $this->service->issue($this->warehouse, $order->id(), str_repeat('c', 32), $payload);
                self::fail('Invalid issue payload accepted.');
            } catch (ValidationException $exception) {
                self::assertSame(422, $exception->statusCode());
            }
        }
        self::assertSame(SalesOrder::STATUS_APPROVED, $this->order($order->id())->status());
        self::assertSame([], $this->ledger->entries());
    }

    public function testReplayWithSamePayloadIsANoOpAndDifferentPayloadIs409(): void
    {
        $order = $this->approved([[10, 8]]);
        [$a] = $this->itemIds($order);
        $key = str_repeat('a', 32);

        $this->service->issue($this->warehouse, $order->id(), $key, [$a => 3]);
        $this->service->issue($this->warehouse, $order->id(), $key, [$a => 3]);

        self::assertCount(1, $this->ledger->entries(), 'A replay never issues twice.');
        self::assertSame(3, $this->order($order->id())->items()[0]->issuedQuantity());
        try {
            $this->service->issue($this->warehouse, $order->id(), $key, [$a => 4]);
            self::fail('Same key with a different payload must conflict.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->statusCode());
        }
        self::assertCount(1, $this->ledger->entries());

        $this->service->issue($this->warehouse, $order->id(), str_repeat('b', 32), [$a => 3]);
        self::assertCount(2, $this->ledger->entries(), 'A new key is a new shipment intent.');
    }

    public function testCloseRemainingReleasesTheReservationAndBlocksFurtherIssues(): void
    {
        $order = $this->approved([[10, 8], [11, 4]]);
        $other = $this->approved([[10, 2]]);
        [$a] = $this->itemIds($order);
        $this->service->issue($this->warehouse, $order->id(), null, [$a => 5]);
        self::assertSame([5, 4], [$this->stock->reservedQuantity(10, 1), $this->stock->reservedQuantity(11, 1)], 'Remainder 3 + other order 2; line two untouched.');

        $this->service->closeRemaining($this->admin, $order->id(), '  Customer refused the rest  ');

        self::assertSame(SalesOrder::STATUS_PARTIALLY_FULFILLED, $this->order($order->id())->status(), 'Status stays PartiallyFulfilled.');
        self::assertSame([15, 2], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)], 'Only the other order keeps its reservation.');
        self::assertSame([10, 0], [$this->stock->quantity(11, 1), $this->stock->reservedQuantity(11, 1)]);
        $closure = $this->service->remainderClosure($order->id());
        self::assertSame(['Customer refused the rest', 1, 7], [$closure['reason'] ?? null, $closure['closed_by'] ?? null, $closure['closed_units'] ?? null]);
        $entry = $this->audit->entries()[array_key_last($this->audit->entries())];
        self::assertSame('sales-orders.close-remainder', $entry['action']);
        self::assertSame([7, 7, 'Customer refused the rest'], [$entry['metadata']['closed_units'], $entry['metadata']['reservation_released_units'], $entry['metadata']['reason']]);
        self::assertCount(1, $this->ledger->entries(), 'Closing moves no goods.');
        self::assertSame([], $this->service->sourceAvailability($this->admin, $this->order($order->id())));

        foreach ([fn () => $this->service->issue($this->warehouse, $order->id(), null, [$a => 1]), fn () => $this->service->closeRemaining($this->admin, $order->id(), 'again')] as $attempt) {
            try {
                $attempt();
                self::fail('A closed remainder accepts no further issue or closure.');
            } catch (ValidationException $exception) {
                self::assertSame(422, $exception->statusCode());
            }
        }
        self::assertSame(2, $this->stock->reservedQuantity(10, 1));
    }

    public function testCloseRemainingRulesAndAuthorization(): void
    {
        $approved = $this->approved([[10, 3]]);
        foreach ([[$this->warehouse, 403], [new AuthContext(7, 'sales@test', User::ROLE_SALES), 403]] as [$actor, $status]) {
            try {
                $this->service->closeRemaining($actor, $approved->id(), 'reason');
                self::fail('Only Admin may close a remainder.');
            } catch (HttpException $exception) {
                self::assertSame($status, $exception->statusCode());
            }
        }
        try {
            $this->service->closeRemaining($this->admin, $approved->id(), 'Nothing shipped');
            self::fail('Approved with nothing shipped must be cancelled instead.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Cancel it instead', $exception->getMessage());
        }
        $this->service->issue($this->warehouse, $approved->id(), null, [$this->itemIds($approved)[0] => 1]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A reason of at most 500 characters is required.');
        $this->service->closeRemaining($this->admin, $approved->id(), '   ');
    }

    public function testCancelIsForbiddenWhenPartiallyFulfilledButReleasesAnApprovedOrder(): void
    {
        $partial = $this->approved([[10, 6]]);
        $this->service->issue($this->warehouse, $partial->id(), null, [$this->itemIds($partial)[0] => 2]);
        try {
            $this->service->rejectOrCancel($this->admin, $partial->id());
            self::fail('A partially fulfilled order cannot be cancelled.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Close the remaining fulfilment', $exception->getMessage());
        }
        self::assertSame(4, $this->stock->reservedQuantity(10, 1));

        $approved = $this->approved([[10, 5]]);
        $this->service->rejectOrCancel($this->admin, $approved->id());
        self::assertSame(SalesOrder::STATUS_CANCELLED, $this->order($approved->id())->status());
        self::assertSame(4, $this->stock->reservedQuantity(10, 1), 'Cancelling the Approved order released its 5 units only.');
    }

    public function testOnlyWarehouseAndAdminMayIssueAndRoleIsCheckedFirst(): void
    {
        $order = $this->approved([[10, 4]]);
        $locksAfterApproval = $this->stock->lockedKeys();
        try {
            $this->service->issue(new AuthContext(7, 'sales@test', User::ROLE_SALES), $order->id(), null, [$this->itemIds($order)[0] => 1]);
            self::fail('Sales must not issue.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
        }
        self::assertSame($locksAfterApproval, $this->stock->lockedKeys(), 'No stock row was locked for the refused actor.');
        $this->service->issue($this->admin, $order->id(), null, [$this->itemIds($order)[0] => 1]);
        self::assertSame(3, $this->stock->reservedQuantity(10, 1));
    }

    public function testSourceAvailabilityAddsBackTheRemainingReservation(): void
    {
        $order = $this->approved([[10, 8]]);
        $this->service->issue($this->warehouse, $order->id(), null, [$this->itemIds($order)[0] => 3]);

        // The read model mirrors the stock row: 17 on hand, 5 reserved for this order's remainder, plus 2 for another order.
        $this->products->setStockSnapshot(10, 1, 17, 7);
        self::assertSame([10 => 15], $this->service->sourceAvailability($this->admin, $this->order($order->id())), 'Available 10 plus own remainder 5.');
    }

    public function testShipmentHistoryGroupsIssueRowsPerShipment(): void
    {
        $rows = [
            new StockLedgerEntry(1, 10, 1, 'Issue', 3, 'SO', 9, 5, null, '2026-10-11 09:00:00'),
            new StockLedgerEntry(2, 11, 1, 'Issue', 1, 'SO', 9, 5, null, '2026-10-11 09:00:00'),
            new StockLedgerEntry(3, 10, 1, 'Issue', 2, 'SO', 9, 5, null, '2026-10-12 10:00:00'),
            new StockLedgerEntry(4, 10, 1, 'Adjustment', 1, 'SO', 9, 5, 1, '2026-10-12 10:00:00'),
            new StockLedgerEntry(5, 10, 1, 'Issue', 1, 'SO', 9, 5, null, '2026-10-12 10:00:00'),
        ];

        $shipments = ShipmentHistory::group($rows);

        self::assertSame([[1, 4, 2], [2, 2, 1], [3, 1, 1]], array_map(static fn (array $shipment): array => [$shipment['number'], $shipment['units'], count($shipment['lines'])], $shipments), 'A repeated product starts a new shipment even within the same second.');
        self::assertSame([], ShipmentHistory::group([]));
    }

    public function testTimelinePresentsShipmentAndClosureEvents(): void
    {
        $repo = $this->createMock(DocumentTimelineRepositoryInterface::class);
        $repo->method('document')->willReturn(['created_by' => 2]);
        $repo->method('links')->willReturn([]);
        $repo->method('events')->willReturn([
            ['title' => 'sales-orders.close-remainder', 'detail' => '', 'metadata' => '{"reason":"Customer refused","closed_units":4,"reservation_released_units":4}', 'operation_id' => 0],
            ['title' => 'sales-orders.issue', 'detail' => '', 'metadata' => '{"shipment_units":3,"remaining_units":4,"reservation_released_units":3}', 'operation_id' => 0],
            ['title' => 'sales-orders.issue', 'detail' => '', 'metadata' => '{"movement_count":1,"reservation_released_units":5}', 'operation_id' => 0],
            ['title' => 'sales-orders.issue', 'detail' => '', 'metadata' => '{"movement_count":2}', 'operation_id' => 0],
        ]);

        $events = (new DocumentTimelineService($repo))->forDocument($this->admin, 'SO', 9)['events'];

        self::assertSame(['SO remaining fulfilment closed', 'Released reservation of 4 units', 'Customer refused'], [$events[0]['title'], $events[0]['detail'], $events[0]['reason']]);
        self::assertSame(['Goods issued (shipment)', 'Shipped 3 units; 4 units still to ship'], [$events[1]['title'], $events[1]['detail']]);
        self::assertSame('Shipped 5 units', $events[2]['detail'], 'Older issue rows carry only the released units.');
        self::assertSame('Goods issued; quantities in the Issue ledger rows', $events[3]['detail'], 'Rows written before D-12 carry no units.');
    }

    public function testDetailPageShowsColumnsInputsHistoryAndClosure(): void
    {
        $order = $this->approved([[10, 8], [11, 4]]);
        [$a, $b] = $this->itemIds($order);

        $warehouseBody = $this->controller($this->warehouse)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('<th scope="col">Ordered</th><th scope="col">Issued</th><th scope="col">Remaining</th>', $warehouseBody);
        self::assertStringContainsString('name="quantities[' . $a . ']" type="number" min="0" max="8"', $warehouseBody);
        self::assertStringContainsString('id="issue-form"', $warehouseBody);
        self::assertStringNotContainsString('Close Remaining Fulfilment', $warehouseBody);

        $response = $this->controller($this->warehouse)->issue(new Request('POST', '/sales-orders/issue', [], ['id' => (string) $order->id(), 'operation_key' => str_repeat('d', 32), 'quantities' => [(string) $a => '3', (string) $b => '']], []));
        self::assertSame(302, $response->statusCode());
        self::assertSame(SalesOrder::STATUS_PARTIALLY_FULFILLED, $this->order($order->id())->status());

        $zero = $this->controller($this->warehouse)->issue(new Request('POST', '/sales-orders/issue', [], ['id' => (string) $order->id(), 'operation_key' => str_repeat('e', 32), 'quantities' => [(string) $a => '0']], []));
        self::assertSame(422, $zero->statusCode());
        self::assertStringContainsString('at least one line', $zero->body());

        $salesBody = $this->controller(new AuthContext(1, 'creator@test', User::ROLE_SALES))->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringNotContainsString('name="quantities[', $salesBody, 'Sales sees progress but no issue inputs.');
        self::assertStringContainsString('Shipment History', $salesBody);
        self::assertStringContainsString('Issued 3 of 12 units', $salesBody);

        $adminBody = $this->controller($this->admin)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('data-dialog-open="close-remaining-dialog"', $adminBody);
        self::assertStringNotContainsString('action="/sales-orders/cancel"', $adminBody, 'Cancel is hidden for a partially fulfilled order.');
        self::assertStringContainsString('status-partially-fulfilled', $adminBody);

        $blank = $this->controller($this->admin)->closeRemaining(new Request('POST', '/sales-orders/close-remaining', [], ['id' => (string) $order->id(), 'reason' => ''], []));
        self::assertSame(422, $blank->statusCode());
        self::assertMatchesRegularExpression('/id="close-remaining-dialog"\s*>/', $blank->body(), 'The reason dialog reopens.');

        $closed = $this->controller($this->admin)->closeRemaining(new Request('POST', '/sales-orders/close-remaining', [], ['id' => (string) $order->id(), 'reason' => 'Customer refused the rest'], []));
        self::assertSame(302, $closed->statusCode());
        $closedBody = $this->controller($this->admin)->show(new Request('GET', '/sales-orders/show', ['id' => (string) $order->id()], [], []))->body();
        self::assertStringContainsString('Remaining fulfilment closed', $closedBody);
        self::assertStringContainsString('Released at closure (9 units)', $closedBody);
        self::assertStringNotContainsString('id="issue-form"', $closedBody);
    }

    /** @param list<array{0: int, 1: int}> $lines */
    private function approved(array $lines): SalesOrder
    {
        $order = $this->orders->createDraft('SO-P' . (count($this->orders->all()) + 1), 1, 1, 1, array_map(
            static fn (array $line): array => ['product_id' => $line[0], 'quantity' => $line[1], 'selling_price' => 1500.0],
            $lines,
        ));
        $this->orders->submit($order->id());
        $this->service->approve($this->admin, $order->id());

        return $this->order($order->id());
    }

    private function order(int $id): SalesOrder
    {
        return $this->orders->findById($id) ?? self::fail('order');
    }

    /** @return list<int> */
    private function itemIds(SalesOrder $order): array
    {
        return array_map(static fn ($item): int => $item->id(), $order->items());
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
            null,
            $this->ledger,
        );
    }
}
