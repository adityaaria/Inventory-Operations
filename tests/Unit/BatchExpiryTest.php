<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\LotController;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\ProductStock;
use App\Entity\StockLedgerEntry;
use App\Entity\StockLot;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryBusinessOperationRepository;
use App\Repository\InMemory\InMemoryDocumentSequenceRepository;
use App\Repository\InMemory\InMemoryLotQueryRepository;
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
use App\Service\LotService;
use App\Service\LotSplit;
use App\Service\LotTarget;
use App\Service\OperationIdempotency;
use App\Service\OrderLinePricing;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockDelta;
use App\Service\StockMovement;
use App\Service\StockService;
use App\Support\BusinessDate;
use App\Support\LotStatus;
use App\Validation\LotInput;
use PHPUnit\Framework\TestCase;

/** D-17 / ADR-023: lots, expiry, FEFO issue, expired stock never sold, per-lot transfers, returns and write-off. */
final class BatchExpiryTest extends TestCase
{
    private const TODAY = '2026-10-12';

    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryAuditLogRepository $audit;
    private StockService $stocks;
    private InMemoryProductRepository $products;
    private AuthContext $admin;
    private AuthContext $reviewer;
    private AuthContext $warehouse;
    private AuthContext $sales;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockRepository(self::TODAY);
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->stocks = new StockService($this->stock, $this->ledger, $this->audit);
        $this->products = new InMemoryProductRepository([
            new Product(10, 'SKU-010', 'Susu UHT', 'pcs', 1000.0, 1500.0, 5, 1, true, true),
            new Product(11, 'SKU-011', 'Sabun', 'pcs', 1000.0, 1500.0, 5, 1, true, false),
        ]);
        $this->stock->configureProduct(10, true, '1000.00');
        $this->stock->configureProduct(11, false, '1000.00');
        $this->admin = new AuthContext(1, 'admin@test', User::ROLE_ADMIN);
        $this->reviewer = new AuthContext(2, 'reviewer@test', User::ROLE_ADMIN);
        $this->warehouse = new AuthContext(5, 'wh@test', User::ROLE_WAREHOUSE_STAFF);
        $this->sales = new AuthContext(7, 'sales@test', User::ROLE_SALES);
    }

    // ---- Receipt ------------------------------------------------------------------------------------------------

    public function testReceiptSplitsIntoLotsWithCostPerBaseAndOneLedgerRowPerLot(): void
    {
        [$po, $orders] = $this->purchasing();
        $order = $po->createDraft($this->admin, 1, 1, [['product_id' => 10, 'quantity' => 5, 'price' => '24000.00', 'price_reason' => 'Harga kontrak principal']]);
        $po->markOrdered($this->admin, $order->id());
        $item = $order->items()[0]->id();
        $po->receive($this->warehouse, $order->id(), [$item => 5], null, [$item => [
            ['lot_code' => 'B2610A', 'expiry_date' => '2027-03-01', 'quantity' => 3],
            ['lot_code' => 'B2609Z', 'expiry_date' => '2027-01-15', 'quantity' => 2],
        ]]);

        $lots = $this->stock->lots(10, 1);
        self::assertSame(['B2609Z', 'B2610A'], array_map(static fn (StockLot $lot): string => $lot->code(), $lots), 'FEFO order: earliest expiry first.');
        self::assertSame([2, 3], array_map(static fn (StockLot $lot): int => $lot->quantity(), $lots));
        self::assertSame('24000.00', $lots[0]->unitCost(), 'Base unit (factor 1): cost per base = line price.');
        self::assertSame(5, $this->stock->quantity(10, 1));
        self::assertSame([3, 2], array_map(static fn (StockLedgerEntry $entry): int => $entry->quantity(), $this->ledger->entries()));
        self::assertNotContains(null, array_map(static fn (StockLedgerEntry $entry): ?int => $entry->lotId(), $this->ledger->entries()));
        self::assertSame('Received', $orders->findById($order->id())->status());
    }

    public function testReceiptRefusesSplitsThatDoNotAddUpMissingExpiryAndExpiredGoods(): void
    {
        [$po] = $this->purchasing();
        $order = $po->createDraft($this->admin, 1, 1, [['product_id' => 10, 'quantity' => 5], ['product_id' => 11, 'quantity' => 4]]);
        $po->markOrdered($this->admin, $order->id());
        [$tracked, $plain] = array_map(static fn ($item): int => $item->id(), $order->items());

        foreach ([
            [[$tracked => 5], [$tracked => [['lot_code' => 'A1', 'expiry_date' => '2027-01-01', 'quantity' => 4]]], 'must add up'],
            [[$tracked => 5], null, 'tracks expiry'],
            [[$tracked => 5], [$tracked => [['lot_code' => 'A1', 'expiry_date' => null, 'quantity' => 5]]], 'tracks expiry'],
            [[$tracked => 5], [$tracked => [['lot_code' => 'A1', 'expiry_date' => '2026-10-11', 'quantity' => 5]]], 'expired'],
            [[$tracked => 5], [$tracked => [['lot_code' => 'LEGACY', 'expiry_date' => '2027-01-01', 'quantity' => 5]]], 'reserved'],
        ] as [$quantities, $lots, $message]) {
            try {
                $po->receive($this->warehouse, $order->id(), $quantities, null, $lots);
                self::fail('Expected 422: ' . $message);
            } catch (ValidationException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        self::assertSame([], $this->ledger->entries(), 'Nothing was received.');
        self::assertSame([], $this->stock->lots(10, 1));

        // A non-tracked line without splits becomes one lot named after the order number, no expiry; today's expiry is fine.
        $po->receive($this->warehouse, $order->id(), [$plain => 4]);
        $po->receive($this->warehouse, $order->id(), [$tracked => 5], null, [$tracked => [['lot_code' => 'TODAY', 'expiry_date' => self::TODAY, 'quantity' => 5]]]);
        self::assertSame([$order->orderNumber()], array_map(static fn (StockLot $lot): string => $lot->code(), $this->stock->lots(11, 1)));
        self::assertNull($this->stock->lots(11, 1)[0]->expiryDate());
        self::assertFalse($this->stock->lots(10, 1)[0]->isExpired(), 'A lot is sellable on its expiry date.');
    }

    public function testSameLotCodeExpiryAndCostMergesIntoOneLot(): void
    {
        $this->stocks->receive(1, [new StockMovement(10, 3, [new LotSplit('M1', '2027-01-01', 3, '10.00')])], 1, 'PO', 1);
        $this->stocks->receive(1, [new StockMovement(10, 2, [new LotSplit('M1', '2027-01-01', 2, '10.00')])], 1, 'PO', 2);
        $this->stocks->receive(1, [new StockMovement(10, 1, [new LotSplit('M1', '2027-01-01', 1, '11.00')])], 1, 'PO', 3);
        $lots = $this->stock->lots(10, 1);
        self::assertSame([5, 1], array_map(static fn (StockLot $lot): int => $lot->quantity(), $lots), 'A different cost is a separate cost layer.');
        self::assertSame([1, 2], array_map(static fn (StockLot $lot): int => $lot->sequence(), $lots));
    }

    public function testCostPerBaseIsLinePriceDividedByFactorInCents(): void
    {
        self::assertSame('1000.00', LotInput::costPerBase(24000.0, 24));
        self::assertSame('3333.33', LotInput::costPerBase(10000.0, 3));
        self::assertSame('6666.67', LotInput::costPerBase(20000.0, 3), 'Half-up to whole cents.');
        self::assertSame('0.01', LotInput::costPerBase(0.05, 10), 'Half a cent rounds up.');
    }

    // ---- FEFO issue and expired stock -----------------------------------------------------------------------------

    public function testIssueAllocatesFefoOverNonExpiredLots(): void
    {
        $a = $this->stock->seedLot(10, 1, 'A-NOV', '2026-11-01', 3);
        $b = $this->stock->seedLot(10, 1, 'B-OCT', '2026-10-20', 2);
        $c = $this->stock->seedLot(10, 1, 'C-NONE', null, 5);
        $x = $this->stock->seedLot(10, 1, 'X-OLD', '2026-10-01', 4);
        $this->stocks->issue(1, [new StockMovement(10, 4)], 1, 'SO', 9);
        self::assertSame([[$b, 2], [$a, 2]], array_map(static fn (StockLedgerEntry $entry): array => [$entry->lotId(), $entry->quantity()], $this->ledger->entries()));
        self::assertSame([4, 0, 1, 5], [$this->lot($x)->quantity(), $this->lot($b)->quantity(), $this->lot($a)->quantity(), $this->lot($c)->quantity()], 'The expired lot is untouched.');
        self::assertSame(10, $this->stock->quantity(10, 1));
    }

    public function testExpiredLotsAreNeverIssuedOrCountedAsAvailable(): void
    {
        $this->stock->seedLot(10, 1, 'OLD', '2026-10-11', 6);
        $this->stock->seedLot(10, 1, 'NEW', '2027-01-01', 2);
        try {
            $this->stocks->issue(1, [new StockMovement(10, 3)], 1, 'SO', 9);
            self::fail('Expired stock must not be issued.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Insufficient available stock.', $exception->getMessage());
        }
        self::assertSame([], $this->ledger->entries());
        $stock = new ProductStock(10, 1, 'SKU-010', 'Susu', 'Main', 8, 5, null, true, true, 0, 0, 6);
        self::assertSame([2, 2, 8], [$stock->sellableQuantity(), $stock->availableQuantity(), $stock->availableIncludingExpired()]);
        self::assertTrue($stock->isLowStock(), 'Low stock compares available without expired lots: 2 < 5.');
    }

    public function testApprovalUsesAvailableWithoutExpiredStockAndIssueRefusesOverReservation(): void
    {
        [$so, $orders] = $this->selling();
        $this->stock->seedLot(10, 1, 'OLD', '2026-10-11', 5);
        $this->stock->seedLot(10, 1, 'NEW', '2026-10-20', 4);
        $tooMuch = $this->pending($so, 10, 5);
        try {
            $so->approve($this->admin, $tooMuch->id());
            self::fail('Only 4 non-expired units are available.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Expired lots do not count', $exception->getMessage());
        }
        self::assertSame(0, $this->stock->reservedQuantity(10, 1));

        $fits = $this->pending($so, 10, 4);
        $so->approve($this->admin, $fits->id());
        self::assertSame(4, $this->stock->reservedQuantity(10, 1));

        // Time passes: the NEW lot expires after approval. The reservation now exceeds sellable stock; issue is refused.
        $this->stock->setToday('2026-10-21');
        try {
            $so->issue($this->warehouse, $fits->id());
            self::fail('Expired stock must not be shipped.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('expired after the order was approved', $exception->getMessage());
        }
        self::assertSame([9, 4], [$this->stock->quantity(10, 1), $this->stock->reservedQuantity(10, 1)], 'Nothing changed; nothing is cancelled automatically.');
        self::assertSame('Approved', $orders->findById($fits->id())->status());
    }

    public function testPartialShipmentsFollowFefo(): void
    {
        [$so] = $this->selling();
        $late = $this->stock->seedLot(10, 1, 'LATE', '2027-02-01', 6);
        $early = $this->stock->seedLot(10, 1, 'EARLY', '2026-12-01', 3);
        $order = $this->pending($so, 10, 8);
        $so->approve($this->admin, $order->id());
        $item = $order->items()[0]->id();
        $so->issue($this->warehouse, $order->id(), null, [$item => 2]);
        $so->issue($this->warehouse, $order->id(), null, [$item => 6]);
        self::assertSame([[$early, 2], [$early, 1], [$late, 5]], array_map(static fn (StockLedgerEntry $entry): array => [$entry->lotId(), $entry->quantity()], $this->ledger->entries()));
        $issue = array_values(array_filter($this->audit->entries(), static fn (array $entry): bool => $entry['action'] === 'sales-orders.issue'));
        self::assertSame(['EARLY', 'LATE'], array_column($issue[1]['metadata']['lots'], 'lot_code'), 'The shipment audit lists the lots used.');
        self::assertSame([0, 1], [$this->lot($early)->quantity(), $this->lot($late)->quantity()]);
    }

    public function testLotsAreLockedOnlyAfterEveryPair(): void
    {
        $this->stock->seedLot(10, 1, 'A', '2027-01-01', 3);
        $this->stock->seedLot(11, 1, 'B', null, 3);
        $this->stocks->issue(1, [new StockMovement(11, 1), new StockMovement(10, 1)], 1, 'SO', 9);
        self::assertSame(['1:10', '1:11'], $this->stock->lockedKeys());
        self::assertSame(['1:10', '1:11'], $this->stock->lockedLotKeys(), 'Lots follow the same deterministic pair order.');
    }

    // ---- Transfers --------------------------------------------------------------------------------------------------

    public function testTransferDispatchPicksFefoLotsAndReceiptKeepsCodeExpiryAndCostPerLot(): void
    {
        $repository = new InMemoryBusinessOperationRepository(['10:1' => ['quantity' => 9], '10:2' => ['quantity' => 0]]);
        $service = new BusinessOperationService($repository, $this->stocks, $this->audit);
        $this->stock->seedLot(10, 1, 'EXP', '2026-10-01', 2, '9.00');
        $this->stock->seedLot(10, 1, 'NOV', '2026-11-01', 3, '10.00');
        $this->stock->seedLot(10, 1, 'DEC', '2026-12-01', 4, '11.00');
        $id = $service->propose($this->admin, 'Transfer', 1, 2, 'Rebalance', [['product_id' => 10, 'quantity' => 5]]);
        $service->decide($this->reviewer, $id, 'Approved', 'OK');
        $service->dispatch($this->warehouse, $id);
        $lots = $service->show($this->admin, $id)['items'][0]['lots'];
        self::assertSame([['NOV', 3], ['DEC', 2]], array_map(static fn (array $lot): array => [$lot['lot_code'], $lot['dispatched_quantity']], $lots), 'FEFO over non-expired lots; the expired lot stays.');
        self::assertSame(4, $this->stock->quantity(10, 1));

        // 3 good, 1 damaged, 1 short: good fills NOV first, the damaged unit is in DEC, the short unit too.
        $service->receive($this->warehouse, $id, [10 => ['good' => 3, 'damaged' => 1]], 'One carton crushed, one missing');
        $lots = $service->show($this->admin, $id)['items'][0]['lots'];
        self::assertSame([[3, 0, 0], [0, 1, 1]], array_map(static fn (array $lot): array => [$lot['received_quantity'], $lot['damaged_quantity'], $lot['short_quantity']], $lots));
        $destination = $this->stock->lots(10, 2);
        self::assertSame([['NOV', '2026-11-01', '10.00', 3]], array_map(static fn (StockLot $lot): array => [$lot->code(), $lot->expiryDate(), $lot->unitCost(), $lot->quantity()], $destination));
        self::assertSame($destination[0]->id(), $lots[0]['destination_lot_id']);
        self::assertNull($lots[1]['destination_lot_id'], 'Nothing of DEC arrived in good condition.');
    }

    // ---- Returns and adjustments ------------------------------------------------------------------------------------

    public function testSupplierReturnTakesTheOriginalReceiptLot(): void
    {
        $first = $this->stock->seedLot(10, 1, 'FIRST', '2026-11-01', 3);
        $second = $this->stock->seedLot(10, 1, 'SECOND', '2027-01-01', 3);
        $service = $this->operations(['10:1' => ['quantity' => 6]], [70 => ['id' => 70, 'product_id' => 10, 'warehouse_id' => 1, 'movement_type' => 'Receipt', 'reference_type' => 'PO', 'reference_id' => 3, 'quantity' => 3, 'lot_id' => $second]]);
        $id = $service->propose($this->warehouse, 'SupplierReturn', 0, null, 'Damaged batch', [['source_ledger_id' => '70', 'quantity' => '2']]);
        $service->decide($this->reviewer, $id, 'Approved', 'OK');
        $service->post($this->warehouse, $id);
        self::assertSame([3, 1], [$this->lot($first)->quantity(), $this->lot($second)->quantity()], 'Not FEFO: the batch received from that supplier goes back.');
        self::assertSame($second, $this->ledger->entries()[0]->lotId());
    }

    public function testCustomerReturnGoesBackToTheOriginalLotEvenWhenExpired(): void
    {
        $expired = $this->stock->seedLot(10, 1, 'GONE', '2026-10-01', 0);
        $this->stock->seedLot(10, 1, 'FRESH', '2027-01-01', 2);
        $service = $this->operations(['10:1' => ['quantity' => 2]], [80 => ['id' => 80, 'product_id' => 10, 'warehouse_id' => 1, 'movement_type' => 'Issue', 'reference_type' => 'SO', 'reference_id' => 4, 'quantity' => 3, 'lot_id' => $expired]]);
        $id = $service->propose($this->warehouse, 'CustomerReturn', 0, null, 'Customer returned', [['source_ledger_id' => '80', 'quantity' => '3', 'fit_for_stock' => true]]);
        $service->decide($this->reviewer, $id, 'Approved', 'OK');
        $service->post($this->warehouse, $id);
        self::assertSame(3, $this->lot($expired)->quantity());
        self::assertSame(3, $this->stock->expiredQuantity(10, 1), 'Back in the expired lot: quarantined, never sellable.');
        self::assertSame(5, $this->stock->quantity(10, 1));
    }

    public function testCountAdjustmentPerLotAndNewLotRules(): void
    {
        $lot = $this->stock->seedLot(10, 1, 'COUNTED', '2027-01-01', 5);
        $repository = new InMemoryBusinessOperationRepository(['10:1' => ['quantity' => 5]]);
        $repository->addLot(['id' => $lot, 'product_id' => 10, 'warehouse_id' => 1, 'lot_code' => 'COUNTED', 'expiry_date' => '2027-01-01', 'quantity' => 5, 'expired' => false]);
        $service = new BusinessOperationService($repository, $this->stocks, $this->audit, null, $this->products);

        try {
            $service->propose($this->warehouse, 'Adjustment', 1, null, 'Count', [['product_id' => 10, 'quantity' => 3, 'baseline' => 9, 'lot_id' => (string) $lot]]);
            self::fail('The baseline is the lot quantity.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->statusCode());
        }
        try {
            $service->propose($this->warehouse, 'Adjustment', 1, null, 'Found', [['product_id' => 10, 'quantity' => 2, 'baseline' => 0, 'lot_code' => 'FOUND', 'expiry_date' => '']]);
            self::fail('A tracked product needs an expiry date for a new lot.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('tracks expiry', $exception->getMessage());
        }
        $id = $service->propose($this->warehouse, 'Adjustment', 1, null, 'Count', [
            ['product_id' => 10, 'quantity' => 3, 'baseline' => 5, 'lot_id' => (string) $lot],
            ['product_id' => 10, 'quantity' => 2, 'baseline' => 0, 'lot_code' => 'FOUND', 'expiry_date' => '2027-03-01'],
        ]);
        $service->decide($this->reviewer, $id, 'Approved', 'OK');
        $service->post($this->warehouse, $id);
        $lots = $this->stock->lots(10, 1);
        self::assertSame([['COUNTED', 3], ['FOUND', 2]], array_map(static fn (StockLot $lot): array => [$lot->code(), $lot->quantity()], $lots));
        self::assertSame('1000.00', $lots[1]->unitCost(), 'A new lot found at the count costs the current purchase price per base.');
        self::assertSame([-2, 2], array_map(static fn (StockLedgerEntry $entry): int => $entry->delta(), $this->ledger->entries()));
    }

    public function testPairLevelShortfallTakesExpiredLotsFirstAndKeepsReservedSellableStock(): void
    {
        $expired = $this->stock->seedLot(10, 1, 'OLD', '2026-10-01', 2);
        $fresh = $this->stock->seedLot(10, 1, 'NEW', '2027-01-01', 4);
        $this->stock->reserve(10, 1, 3);
        $this->stocks->adjust([new StockDelta(10, 1, -2, 6)], 1, 'ADJUSTMENT', 1, static fn () => null);
        self::assertSame([0, 4], [$this->lot($expired)->quantity(), $this->lot($fresh)->quantity()]);
        try {
            $this->stocks->adjust([new StockDelta(10, 1, -2, 4, LotTarget::lot($fresh))], 1, 'ADJUSTMENT', 2, static fn () => null);
            self::fail('Would leave 2 sellable units for 3 reserved.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('reserved for approved sales orders', $exception->getMessage());
        }
        self::assertSame(4, $this->stock->quantity(10, 1));
    }

    // ---- Expired write-off ----------------------------------------------------------------------------------------------

    public function testExpiredWriteOffIsProposedApprovedByAnotherAdminAndPostedPerLot(): void
    {
        $one = $this->stock->seedLot(10, 1, 'E1', '2026-09-01', 4);
        $two = $this->stock->seedLot(10, 1, 'E2', '2026-10-01', 1);
        $this->stock->seedLot(10, 1, 'OK', '2027-01-01', 3);
        $repository = new InMemoryBusinessOperationRepository(['10:1' => ['quantity' => 8]]);
        foreach ([[$one, 'E1', 4], [$two, 'E2', 1]] as [$id, $code, $quantity]) {
            $repository->addLot(['id' => $id, 'product_id' => 10, 'warehouse_id' => 1, 'lot_code' => $code, 'expiry_date' => '2026-09-01', 'quantity' => $quantity, 'expired' => true]);
        }
        $service = new BusinessOperationService($repository, $this->stocks, $this->audit);
        try {
            $service->proposeExpiredWriteOff($this->sales, 1, 'Expired');
            self::fail('Sales may not write off stock.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
        }
        $id = $service->proposeExpiredWriteOff($this->warehouse, 1, 'Monthly destruction');
        $operation = $service->show($this->admin, $id);
        self::assertSame(['ExpiredWriteOff', 'Adjustment'], [$operation['purpose'], $operation['kind']]);
        self::assertSame([[$one, 4], [$two, 1]], array_map(static fn (array $item): array => [$item['lot_id'], $item['baseline']], $operation['items']));
        try {
            $service->decide($this->warehouse, $id, 'Approved', 'OK');
            self::fail('Only another Admin approves.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
        }
        $service->decide($this->reviewer, $id, 'Approved', 'Destroyed with witness');
        $service->post($this->warehouse, $id);
        self::assertSame([0, 0, 3], [$this->lot($one)->quantity(), $this->lot($two)->quantity(), $this->stock->quantity(10, 1)]);
        self::assertSame([[$one, -4], [$two, -1]], array_map(static fn (StockLedgerEntry $entry): array => [$entry->lotId(), $entry->delta()], $this->ledger->entries()));
    }

    public function testWriteOffPostingRefusesALotThatIsNotExpired(): void
    {
        // Proposed while expired under a (simulated) later date, posted when the lot is not expired: 409, nothing changes.
        $lot = $this->stock->seedLot(10, 1, 'E1', '2026-10-20', 4);
        try {
            $this->stocks->adjust([new StockDelta(10, 1, -4, 4, LotTarget::lot($lot, true))], 1, 'ADJUSTMENT', 1, static fn () => null);
            self::fail('Only expired lots are written off.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->statusCode());
            self::assertStringContainsString('no longer expired', $exception->getMessage());
        }
        self::assertSame([4, 4], [$this->stock->quantity(10, 1), $this->lot($lot)->quantity()]);
        $this->stock->setToday('2026-10-21');
        $this->stocks->adjust([new StockDelta(10, 1, -4, 4, LotTarget::lot($lot, true))], 1, 'ADJUSTMENT', 1, static fn () => null);
        self::assertSame(0, $this->stock->quantity(10, 1));
    }

    // ---- Read models and authorization ----------------------------------------------------------------------------------

    public function testLotServiceAuthorizesAndClassifiesExpiry(): void
    {
        $service = new LotService(new InMemoryLotQueryRepository([
            ['id' => 1, 'product_id' => 10, 'warehouse_id' => 1, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'warehouse_name' => 'Main', 'base_unit' => 'pcs', 'lot_code' => 'L1', 'expiry_date' => '2026-10-01', 'received_at' => '2026-04-01 08:00:00', 'unit_cost' => '10.00', 'quantity' => 4, 'days_left' => -11],
            ['id' => 2, 'product_id' => 10, 'warehouse_id' => 1, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'warehouse_name' => 'Main', 'base_unit' => 'pcs', 'lot_code' => '=L2', 'expiry_date' => '2026-10-30', 'received_at' => '2026-05-01 08:00:00', 'unit_cost' => '10.00', 'quantity' => 2, 'days_left' => 18],
            ['id' => 3, 'product_id' => 10, 'warehouse_id' => 2, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'warehouse_name' => 'East', 'base_unit' => 'pcs', 'lot_code' => 'L3', 'expiry_date' => '2027-06-01', 'received_at' => '2026-09-01 08:00:00', 'unit_cost' => '10.00', 'quantity' => 9, 'days_left' => 232],
        ]), 30);
        foreach ([$this->sales] as $actor) {
            foreach ([static fn () => $service->expiryReport($actor, []), static fn () => $service->trace($actor, 1), static fn () => $service->search($actor, ['q' => 'L']), static fn () => $service->expiryCsv($actor, [])] as $call) {
                try {
                    $call();
                    self::fail('Sales must get 403.');
                } catch (HttpException $exception) {
                    self::assertSame(403, $exception->statusCode());
                }
            }
        }
        $report = $service->expiryReport($this->warehouse, []);
        self::assertSame(['near_lots' => 1, 'expired_lots' => 1, 'near_units' => 2, 'expired_units' => 4], $report['summary']);
        self::assertSame([LotStatus::EXPIRED, LotStatus::NEAR], array_column($report['result']->items(), 'status'));
        self::assertSame(1, $service->expiryReport($this->admin, ['status' => 'expired'])['result']->total());
        self::assertSame(3, $service->expiryReport($this->admin, ['status' => 'all'])['result']->total());
        $this->expectOutputRegex("/'=L2/");
        ($service->expiryCsv($this->admin, ['status' => 'near']))();
        self::assertArrayNotHasKey('unit_cost', $service->productLots($this->sales, 10)[0], 'Sales does not see lot costs.');
        self::assertSame('10.00', $service->productLots($this->admin, 10)[0]['unit_cost']);
    }

    public function testLotStatusInputAndBusinessDate(): void
    {
        self::assertSame(LotStatus::EXPIRED, LotStatus::of('2026-10-11', self::TODAY, 30));
        self::assertSame(LotStatus::NEAR, LotStatus::of(self::TODAY, self::TODAY, 30));
        self::assertSame(LotStatus::NEAR, LotStatus::of('2026-11-11', self::TODAY, 30));
        self::assertSame(LotStatus::OK, LotStatus::of('2026-11-12', self::TODAY, 30));
        self::assertSame(LotStatus::NONE, LotStatus::of(null, self::TODAY, 30));
        self::assertTrue(BusinessDate::isValidDate('2028-02-29'));
        self::assertFalse(BusinessDate::isValidDate('2027-02-29'));
        self::assertFalse(BusinessDate::isValidDate('12-10-2026'));
        self::assertSame(25200, (new \DateTimeZone('Asia/Jakarta'))->getOffset(new \DateTimeImmutable('2026-10-12 00:00:00', new \DateTimeZone('UTC'))));
        self::assertSame('B-01/X_2.a', LotInput::code(' B-01/X_2.a '));
        foreach (['', 'legacy', '-X', str_repeat('A', 61), 'A B'] as $bad) {
            try {
                LotInput::code($bad);
                self::fail('Invalid code accepted: ' . $bad);
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([['lot_code' => 'A1', 'expiry_date' => '2027-01-01', 'quantity' => 7]], LotInput::splitsFromForm(['A1'], ['2027-01-01'], [''], 7), 'One row without quantity takes the whole line.');
        self::assertSame([], LotInput::splitsFromForm([''], [''], [''], 7));
        $this->expectException(ValidationException::class);
        LotInput::splitsFromForm(['A1', 'A2'], ['', ''], ['3', ''], 7);
    }

    public function testExpiryPagesAreForbiddenForSalesAndRenderForWarehouse(): void
    {
        $service = new LotService(new InMemoryLotQueryRepository([
            ['id' => 1, 'product_id' => 10, 'warehouse_id' => 1, 'sku' => 'SKU-010', 'product_name' => 'Susu', 'warehouse_name' => 'Main', 'base_unit' => 'pcs', 'lot_code' => 'L1', 'expiry_date' => '2026-10-01', 'received_at' => '2026-04-01 08:00:00', 'unit_cost' => '10.00', 'quantity' => 4, 'days_left' => -11, 'opening_quantity' => 0, 'source_type' => 'PO'],
        ], [], [['lot_id' => 1, 'id' => 5, 'movement_type' => 'Receipt', 'quantity' => 4, 'quantity_delta' => null, 'reference_type' => 'PO', 'reference_id' => 2, 'created_at' => '2026-04-01 08:00:00', 'performed_by' => 'WH', 'po_number' => 'PO-2604-0002', 'supplier_name' => 'PT Susu', 'so_number' => null, 'customer_name' => null, 'operation_kind' => null, 'operation_purpose' => null]]));
        $warehouses = $this->createStub(WarehouseRepositoryInterface::class);
        $warehouses->method('all')->willReturn([new Warehouse(1, 'Main', '', true)]);
        $controller = static function (AuthContext $actor) use ($service, $warehouses): LotController {
            $session = new SessionManager();
            $session->login($actor);

            return new LotController($service, new AuthGuard($session), $warehouses);
        };

        try {
            $controller($this->sales)->expiry(new Request('GET', '/reports/expiry', [], [], []));
            self::fail('Sales must get 403.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
        }
        $body = $controller($this->warehouse)->expiry(new Request('GET', '/reports/expiry', ['status' => 'expired'], [], []))->body();
        self::assertStringContainsString('data-lot-status="Expired"', $body);
        self::assertStringContainsString('action="/inventory-operations/expired-write-off"', $body);
        $trace = $controller($this->admin)->show(new Request('GET', '/lots/show', ['id' => '1'], [], []))->body();
        self::assertStringContainsString('Receipt from PO-2604-0002 · supplier PT Susu', $trace);
        self::assertSame(422, $controller($this->admin)->expiry(new Request('GET', '/reports/expiry', ['status' => 'rotten'], [], []))->statusCode());
    }

    // ---- Helpers --------------------------------------------------------------------------------------------------------

    private function lot(int $id): StockLot
    {
        return $this->stock->findLot($id) ?? throw new \RuntimeException('Lot missing.');
    }

    /** @param array<string, array<string, mixed>> $pairs @param array<int, array<string, mixed>> $sources */
    private function operations(array $pairs, array $sources): BusinessOperationService
    {
        return new BusinessOperationService(new InMemoryBusinessOperationRepository($pairs, $sources), $this->stocks, $this->audit);
    }

    /** @return array{0: PurchaseOrderService, 1: InMemoryPurchaseOrderRepository} */
    private function purchasing(): array
    {
        $orders = new InMemoryPurchaseOrderRepository();

        return [new PurchaseOrderService($orders, new InMemoryDocumentSequenceRepository(), $this->products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], $this->stocks), $orders];
    }

    /** @return array{0: SalesOrderService, 1: InMemorySalesOrderRepository} */
    private function selling(): array
    {
        $orders = new InMemorySalesOrderRepository();

        return [new SalesOrderService($orders, new InMemoryDocumentSequenceRepository(), $this->products, [1 => new Customer(1, 'Customer', '', '', '', true)], [1 => new Warehouse(1, 'Main', '', true)], $this->stocks, new OperationIdempotency(new InMemoryOperationRequestRepository()), new OrderLinePricing(), new InMemoryOrderExceptionRepository()), $orders];
    }

    private function pending(SalesOrderService $so, int $product, int $quantity): \App\Entity\SalesOrder
    {
        $order = $so->createDraft($this->admin, 1, 1, [['product_id' => $product, 'quantity' => $quantity]]);
        $so->submit($this->admin, $order->id());

        return $order;
    }
}
