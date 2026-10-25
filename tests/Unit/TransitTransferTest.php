<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\BusinessOperationController;
use App\Entity\ProductStock;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\Contract\DocumentTimelineRepositoryInterface;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Repository\Contract\WorkQueueRepositoryInterface;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryBusinessOperationRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Security\AuthGuard;
use App\Security\SessionManager;
use App\Service\BusinessOperationService;
use App\Service\DocumentTimelineService;
use App\Service\StockService;
use App\Service\WorkQueueService;
use PHPUnit\Framework\TestCase;

/** D-15 / ADR-021: transfers are dispatched (goods in transit) and then received, with damage and shortage written off. */
final class TransitTransferTest extends TestCase
{
    private InMemoryStockRepository $balances;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryAuditLogRepository $audit;
    private BusinessOperationService $service;
    private AuthContext $creator;
    private AuthContext $reviewer;
    private AuthContext $warehouse;

    protected function setUp(): void
    {
        $this->balances = new InMemoryStockRepository();
        // Product 1: 10 on hand at the source, 4 reserved for an approved sales order → 6 available. Product 2: 5 free.
        $this->balances->seed(1, 1, 10, 4);
        $this->balances->seed(1, 2, 0);
        $this->balances->seed(2, 1, 5);
        $this->balances->seed(2, 2, 1);
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $pairs = ['1:1' => ['quantity' => 10], '1:2' => ['quantity' => 0], '2:1' => ['quantity' => 5], '2:2' => ['quantity' => 1], '1:3' => ['quantity' => 0]];
        $this->service = new BusinessOperationService(new InMemoryBusinessOperationRepository($pairs), new StockService($this->balances, $this->ledger, $this->audit), $this->audit);
        $this->creator = new AuthContext(1, 'creator@example.test', 'Admin');
        $this->reviewer = new AuthContext(2, 'reviewer@example.test', 'Admin');
        $this->warehouse = new AuthContext(4, 'warehouse@example.test', 'WarehouseStaff');
    }

    /** @param list<array{product_id:int,quantity:int}> $items */
    private function approvedTransfer(array $items = [['product_id' => 1, 'quantity' => 6]]): int
    {
        $id = $this->service->propose($this->creator, 'Transfer', 1, 2, 'Rebalance', $items);
        $this->service->decide($this->reviewer, $id, 'Approved', 'Checked');

        return $id;
    }

    private function dispatchedTransfer(): int
    {
        $id = $this->approvedTransfer([['product_id' => 1, 'quantity' => 6], ['product_id' => 2, 'quantity' => 5]]);
        $this->service->dispatch($this->warehouse, $id);

        return $id;
    }

    /** @return list<string> */
    private function actions(): array
    {
        return array_map(static fn (array $entry): string => $entry['action'], $this->audit->entries());
    }

    public function testDispatchPutsGoodsInTransitUsingAvailableStockOnly(): void
    {
        $id = $this->approvedTransfer();
        $this->service->dispatch($this->warehouse, $id);

        $operation = $this->service->show($this->creator, $id);
        self::assertSame('Dispatched', $operation['status']);
        self::assertSame(4, $operation['dispatched_by']);
        self::assertSame(6, $operation['items'][0]['dispatched_quantity']);
        self::assertNull($operation['items'][0]['received_quantity']);
        self::assertSame([4, 4, 0], [$this->balances->quantity(1, 1), $this->balances->reservedQuantity(1, 1), $this->balances->quantity(1, 2)], 'Reservation kept; nothing at the destination yet.');
        self::assertCount(1, $this->ledger->entries());
        self::assertSame([-6, 'TRANSFER', 1], [$this->ledger->entries()[0]->delta(), $this->ledger->entries()[0]->referenceType(), $this->ledger->entries()[0]->warehouseId()]);
        $audit = $this->audit->entries()[array_key_last($this->audit->entries())];
        self::assertSame(['inventory-operations.dispatch', 'TRANSFER', 6, 2], [$audit['action'], $audit['entity_type'], $audit['metadata']['dispatched_units'], $audit['metadata']['destination_id']]);
    }

    public function testDispatchIsRefusedWhenItWouldConsumeReservedStock(): void
    {
        $id = $this->approvedTransfer([['product_id' => 1, 'quantity' => 7]]);
        try {
            $this->service->dispatch($this->warehouse, $id);
            self::fail('Dispatch consumed reserved stock.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('available 6', $exception->getMessage());
        }
        self::assertSame('Approved', $this->service->show($this->creator, $id)['status']);
        self::assertSame([10, 4], [$this->balances->quantity(1, 1), $this->balances->reservedQuantity(1, 1)]);
        self::assertSame([], $this->ledger->entries());
        self::assertNotContains('inventory-operations.dispatch', $this->actions());
    }

    public function testPostingATransferDispatchesItAndRepeatsAreNoOps(): void
    {
        $id = $this->approvedTransfer();
        $this->service->post($this->creator, $id);
        $this->service->post($this->creator, $id);
        $this->service->dispatch($this->warehouse, $id);
        self::assertSame('Dispatched', $this->service->show($this->creator, $id)['status']);
        self::assertCount(1, $this->ledger->entries());
        $this->service->receive($this->warehouse, $id);
        $this->service->receive($this->warehouse, $id, [1 => ['good' => 1, 'damaged' => 0]], 'Different payload');
        $this->service->dispatch($this->warehouse, $id);
        $this->service->post($this->creator, $id);
        self::assertSame('Received', $this->service->show($this->creator, $id)['status']);
        self::assertCount(2, $this->ledger->entries(), 'One dispatch row and one receipt row only.');
        self::assertSame(6, $this->balances->quantity(1, 2));
        self::assertSame(1, count(array_filter($this->actions(), static fn (string $action): bool => $action === 'inventory-operations.receive')));
    }

    public function testReceiveAllGoodEntersTheDestination(): void
    {
        $id = $this->dispatchedTransfer();
        $this->service->receive($this->warehouse, $id);

        $operation = $this->service->show($this->creator, $id);
        self::assertSame(['Received', 4, null], [$operation['status'], $operation['received_by'], $operation['receipt_reason']]);
        self::assertSame([6, 0, 0], [$operation['items'][0]['received_quantity'], $operation['items'][0]['damaged_quantity'], $operation['items'][0]['short_quantity']]);
        self::assertSame([6, 6], [$this->balances->quantity(1, 2), $this->balances->quantity(2, 2)]);
        self::assertSame([-6, -5, 6, 5], array_map(static fn ($entry): ?int => $entry->delta(), $this->ledger->entries()));
    }

    public function testReceiveWithDamageAndShortageWritesOffAndNeedsAReason(): void
    {
        $id = $this->dispatchedTransfer();
        $lines = [1 => ['good' => '4', 'damaged' => '1'], 2 => ['good' => '5', 'damaged' => '0']];
        try {
            $this->service->receive($this->warehouse, $id, $lines, '  ');
            self::fail('Discrepancy accepted without a reason.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('reason', $exception->getMessage());
        }
        self::assertSame('Dispatched', $this->service->show($this->creator, $id)['status']);
        self::assertCount(2, $this->ledger->entries());
        self::assertSame(0, $this->balances->quantity(1, 2));

        $this->service->receive($this->warehouse, $id, $lines, 'One carton crushed, one missing');
        $operation = $this->service->show($this->creator, $id);
        self::assertSame('Received', $operation['status']);
        self::assertSame('One carton crushed, one missing', $operation['receipt_reason']);
        self::assertSame([4, 1, 1], [$operation['items'][0]['received_quantity'], $operation['items'][0]['damaged_quantity'], $operation['items'][0]['short_quantity']]);
        self::assertSame([4, 6], [$this->balances->quantity(1, 2), $this->balances->quantity(2, 2)], 'Damaged and short units never enter stock.');
        // Stock = Σ ledger per touched pair, with the write-off on the document, not in the ledger.
        foreach ([[1, 1, 4], [1, 2, 4], [2, 1, 0], [2, 2, 6]] as [$product, $warehouse, $expected]) {
            self::assertSame($expected, $this->balances->quantity($product, $warehouse));
        }
        self::assertSame([-6, -5, 4, 5], array_map(static fn ($entry): ?int => $entry->delta(), $this->ledger->entries()));
        $audit = $this->audit->entries()[array_key_last($this->audit->entries())];
        self::assertSame(['inventory-operations.receive', 9, 1, 1, 2, 'One carton crushed, one missing'], [$audit['action'], $audit['metadata']['received_units'], $audit['metadata']['damaged_units'], $audit['metadata']['short_units'], $audit['metadata']['written_off_units'], $audit['metadata']['reason']]);
    }

    public function testALostShipmentIsReceivedWithZeroGoodUnits(): void
    {
        $id = $this->approvedTransfer();
        $this->service->dispatch($this->warehouse, $id);
        $this->service->receive($this->warehouse, $id, [1 => ['good' => 0, 'damaged' => 0]], 'Truck lost in transit; police report filed');
        $operation = $this->service->show($this->creator, $id);
        self::assertSame(['Received', 0, 6], [$operation['status'], $operation['items'][0]['received_quantity'], $operation['items'][0]['short_quantity']]);
        self::assertSame(0, $this->balances->quantity(1, 2));
        self::assertCount(1, $this->ledger->entries(), 'No receipt row without good units.');
    }

    public function testReceiveRejectsInvalidQuantitiesWithoutChangingAnything(): void
    {
        $id = $this->dispatchedTransfer();
        $cases = [
            'over dispatched' => [[1 => ['good' => 6, 'damaged' => 1], 2 => ['good' => 5, 'damaged' => 0]], 'cannot exceed'],
            'foreign line' => [[1 => ['good' => 6, 'damaged' => 0], 2 => ['good' => 5, 'damaged' => 0], 3 => ['good' => 1, 'damaged' => 0]], 'does not belong'],
            'missing line' => [[1 => ['good' => 6, 'damaged' => 0]], 'every line'],
            'negative' => [[1 => ['good' => '-1', 'damaged' => 0], 2 => ['good' => 5, 'damaged' => 0]], 'whole numbers'],
            'not a number' => [[1 => ['good' => 'x', 'damaged' => 0], 2 => ['good' => 5, 'damaged' => 0]], 'whole numbers'],
            'empty' => [[], 'every line'],
        ];
        foreach ($cases as $name => [$lines, $message]) {
            try {
                $this->service->receive($this->warehouse, $id, $lines, 'reason');
                self::fail($name . ' accepted');
            } catch (ValidationException $exception) {
                self::assertStringContainsString($message, $exception->getMessage(), $name);
            }
        }
        self::assertSame('Dispatched', $this->service->show($this->creator, $id)['status']);
        self::assertCount(2, $this->ledger->entries());
    }

    public function testReceiveBeforeDispatchAndDispatchBeforeApprovalAreRefused(): void
    {
        $pending = $this->service->propose($this->creator, 'Transfer', 1, 2, 'Rebalance', [['product_id' => 2, 'quantity' => 1]]);
        $this->expectExceptionMessageMatches('/Independent approval/');
        try {
            $this->service->receive($this->warehouse, $pending);
            self::fail('Received before dispatch.');
        } catch (ValidationException $exception) {
            self::assertSame('Only a dispatched transfer can be received.', $exception->getMessage());
        }
        $this->service->dispatch($this->warehouse, $pending);
    }

    public function testDispatchedTransferCannotBeCancelledButApprovedCan(): void
    {
        $id = $this->dispatchedTransfer();
        foreach ([$this->creator, $this->reviewer] as $actor) {
            try {
                $this->service->decide($actor, $id, 'Cancelled', 'Changed mind');
                self::fail('Dispatched transfer cancelled.');
            } catch (ValidationException $exception) {
                self::assertSame('This operation cannot be cancelled.', $exception->getMessage());
            }
        }
        $approved = $this->approvedTransfer([['product_id' => 2, 'quantity' => 0 + 1]]);
        $this->service->decide($this->creator, $approved, 'Cancelled', 'No longer needed');
        self::assertSame('Cancelled', $this->service->show($this->creator, $approved)['status']);
    }

    public function testOnlyAdminAndWarehouseStaffMayDispatchOrReceiveAndOnlyTransfers(): void
    {
        $id = $this->approvedTransfer();
        $sales = new AuthContext(3, 'sales@example.test', 'Sales');
        foreach (['dispatch', 'receive'] as $method) {
            try {
                $this->service->{$method}($sales, $id);
                self::fail('Sales may ' . $method);
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->statusCode());
            }
        }
        self::assertSame([], $this->ledger->entries());
        $adjustment = $this->service->propose($this->creator, 'Adjustment', 1, null, 'Counted', [['product_id' => 2, 'quantity' => 4, 'baseline' => 5]]);
        $this->service->decide($this->reviewer, $adjustment, 'Approved', 'Checked');
        foreach (['dispatch' => 'Only transfers are dispatched.', 'receive' => 'Only transfers are received.'] as $method => $message) {
            try {
                $this->service->{$method}($this->warehouse, $adjustment);
                self::fail('Adjustment ' . $method);
            } catch (ValidationException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
        $this->service->post($this->warehouse, $adjustment);
        self::assertSame('Posted', $this->service->show($this->creator, $adjustment)['status'], 'Other kinds still post in one step.');
    }

    public function testInTransitListShowsOpenDispatchedTransfersOnly(): void
    {
        $this->balances->seed(2, 1, 10);
        $open = $this->dispatchedTransfer();
        $done = $this->approvedTransfer([['product_id' => 2, 'quantity' => 1]]);
        $this->service->dispatch($this->warehouse, $done);
        $this->service->receive($this->warehouse, $done);
        $result = $this->service->inTransit($this->warehouse, []);
        self::assertSame(1, $result->total());
        self::assertSame([$open, 11], [$result->items()[0]['id'], $result->items()[0]['units']]);
        $this->expectException(HttpException::class);
        $this->service->inTransit(new AuthContext(3, 'sales@example.test', 'Sales'), []);
    }

    private function controller(AuthContext $actor): BusinessOperationController
    {
        $session = new SessionManager();
        $session->login($actor);
        $warehouses = $this->createMock(WarehouseRepositoryInterface::class);
        $warehouses->method('all')->willReturn([]);

        return new BusinessOperationController($this->service, new AuthGuard($session), new InMemoryProductRepository(), $warehouses);
    }

    public function testControllerDispatchReceiveAndPagesShowTransitState(): void
    {
        $id = $this->approvedTransfer([['product_id' => 1, 'quantity' => 6], ['product_id' => 2, 'quantity' => 5]]);
        $page = $this->controller($this->warehouse)->show(new Request('GET', '/inventory-operations/show', ['id' => (string) $id], [], []));
        self::assertStringContainsString('Dispatch Transfer', $page->body());
        self::assertStringNotContainsString('Post Stock Changes', $page->body());

        $response = $this->controller($this->warehouse)->dispatch(new Request('POST', '/inventory-operations/dispatch', [], ['id' => (string) $id], []));
        self::assertSame([302, '/inventory-operations/show?id=' . $id], [$response->statusCode(), $response->headers()['Location']]);
        $page = $this->controller($this->warehouse)->show(new Request('GET', '/inventory-operations/show', ['id' => (string) $id], [], []))->body();
        self::assertStringContainsString('In transit (Dispatched)', $page);
        self::assertStringContainsString('name="received[1]"', $page);
        self::assertStringContainsString('Receive Transfer', $page);
        self::assertStringNotContainsString('Cancel Proposal', $page);
        $list = $this->controller($this->warehouse)->inTransit(new Request('GET', '/inventory-operations/in-transit', [], [], []))->body();
        self::assertStringContainsString('Transfer #' . $id, $list);

        $failed = $this->controller($this->warehouse)->receive(new Request('POST', '/inventory-operations/receive', [], ['id' => (string) $id, 'received' => ['1' => '5', '2' => '5'], 'damaged' => ['1' => '1', '2' => '0'], 'receipt_reason' => ''], []));
        self::assertSame(422, $failed->statusCode());
        self::assertStringContainsString('Enter a reason for the damaged or missing units', $failed->body());
        self::assertStringContainsString('value="5"', $failed->body(), 'Entered quantities are kept.');

        $done = $this->controller($this->warehouse)->receive(new Request('POST', '/inventory-operations/receive', [], ['id' => (string) $id, 'received' => ['1' => '5', '2' => '5'], 'damaged' => ['1' => '1', '2' => '0'], 'receipt_reason' => 'Crushed carton'], []));
        self::assertSame(302, $done->statusCode());
        $page = $this->controller($this->warehouse)->show(new Request('GET', '/inventory-operations/show', ['id' => (string) $id], [], []))->body();
        self::assertStringContainsString('Received with discrepancy', $page);
        self::assertStringContainsString('Receipt discrepancy reason: Crushed carton', $page);
        self::assertStringNotContainsString('Receive Transfer', $page);
    }

    public function testWorkQueueOffersDispatchAndReceiveTasks(): void
    {
        $queue = $this->createMock(WorkQueueRepositoryInterface::class);
        $queue->method('counts')->willReturn(['TransferDispatch' => 1, 'TransferReceive' => 1]);
        $queue->method('page')->willReturn([
            ['task_type' => 'TransferDispatch', 'id' => 7, 'label' => 'Transfer #7', 'status' => 'Approved', 'created_at' => '2026-10-01', 'action' => 'Dispatch approved transfer', 'age_days' => 1],
            ['task_type' => 'TransferReceive', 'id' => 8, 'label' => 'Transfer #8', 'status' => 'Dispatched', 'created_at' => '2026-10-01', 'action' => 'Receive transfer', 'age_days' => 1],
        ]);
        $result = (new WorkQueueService($queue))->search($this->warehouse, ['type' => 'TransferReceive']);
        self::assertSame(['Transfer dispatch', 'Transfer receipt'], [$result['types']['TransferDispatch'], $result['types']['TransferReceive']]);
        self::assertSame(['/inventory-operations/show?id=7', '/inventory-operations/show?id=8'], array_column($result['result']->items(), 'url'));
    }

    public function testTimelineDescribesDispatchAndReceipt(): void
    {
        $repo = $this->createMock(DocumentTimelineRepositoryInterface::class);
        $repo->method('document')->willReturn(['created_by' => 1]);
        $repo->method('links')->willReturn([]);
        $repo->method('events')->willReturn([
            ['title' => 'inventory-operations.receive', 'detail' => '', 'metadata' => '{"received_units":9,"damaged_units":1,"short_units":1,"reason":"Crushed carton"}', 'operation_id' => 0],
            ['title' => 'inventory-operations.receive', 'detail' => '', 'metadata' => '{"received_units":1,"damaged_units":0,"short_units":0}', 'operation_id' => 0],
            ['title' => 'inventory-operations.dispatch', 'detail' => '', 'metadata' => '{"dispatched_units":11}', 'operation_id' => 0],
        ]);
        $events = (new DocumentTimelineService($repo))->forDocument($this->creator, 'Operation', 3)['events'];
        self::assertSame(['Transfer received', 'Received 9 good units; written off 1 damaged and 1 short', 'Crushed carton'], [$events[0]['title'], $events[0]['detail'], $events[0]['reason']]);
        self::assertSame('Received 1 good unit', $events[1]['detail']);
        self::assertSame(['Transfer dispatched (in transit)', '11 units left the source warehouse and are in transit'], [$events[2]['title'], $events[2]['detail']]);
    }

    public function testInTransitInboundIsInformationalAndNeverAvailable(): void
    {
        $stock = new ProductStock(1, 2, 'SKU', 'Product', 'Destination', 3, 5, null, true, true, 0, 40);
        self::assertSame([40, 3], [$stock->inTransitInbound(), $stock->availableQuantity()]);
        self::assertTrue($stock->isLowStock(), 'Low stock stays on available; inbound goods do not hide it.');
    }
}
