<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\{Customer, Supplier, User, Warehouse};
use App\Repository\MySql\{MySqlProductRepository, MySqlSalesOrderRepository, MySqlPurchaseOrderRepository, MySqlStockRepository, MySqlStockLedgerRepository, MySqlAuditLogRepository};
use App\Security\AuthContext;
use App\Service\{SalesOrderService, PurchaseOrderService, StockService};
use App\Support\ProductInput;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class ParallelStockIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MySqlStockRepository $balances;
    private StockService $stock;
    private MySqlProductRepository $products;
    private AuthContext $actor;

    protected function setUp(): void
    {
        TestDatabase::reset();
        $this->pdo = TestDatabase::connect();
        $this->balances = new MySqlStockRepository($this->pdo);
        $this->stock = new StockService($this->balances, new MySqlStockLedgerRepository($this->pdo), new MySqlAuditLogRepository($this->pdo),new \App\Repository\MySql\MySqlStockCatalogRepository($this->pdo),new \App\Repository\MySql\MySqlTransactionManager($this->pdo));
        $this->products = new MySqlProductRepository($this->pdo);
        $this->actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
    }

    public function testTwoProcessesIssueSameOrderOnlyOnce(): void
    {
        $product = $this->fixtureProduct();
        [$service, $orders] = $this->sales();
        $order = $service->createDraft($this->actor, 1, 1, [['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);
        $service->submit($this->actor, $order->id()); $service->approve($this->actor, $order->id());
        $this->race($product, [['operation'=>'issue','id'=>$order->id()], ['operation'=>'issue','id'=>$order->id()]]);
        self::assertSame(1, $this->balances->quantity($product, 1));
        self::assertSame('Fulfilled', $orders->findById($order->id())->status());
        self::assertCount(1, (new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $order->id()));
        self::assertSame(1, $this->sqlCount("SELECT COUNT(*) FROM audit_logs WHERE action='sales-orders.issue' AND status='success' AND entity_id=?", [$order->id()]));
    }

    public function testConcurrentDuplicatePartialReceiptBothSucceedWithOneMovement(): void
    {
        $product=$this->fixtureProduct(); $orders=new MySqlPurchaseOrderRepository($this->pdo);
        $service=new PurchaseOrderService($orders, new \App\Repository\MySql\MySqlDocumentSequenceRepository($this->pdo),$this->products,[1=>new Supplier(1,'Supplier','','','',true)],[1=>new Warehouse(1,'Warehouse','',true)],$this->stock);
        $order=$service->createDraft($this->actor,1,1,[['product_id'=>$product,'quantity'=>10,'purchase_price'=>10.0]]);$service->markOrdered($this->actor,$order->id());
        $job=['operation'=>'receive','id'=>$order->id(),'item_id'=>$order->items()[0]->id(),'quantity'=>3,'key'=>str_repeat('a',32)];
        $this->race($product,[$job,$job],false,true);
        self::assertSame(8,$this->balances->quantity($product,1));self::assertSame(3,$orders->findById($order->id())->items()[0]->receivedQuantity());
        self::assertCount(1,(new MySqlStockLedgerRepository($this->pdo))->forReference('PO',$order->id()));
    }

    public function testConcurrentDuplicateIssueBothSucceedWithOneMovement(): void
    {
        $product=$this->fixtureProduct();[$service,$orders]=$this->sales();
        $order=$service->createDraft($this->actor,1,1,[['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);$service->submit($this->actor,$order->id());$service->approve($this->actor,$order->id());
        $job=['operation'=>'issue','id'=>$order->id(),'key'=>str_repeat('b',32)];$this->race($product,[$job,$job],false,true);
        self::assertSame(1,$this->balances->quantity($product,1));self::assertSame('Fulfilled',$orders->findById($order->id())->status());
        self::assertCount(1,(new MySqlStockLedgerRepository($this->pdo))->forReference('SO',$order->id()));
    }

    /**
     * D-15 / ADR-021: two processes receive the same dispatched transfer at once (one with damage, one all good). The
     * transfer lock serializes them; the second sees Received and returns as a no-op, so the goods enter the destination
     * exactly once and the line keeps the first receipt.
     */
    public function testTwoConcurrentReceivesOfOneTransferApplyOnce(): void
    {
        $product=$this->fixtureProduct();[$business,$reviewer]=$this->business();
        $id=$business->propose($this->actor,'Transfer',1,2,'Move',[['product_id'=>$product,'quantity'=>4]]);$business->decide($reviewer,$id,'Approved','Checked');$business->dispatch($this->actor,$id);
        $this->race($product,[
            ['operation'=>'inventory_receive','id'=>$id,'lines'=>[$product=>['good'=>3,'damaged'=>1]],'reason'=>'One carton crushed'],
            ['operation'=>'inventory_receive','id'=>$id,'lines'=>[$product=>['good'=>4,'damaged'=>0]]],
        ],false,true,2);
        $ledger=(new MySqlStockLedgerRepository($this->pdo))->forReference('TRANSFER',$id);
        self::assertCount(2,$ledger,'One dispatch row and exactly one receipt row.');
        $received=$this->balances->quantity($product,2);
        self::assertContains($received,[3,4]);
        self::assertSame($received,$ledger[1]->delta());
        self::assertSame($received,$this->sqlCount('SELECT received_quantity FROM inventory_operation_items WHERE operation_id=?',[$id]));
        self::assertSame(1,$this->sqlCount("SELECT COUNT(*) FROM audit_logs WHERE action='inventory-operations.receive' AND status='success' AND entity_id=?",[$id]));
        self::assertSame(0,array_sum((new \App\Repository\MySql\TransferTransitChecks($this->pdo))->report()));
    }

    private function business(): array
    {
        $user=(new \App\Repository\MySql\MySqlUserRepository($this->pdo))->create('Independent reviewer','business-reviewer@example.test',password_hash('password',PASSWORD_DEFAULT),'Admin',true);
        return [new \App\Service\BusinessOperationService(new \App\Repository\MySql\MySqlBusinessOperationRepository($this->pdo),$this->stock,new MySqlAuditLogRepository($this->pdo)),new AuthContext($user->id(),$user->email(),'Admin')];
    }

    /** D-15 / ADR-021: posting a transfer dispatches it; a concurrent replay is a no-op, so the goods leave the source once. */
    public function testConcurrentTransferReplayBothSucceedWithOneDispatch(): void
    {
        $product=$this->fixtureProduct();[$business,$reviewer]=$this->business();$id=$business->propose($this->actor,'Transfer',1,2,'Move',[['product_id'=>$product,'quantity'=>3]]);$business->decide($reviewer,$id,'Approved','Checked');
        $job=['operation'=>'inventory_post','id'=>$id];$this->race($product,[$job,$job],false,true);
        self::assertSame(2,$this->balances->quantity($product,1));self::assertSame(0,$this->balances->quantity($product,2),'In transit: not yet at the destination.');self::assertCount(1,(new MySqlStockLedgerRepository($this->pdo))->forReference('TRANSFER',$id));
        $business->receive($this->actor,$id);
        self::assertSame(3,$this->balances->quantity($product,2));self::assertCount(2,(new MySqlStockLedgerRepository($this->pdo))->forReference('TRANSFER',$id));
    }

    public function testOppositeTransfersUseDeterministicPairLocksAndConserveTotal(): void
    {
        $product=$this->fixtureProduct();$this->stock->receive(2,[new \App\Service\StockMovement($product,4)],1,'TEST',2);[$business,$reviewer]=$this->business();$jobs=[];
        foreach([[1,2],[2,1]] as [$source,$destination]){$id=$business->propose($this->actor,'Transfer',$source,$destination,'Opposite move',[['product_id'=>$product,'quantity'=>3]]);$business->decide($reviewer,$id,'Approved','Checked');$jobs[]=['operation'=>'inventory_post','id'=>$id];}
        $this->race($product,$jobs,false,true);
        // Both dispatched: 3 units of each warehouse are in transit (D-15, ADR-021); receiving both conserves the total.
        self::assertSame([2,1],[$this->balances->quantity($product,1),$this->balances->quantity($product,2)]);
        foreach($jobs as $job){$business->receive($this->actor,$job['id']);}
        self::assertSame(5,$this->balances->quantity($product,1));self::assertSame(4,$this->balances->quantity($product,2));
    }

    public function testConcurrentCustomerReturnsCannotExceedOriginalIssue(): void
    {
        $product=$this->fixtureProduct();[$sales,$orders]=$this->sales();$order=$sales->createDraft($this->actor,1,1,[['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);$sales->submit($this->actor,$order->id());$sales->approve($this->actor,$order->id());$sales->issue($this->actor,$order->id());
        $source=(new MySqlStockLedgerRepository($this->pdo))->forReference('SO',$order->id())[0]->id();[$business,$reviewer]=$this->business();$jobs=[];
        foreach([1,2] as $unused){$id=$business->propose($this->actor,'CustomerReturn',0,null,'Returned goods',[['quantity'=>3,'source_ledger_id'=>$source,'fit_for_stock'=>true]]);$business->decide($reviewer,$id,'Approved','Checked');$jobs[]=['operation'=>'inventory_post','id'=>$id];}
        $this->race($product,$jobs);self::assertSame(4,$this->balances->quantity($product,1));self::assertSame(3,$this->sqlCount('SELECT returned_quantity FROM inventory_return_totals WHERE source_ledger_id=?',[$source]));self::assertSame('Fulfilled',$orders->findById($order->id())->status());
    }

    /**
     * Since D-12 (ADR-018) the competition happens at approval: both orders need 4 of the 5 units, so only one can
     * reserve. Two independent processes approve at the same time; exactly one commits, the other gets 422 and stays
     * PendingApproval. Issuing the winner then consumes its reservation. The oversell guarantee is unchanged.
     */
    public function testDifferentOrdersCompetingForSameStockCannotOversell(): void
    {
        $product = $this->fixtureProduct();
        [$service, $orders] = $this->sales();
        $ids = [];
        foreach (['A', 'B'] as $suffix) {
            $order = $service->createDraft($this->actor, 1, 1, [['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);
            $service->submit($this->actor, $order->id());
            $ids[] = $order->id();
        }
        $this->race($product, array_map(static fn (int $id): array => ['operation'=>'approve','id'=>$id], $ids));
        self::assertSame(5, $this->balances->quantity($product, 1), 'Approval moves no goods.');
        self::assertSame(4, $this->balances->reservedQuantity($product, 1), 'Exactly one order reserved its 4 units.');
        $statuses = array_map(static fn (int $id): string => $orders->findById($id)->status(), $ids);
        sort($statuses); self::assertSame(['Approved', 'PendingApproval'], $statuses);
        self::assertSame(1, $this->sqlCount("SELECT COUNT(*) FROM audit_logs WHERE action='sales-orders.approve' AND status='success' AND entity_id IN (?, ?)", $ids));
        self::assertSame(0, $this->sqlCount("SELECT COUNT(*) FROM stock_ledger WHERE product_id=? AND movement_type='Issue'", [$product]));

        $winner = $orders->findById($ids[0])->status() === 'Approved' ? $ids[0] : $ids[1];
        $service->issue($this->actor, $winner);
        self::assertSame(1, $this->balances->quantity($product, 1));
        self::assertSame(0, $this->balances->reservedQuantity($product, 1));
        self::assertSame(1, $this->sqlCount("SELECT COUNT(*) FROM stock_ledger WHERE product_id=? AND movement_type='Issue'", [$product]));
        self::assertSame(0, (new \App\Repository\MySql\StockReservationReconciler($this->pdo))->mismatches());
    }

    /**
     * An approval (reserve 4) races a transfer dispatch (3) of the same source pair with 5 on hand: both fit alone, not
     * together. Whichever locks the stock row first wins; the other is refused after the lock, so available stock
     * (quantity − reserved) never goes negative and a dispatch never consumes reserved stock (D-15/ADR-021, ADR-018).
     */
    public function testApprovalRacingTransferDispatchNeverViolatesAvailableStock(): void
    {
        $product = $this->fixtureProduct();
        [$service, $orders] = $this->sales();
        $order = $service->createDraft($this->actor, 1, 1, [['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);
        $service->submit($this->actor, $order->id());
        [$business, $reviewer] = $this->business();
        $transfer = $business->propose($this->actor, 'Transfer', 1, 2, 'Move', [['product_id'=>$product,'quantity'=>3]]);
        $business->decide($reviewer, $transfer, 'Approved', 'Checked');

        $this->race($product, [['operation'=>'approve','id'=>$order->id()], ['operation'=>'inventory_dispatch','id'=>$transfer]]);

        $quantity = $this->balances->quantity($product, 1);
        $reserved = $this->balances->reservedQuantity($product, 1);
        self::assertGreaterThanOrEqual(0, $quantity - $reserved);
        $approved = $orders->findById($order->id())->status() === 'Approved';
        $dispatched = $this->sqlCount("SELECT COUNT(*) FROM inventory_operations WHERE id=? AND status='Dispatched'", [$transfer]) === 1;
        self::assertTrue($approved xor $dispatched, 'Exactly one of the two commits.');
        if ($approved) {
            self::assertSame([5, 4, 0], [$quantity, $reserved, $this->balances->quantity($product, 2)]);
            self::assertSame(0, $this->sqlCount("SELECT COUNT(*) FROM stock_ledger WHERE reference_type='TRANSFER' AND reference_id=?", [$transfer]));
        } else {
            // Dispatched: 3 units in transit, nothing at the destination yet.
            self::assertSame([2, 0, 0], [$quantity, $reserved, $this->balances->quantity($product, 2)]);
            self::assertSame('PendingApproval', $orders->findById($order->id())->status());
        }
        self::assertSame(0, array_sum((new \App\Repository\MySql\TransferTransitChecks($this->pdo))->report()));
        self::assertSame(0, (new \App\Repository\MySql\StockReservationReconciler($this->pdo))->mismatches());
    }

    /**
     * D-14 / ADR-020: two independent processes each try to ship 3 of the 5 ordered units of the same order (different
     * keys, so both are new intents). The order lock serializes them; the second sees 2 remaining and is refused with
     * 422, so issued never exceeds ordered and stock, reservation and ledger stay consistent.
     */
    public function testConcurrentPartialIssuesCannotExceedRemaining(): void
    {
        $product = $this->fixtureProduct();
        [$service, $orders] = $this->sales();
        $order = $service->createDraft($this->actor, 1, 1, [['product_id'=>$product,'quantity'=>5,'selling_price'=>20.0]]);
        $service->submit($this->actor, $order->id()); $service->approve($this->actor, $order->id());
        $item = $order->items()[0]->id();
        $this->race($product, [
            ['operation'=>'issue','id'=>$order->id(),'key'=>str_repeat('c',32),'quantities'=>[$item=>3]],
            ['operation'=>'issue','id'=>$order->id(),'key'=>str_repeat('d',32),'quantities'=>[$item=>3]],
        ]);
        $updated = $orders->findById($order->id());
        self::assertSame(['PartiallyFulfilled', 3], [$updated->status(), $updated->items()[0]->issuedQuantity()]);
        self::assertSame([2, 2], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        self::assertCount(1, (new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $order->id()));
        self::assertSame(0, (new \App\Repository\MySql\StockReservationReconciler($this->pdo))->mismatches());
        self::assertSame(['issued_ledger_mismatches'=>0,'fulfilment_status_mismatches'=>0,'closure_mismatches'=>0], (new \App\Repository\MySql\PartialFulfilmentChecks($this->pdo))->report());
    }

    /**
     * D-14 / ADR-020: an issue of the whole remainder races an Admin closing that remainder. Exactly one commits:
     * either the order becomes Fulfilled (and the closure is refused) or the remainder is closed and released (and the
     * issue is refused). Either way the reservation invariant and issued = Σ Issue ledger hold.
     */
    public function testIssueRacingCloseRemainingStaysConsistent(): void
    {
        $product = $this->fixtureProduct();
        [$service, $orders] = $this->sales();
        $order = $service->createDraft($this->actor, 1, 1, [['product_id'=>$product,'quantity'=>5,'selling_price'=>20.0]]);
        $service->submit($this->actor, $order->id()); $service->approve($this->actor, $order->id());
        $item = $order->items()[0]->id();
        $service->issue($this->actor, $order->id(), null, [$item=>1]);

        $this->race($product, [
            ['operation'=>'issue','id'=>$order->id(),'key'=>str_repeat('e',32),'quantities'=>[$item=>4]],
            ['operation'=>'close_remaining','id'=>$order->id(),'reason'=>'Customer cancelled the rest'],
        ]);

        $updated = $orders->findById($order->id());
        $closed = $this->sqlCount('SELECT COUNT(*) FROM sales_order_closures WHERE sales_order_id=?', [$order->id()]) === 1;
        self::assertTrue(($updated->status() === 'Fulfilled') xor $closed, 'Exactly one of issue and closure commits.');
        if ($closed) {
            self::assertSame(['PartiallyFulfilled', 1, 4, 0], [$updated->status(), $updated->items()[0]->issuedQuantity(), $this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
            self::assertCount(1, (new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $order->id()));
        } else {
            self::assertSame([5, 0, 0], [$updated->items()[0]->issuedQuantity(), $this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
            self::assertCount(2, (new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $order->id()));
        }
        self::assertSame(0, (new \App\Repository\MySql\StockReservationReconciler($this->pdo))->mismatches());
        self::assertSame(['issued_ledger_mismatches'=>0,'fulfilment_status_mismatches'=>0,'closure_mismatches'=>0], (new \App\Repository\MySql\PartialFulfilmentChecks($this->pdo))->report());
    }

    /**
     * D-17 / ADR-023: two approved orders (4 + 4) were reserved while both lots were fresh; then lot B expired. Only lot A
     * (4 units) is sellable. Two processes issue both orders at once: the pair lock serializes them, the first takes lot A
     * (FEFO, non-expired only) and the second is refused with 422. Never an oversell, never a unit from the expired lot,
     * and lots stay equal to the aggregate.
     */
    public function testTwoIssuesCompetingForTheLastNonExpiredLotNeverUseTheExpiredLot(): void
    {
        $product = $this->lotProduct([['LAST-A', '+120 day', 4], ['LAST-B', '+10 day', 4]]);
        [$service, $orders] = $this->sales();
        $ids = [];
        foreach ([1, 2] as $unused) {
            $order = $service->createDraft($this->actor, 1, 1, [['product_id' => $product, 'quantity' => 4]]);
            $service->submit($this->actor, $order->id());
            $service->approve($this->actor, $order->id());
            $ids[] = $order->id();
        }
        $this->pdo->exec("UPDATE stock_lots SET expiry_date = '" . (new \DateTimeImmutable(\App\Support\BusinessDate::today()))->modify('-1 day')->format('Y-m-d') . "' WHERE product_id = $product AND lot_code = 'LAST-B'");
        $this->race($product, array_map(static fn (int $id): array => ['operation' => 'issue', 'id' => $id], $ids));
        $statuses = array_map(static fn (int $id): string => $orders->findById($id)->status(), $ids);
        sort($statuses);
        self::assertSame(['Approved', 'Fulfilled'], $statuses);
        self::assertSame([4, 4], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        $issued = array_map(static fn (array $row): array => [(string) $row[0], (int) $row[1]], $this->pdo->query("SELECT l.lot_code, SUM(s.quantity) FROM stock_ledger s JOIN stock_lots l ON l.id = s.lot_id WHERE s.movement_type = 'Issue' AND s.product_id = $product GROUP BY l.lot_code")->fetchAll(PDO::FETCH_NUM));
        self::assertSame([['LAST-A', 4]], $issued, 'Only the non-expired lot was issued.');
        $this->assertLotInvariants();
    }

    /**
     * D-17 / ADR-023: an issue (4, reserved) races a transfer dispatch (2) on the same source pair; three fresh lots and
     * one expired lot. Both fit together, so both commit in either order; FEFO and the expiry rule hold for both, the
     * expired lot is untouched and every lot still equals its ledger.
     */
    public function testIssueRacingTransferDispatchKeepsFefoAndLotsEqualToStock(): void
    {
        $product = $this->lotProduct([['RACE-1', '+30 day', 3], ['RACE-2', '+60 day', 3], ['RACE-3', '+90 day', 3], ['RACE-X', '+5 day', 2]]);
        [$service, $orders] = $this->sales();
        $order = $service->createDraft($this->actor, 1, 1, [['product_id' => $product, 'quantity' => 4]]);
        $service->submit($this->actor, $order->id());
        $service->approve($this->actor, $order->id());
        [$business, $reviewer] = $this->business();
        $transfer = $business->propose($this->actor, 'Transfer', 1, 2, 'Move', [['product_id' => $product, 'quantity' => 2]]);
        $business->decide($reviewer, $transfer, 'Approved', 'Checked');
        $this->pdo->exec("UPDATE stock_lots SET expiry_date = '" . (new \DateTimeImmutable(\App\Support\BusinessDate::today()))->modify('-1 day')->format('Y-m-d') . "' WHERE product_id = $product AND lot_code = 'RACE-X'");

        $this->race($product, [['operation' => 'issue', 'id' => $order->id()], ['operation' => 'inventory_dispatch', 'id' => $transfer]], false, true);

        self::assertSame('Fulfilled', $orders->findById($order->id())->status());
        self::assertSame('Dispatched', (string) $this->pdo->query("SELECT status FROM inventory_operations WHERE id = $transfer")->fetchColumn());
        self::assertSame([5, 0], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        self::assertSame(2, (int) $this->pdo->query("SELECT quantity FROM stock_lots WHERE product_id = $product AND lot_code = 'RACE-X'")->fetchColumn(), 'The expired lot is never shipped or dispatched.');
        self::assertSame(3, (int) $this->pdo->query("SELECT quantity FROM stock_lots WHERE product_id = $product AND lot_code = 'RACE-3'")->fetchColumn(), 'FEFO: the latest lot is untouched.');
        $this->assertLotInvariants();
    }

    /**
     * D-18 / ADR-024: an approved stock count (lot counted 9, expected 10: variance −1) is posted while an approved order
     * of 4 is issued from the same lot. The pair lock serializes the two processes in either order; the count applies its
     * delta to the *current* quantity, so the result is always 10 − 4 − 1 = 5 — the shipment is never lost (as it would
     * be with "set to counted") and the count is never applied twice.
     */
    public function testCountPostingRacingAnIssueOnTheSameLotKeepsBothMovements(): void
    {
        $product = $this->lotProduct([['RACE-COUNT', '+60 day', 10]]);
        [$service, $orders] = $this->sales();
        $order = $service->createDraft($this->actor, 1, 1, [['product_id' => $product, 'quantity' => 4]]);
        $service->submit($this->actor, $order->id());
        $service->approve($this->actor, $order->id());
        $counts = new \App\Service\StockCountService(new \App\Repository\MySql\MySqlStockCountRepository($this->pdo), $this->stock, $this->balances, new MySqlAuditLogRepository($this->pdo), $this->products,
            new \App\Repository\MySql\MySqlUserRepository($this->pdo), new \App\Repository\MySql\MySqlWarehouseRepository($this->pdo), new \App\Repository\MySql\MySqlCategoryRepository($this->pdo),
            new \App\Service\ProductUnitCatalog($this->products, new \App\Repository\MySql\MySqlProductUnitRepository($this->pdo)));
        $counter = new AuthContext(4, 'warehouse1@example.test', User::ROLE_WAREHOUSE_STAFF);
        $reviewer = new AuthContext(6, 'hendra.wijaya@sumberniaga.test', User::ROLE_ADMIN);
        $count = $counts->create($this->actor, ['warehouse_id' => '1', 'scope' => 'Products', 'product_ids' => [(string) $product], 'counter_ids' => ['4'], 'reason' => 'Race count']);
        $counts->start($this->actor, $count);
        $line = (int) $this->pdo->query("SELECT id FROM stock_count_lines WHERE count_id = $count")->fetchColumn();
        $counts->record($counter, $count, [$line => ['quantity' => '9', 'unit' => '']]);
        $counts->submit($counter, $count);
        $counts->approve($reviewer, $count);

        $this->race($product, [['operation' => 'count_post', 'id' => $count], ['operation' => 'issue', 'id' => $order->id()]], false, true);

        self::assertSame('Posted', (string) $this->pdo->query("SELECT status FROM stock_counts WHERE id = $count")->fetchColumn());
        self::assertSame('Fulfilled', $orders->findById($order->id())->status());
        self::assertSame([5, 0], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        self::assertSame(5, (int) $this->pdo->query("SELECT quantity FROM stock_lots WHERE product_id = $product AND lot_code = 'RACE-COUNT'")->fetchColumn());
        self::assertSame(-1, (int) $this->pdo->query("SELECT SUM(quantity_delta) FROM stock_ledger WHERE reference_type = 'STOCK_COUNT' AND reference_id = $count")->fetchColumn());
        self::assertSame(array_fill_keys(['count_posted_delta_mismatches', 'count_below_reserved', 'count_approved_by_counter', 'count_expected_mismatches', 'count_open_overlaps'], 0), (new \App\Repository\MySql\StockCountChecks($this->pdo))->report());
        $this->assertLotInvariants();
    }

    /** @param list<array{0: string, 1: string, 2: int}> $lots code, expiry offset, quantity */
    private function lotProduct(array $lots): int
    {
        $product = $this->products->create(new ProductInput('PARALLEL-LOT', 'Parallel lots', 'pcs', 10.0, 20.0, 1, 1, true), true)->id();
        $this->stock->initializeProduct($product);
        $splits = array_map(static fn (array $lot): \App\Service\LotSplit => new \App\Service\LotSplit($lot[0], (new \DateTimeImmutable(\App\Support\BusinessDate::today()))->modify($lot[1])->format('Y-m-d'), $lot[2], '10.00'), $lots);
        $this->stock->receive(1, [new \App\Service\StockMovement($product, array_sum(array_column($lots, 2)), $splits)], 1, 'TEST', 1);

        return $product;
    }

    private function assertLotInvariants(): void
    {
        self::assertSame(array_fill_keys(['lot_aggregate_mismatches', 'lot_ledger_mismatches', 'lot_count_mismatches', 'ledger_rows_without_lot', 'issue_from_expired_lot', 'fefo_violations', 'transfer_lot_mismatches'], 0), (new \App\Repository\MySql\BatchLotChecks($this->pdo))->report());
        self::assertSame(0, (new \App\Repository\MySql\StockReservationReconciler($this->pdo))->mismatches());
        self::assertSame(0, array_sum((new \App\Repository\MySql\TransferTransitChecks($this->pdo))->report()));
    }

    /**
     * D-20 / ADR-026: real processes race on one pair. Race 1: an issue (4, reserved) against a PO receipt of 6 at a new
     * supplier price (12.50; the existing lot costs 10.00). Race 2: two issues (4 and 3) of different orders. The pair lock
     * serializes them in either order; FEFO takes the older cost layer first, so COGS is 4 × 10.00 and then 6 × 10.00 +
     * 1 × 12.50, and every lot still rolls forward to the cent: value = quantity × cost = Σ cost amounts of its rows.
     */
    public function testConcurrentIssuesAndReceiptsKeepTheLotValueRollForwardExact(): void
    {
        $product = $this->products->create(new ProductInput('PARALLEL-VAL', 'Parallel valuation', 'pcs', 10.0, 20.0, 1, 1), true)->id();
        $this->stock->initializeProduct($product);
        $this->stock->receive(1, [new \App\Service\StockMovement($product, 10, [new \App\Service\LotSplit('VAL-OLD', null, 10, '10.00')])], 1, 'TEST', 1);
        $purchases = new PurchaseOrderService(new MySqlPurchaseOrderRepository($this->pdo), new \App\Repository\MySql\MySqlDocumentSequenceRepository($this->pdo), $this->products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], [1 => new Warehouse(1, 'Warehouse', '', true)], $this->stock);
        $po = $purchases->createDraft($this->actor, 1, 1, [['product_id' => $product, 'quantity' => 6, 'price' => '12.50', 'price_reason' => 'Supplier price letter']]);
        $purchases->markOrdered($this->actor, $po->id());
        [$service, $orders] = $this->sales();
        $approved = function (int $quantity) use ($service, $product): int {
            $order = $service->createDraft($this->actor, 1, 1, [['product_id' => $product, 'quantity' => $quantity]]);
            $service->submit($this->actor, $order->id());
            $service->approve($this->actor, $order->id());

            return $order->id();
        };
        $first = $approved(4);
        $this->race($product, [['operation' => 'issue', 'id' => $first], ['operation' => 'receive', 'id' => $po->id(), 'item_id' => $po->items()[0]->id(), 'quantity' => 6]], false, true);
        self::assertSame(12, $this->balances->quantity($product, 1));
        [$second, $third] = [$approved(4), $approved(3)];
        $this->race($product, [['operation' => 'issue', 'id' => $second], ['operation' => 'issue', 'id' => $third]], false, true);

        self::assertSame(['Fulfilled', 'Fulfilled', 'Fulfilled'], [$orders->findById($first)->status(), $orders->findById($second)->status(), $orders->findById($third)->status()]);
        self::assertSame([5, 0], [$this->balances->quantity($product, 1), $this->balances->reservedQuantity($product, 1)]);
        self::assertSame([['VAL-OLD', '10.00', 0], [$po->orderNumber(), '12.50', 5]], array_map(static fn (array $row): array => [(string) $row[0], (string) $row[1], (int) $row[2]],
            $this->pdo->query("SELECT lot_code, unit_cost, quantity FROM stock_lots WHERE product_id = {$product} ORDER BY lot_seq")->fetchAll(PDO::FETCH_NUM)));
        $issued = (int) $this->pdo->query("SELECT SUM(-CAST(ROUND(cost_amount * 100) AS SIGNED)) FROM stock_ledger WHERE product_id = {$product} AND movement_type = 'Issue'")->fetchColumn();
        self::assertSame(4000 + 7250, $issued, 'COGS: 4 × 10.00, then 6 × 10.00 + 1 × 12.50, whatever the commit order.');
        self::assertSame(6250, (int) $this->pdo->query("SELECT SUM(CAST(ROUND(cost_amount * 100) AS SIGNED)) FROM stock_ledger WHERE product_id = {$product}")->fetchColumn(), 'On hand 5 × 12.50 = Σ cost amounts.');
        $checks = (new \App\Repository\MySql\ValuationChecks($this->pdo))->report();
        self::assertSame(array_fill_keys(array_keys($checks), 0), $checks);
        $this->assertLotInvariants();
    }

    public function testConcurrentPartialReceiptsCannotExceedOrderRemainder(): void
    {
        $product = $this->fixtureProduct();
        $orders = new MySqlPurchaseOrderRepository($this->pdo);
        $service = new PurchaseOrderService($orders, new \App\Repository\MySql\MySqlDocumentSequenceRepository($this->pdo), $this->products, [1=>new Supplier(1,'Supplier','','','',true)], [1=>new Warehouse(1,'Warehouse','',true)], $this->stock);
        $order = $service->createDraft($this->actor, 1, 1, [['product_id'=>$product,'quantity'=>5,'purchase_price'=>10.0]]);
        $service->markOrdered($this->actor, $order->id());
        $job = ['operation'=>'receive','id'=>$order->id(),'item_id'=>$order->items()[0]->id(),'quantity'=>3];
        $this->race($product, [$job, $job]);
        self::assertSame(8, $this->balances->quantity($product, 1));
        $updated = $orders->findById($order->id());
        self::assertSame('PartiallyReceived', $updated->status());
        self::assertSame(3, $updated->items()[0]->receivedQuantity());
        self::assertCount(1, (new MySqlStockLedgerRepository($this->pdo))->forReference('PO', $order->id()));
    }

    public function testConcurrentProductAndWarehouseCreationInitializesTheirIntersection(): void
    {
        $before = $this->sqlCount('SELECT COUNT(*) FROM stock_ledger', []);
        $this->race(0, [['operation'=>'catalog_product'], ['operation'=>'catalog_warehouse']], true);
        self::assertSame(1, $this->sqlCount("SELECT COUNT(*) FROM product_stocks ps INNER JOIN products p ON p.id=ps.product_id INNER JOIN warehouses w ON w.id=ps.warehouse_id WHERE p.sku='PARALLEL-CATALOG' AND w.name='Parallel warehouse' AND ps.quantity=0", []));
        self::assertSame($before, $this->sqlCount('SELECT COUNT(*) FROM stock_ledger', []));
    }

    /**
     * D-19 / ADR-025: two payments of 60 % each on one invoice. Both workers wait on the invoice row lock; the first
     * commits, the second sees the new paid amount after the lock and is refused (overpayment), so Σ payments ≤ total.
     */
    public function testConcurrentPaymentsOnOneInvoiceNeverExceedTheTotal(): void
    {
        $invoice = $this->pdo->query('SELECT id, total_amount, invoice_date FROM invoices WHERE voided_at IS NULL AND paid_amount = 0 AND total_amount > 1000 ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $total = \App\Support\TaxSettings::cents((string) $invoice['total_amount']);
        $part = \App\Support\TaxSettings::decimal(intdiv($total * 6, 10));
        $job = ['operation' => 'invoice_payment', 'id' => (int) $invoice['id'], 'paid_on' => (string) $invoice['invoice_date'], 'amount' => $part];
        $this->raceOnRow('SELECT id FROM invoices WHERE id = ? FOR UPDATE', [(int) $invoice['id']], [$job, $job]);
        self::assertSame([1, $part], [$this->sqlCount('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [(int) $invoice['id']]), (string) $this->pdo->query('SELECT paid_amount FROM invoices WHERE id = ' . (int) $invoice['id'])->fetchColumn()]);
        self::assertSame(0, (new \App\Repository\MySql\SalesDocumentChecks($this->pdo))->report()['invoice_payment_mismatches']);
    }

    /** D-19 / ADR-025: two conversions of one accepted quotation give exactly one SO (quotation lock, UNIQUE quotation_id). */
    public function testConcurrentConversionsOfOneQuotationGiveOneSalesOrder(): void
    {
        $customer = (int) $this->pdo->query('SELECT MIN(id) FROM customers WHERE price_list_id IS NOT NULL AND is_active = 1')->fetchColumn();
        $product = $this->fixtureProduct();
        $units = new \App\Service\ProductUnitCatalog($this->products, new \App\Repository\MySql\MySqlProductUnitRepository($this->pdo));
        $customers = new \App\Repository\MySql\MySqlCustomerRepository($this->pdo);
        $byId = [];
        foreach ($customers->all() as $row) { $byId[$row->id()] = $row; }
        $salesOrders = new SalesOrderService(new MySqlSalesOrderRepository($this->pdo), new \App\Repository\MySql\MySqlDocumentSequenceRepository($this->pdo), $this->products, $byId, [1 => new Warehouse(1, 'Warehouse', '', true)], $this->stock, null, new \App\Service\OrderLinePricing(), null, $units);
        $quotations = new \App\Service\QuotationService(new \App\Repository\MySql\MySqlQuotationRepository($this->pdo), new \App\Repository\MySql\MySqlDocumentSequenceRepository($this->pdo), $this->products, $customers,
            new \App\Repository\MySql\MySqlWarehouseRepository($this->pdo), $salesOrders, new \App\Repository\MySql\MySqlTransactionManager($this->pdo), $units);
        $id = $quotations->create($this->actor, $customer, 1, null, '', [['product_id' => $product, 'quantity' => 2]]);
        $quotations->send($this->actor, $id);
        $quotations->accept($this->actor, $id);
        $this->raceOnRow('SELECT id FROM quotations WHERE id = ? FOR UPDATE', [$id], [['operation' => 'quotation_convert', 'id' => $id], ['operation' => 'quotation_convert', 'id' => $id]]);
        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM sales_orders WHERE quotation_id = ?', [$id]));
        self::assertSame(1, $this->sqlCount("SELECT COUNT(*) FROM audit_logs WHERE action = 'quotations.convert' AND entity_id = ?", [$id]));
        self::assertSame(0, (new \App\Repository\MySql\SalesDocumentChecks($this->pdo))->report()['quotation_conversion_mismatches']);
    }

    /** D-19 / ADR-025: two invoice creations for one delivery note give one invoice (note lock, UNIQUE live note). */
    public function testConcurrentInvoiceCreationsFromOneDeliveryNoteGiveOneInvoice(): void
    {
        $note = (int) $this->pdo->query('SELECT MIN(dn.id) FROM delivery_notes dn WHERE NOT EXISTS (SELECT 1 FROM invoices i WHERE i.active_delivery_note_id = dn.id)')->fetchColumn();
        $this->raceOnRow('SELECT id FROM delivery_notes WHERE id = ? FOR UPDATE', [$note], [['operation' => 'invoice_create', 'id' => $note], ['operation' => 'invoice_create', 'id' => $note]]);
        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM invoices WHERE delivery_note_id = ?', [$note]));
        self::assertSame(0, array_sum((new \App\Repository\MySql\SalesDocumentChecks($this->pdo))->report()));
    }

    /** D-19 / ADR-025: two concurrent partial shipments of one order both commit, each with its own delivery note. */
    public function testConcurrentShipmentsOfOneOrderEachGetOneDeliveryNote(): void
    {
        $product = $this->fixtureProduct();
        [$service] = $this->sales();
        $order = $service->createDraft($this->actor, 1, 1, [['product_id' => $product, 'quantity' => 4]]);
        $service->submit($this->actor, $order->id());
        $service->approve($this->actor, $order->id());
        $item = $order->items()[0]->id();
        $this->race($product, [
            ['operation' => 'issue', 'id' => $order->id(), 'key' => str_repeat('f', 32), 'quantities' => [$item => 2]],
            ['operation' => 'issue', 'id' => $order->id(), 'key' => str_repeat('9', 32), 'quantities' => [$item => 2]],
        ], false, true);
        self::assertSame([1, 2], array_map('intval', $this->pdo->query('SELECT shipment_number FROM delivery_notes WHERE sales_order_id = ' . $order->id() . ' ORDER BY shipment_number')->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame(2, $this->sqlCount('SELECT COUNT(DISTINCT delivery_number) FROM delivery_notes WHERE sales_order_id = ?', [$order->id()]));
        self::assertSame(0, array_sum((new \App\Repository\MySql\SalesDocumentChecks($this->pdo))->report()));
    }

    /**
     * Runs two workers that both block on a row the test holds locked, then releases it: the first commits (exit 0), the
     * second is refused after the lock (exit 2).
     *
     * @param list<int> $params
     * @param list<array<string, mixed>> $jobs
     */
    private function raceOnRow(string $lockSql, array $params, array $jobs): void
    {
        $directory = sys_get_temp_dir() . '/inventory-parallel-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        $this->pdo->beginTransaction();
        $this->pdo->prepare($lockSql)->execute($params);
        try {
            foreach ($jobs as $index => $job) {
                file_put_contents($directory . '/' . $index . '.json', json_encode($job, JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/concurrency-worker.php', $directory . '/' . $index . '.json', $directory . '/' . $index . '.ready', $directory . '/gate'], [0=>['pipe','r'],1=>['file',$directory . '/' . $index . '.out','w'],2=>['file',$directory . '/' . $index . '.err','w']], $pipes, dirname(__DIR__, 2));
                self::assertIsResource($process);
                fclose($pipes[0]); $processes[] = $process;
            }
            $this->awaitFiles($directory, '.ready');
            touch($directory . '/gate');
            $this->awaitFiles($directory, '.ready.started');
            usleep(50000);
            $this->pdo->commit();
            $codes = [];
            foreach ($processes as $index => $process) {
                $codes[] = proc_close($process);
                self::assertSame('', (string) file_get_contents($directory . '/' . $index . '.err'));
            }
            $processes = [];
            sort($codes);
            self::assertSame([0, 2], $codes, 'Exactly one worker commits; the other is refused after the lock.');
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
        }
    }

    private function fixtureProduct(): int
    {
        $product = $this->products->create(new ProductInput('PARALLEL-SKU','Parallel stock','pcs',10.0,20.0,1,1), true)->id();
        $this->stock->initializeProduct($product);
        $this->stock->receive(1, [new \App\Service\StockMovement($product,5)], 1, 'TEST', 1);
        return $product;
    }

    private function sales(): array
    {
        $orders = new MySqlSalesOrderRepository($this->pdo);
        return [new SalesOrderService($orders, new \App\Repository\MySql\MySqlDocumentSequenceRepository($this->pdo), $this->products, [1=>new Customer(1,'Customer','','','',true)], [1=>new Warehouse(1,'Warehouse','',true)], $this->stock, null, new \App\Service\OrderLinePricing(), new \App\Repository\MySql\MySqlOrderExceptionRepository($this->pdo)), $orders];
    }

    /** @param list<array<string, int|string>> $jobs */
    private function race(int $product, array $jobs, bool $catalogCreation = false, bool $replay = false, int $lockWarehouse = 1): void
    {
        $directory = sys_get_temp_dir() . '/inventory-parallel-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        $this->pdo->beginTransaction();
        if ($catalogCreation) (new \App\Repository\MySql\MySqlStockCatalogRepository($this->pdo))->lockCreation();
        else $this->balances->lockByProductWarehouse($product, $lockWarehouse);
        try {
            foreach ($jobs as $index => $job) {
                file_put_contents($directory . '/' . $index . '.json', json_encode($job, JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/concurrency-worker.php', $directory . '/' . $index . '.json', $directory . '/' . $index . '.ready', $directory . '/gate'], [0=>['pipe','r'],1=>['file',$directory . '/' . $index . '.out','w'],2=>['file',$directory . '/' . $index . '.err','w']], $pipes, dirname(__DIR__, 2));
                self::assertIsResource($process);
                fclose($pipes[0]); $processes[] = $process;
            }
            $this->awaitFiles($directory, '.ready');
            touch($directory . '/gate');
            $this->awaitFiles($directory, '.ready.started');
            // Both independently connected workers begin their workflows while stock is locked.
            usleep(50000);
            $this->pdo->commit();
            $codes = [];
            foreach ($processes as $index => $process) {
                $codes[] = proc_close($process);
                self::assertSame('', (string) file_get_contents($directory . '/' . $index . '.err'));
            }
            $processes = [];
            sort($codes); self::assertSame(($catalogCreation || $replay) ? [0, 0] : [0, 2], $codes, 'Catalog creations both commit; competing movements commit only once.');
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    private function awaitFiles(string $directory, string $suffix): void
    {
        $deadline = microtime(true) + 10;
        while (!is_file($directory . '/0' . $suffix) || !is_file($directory . '/1' . $suffix)) {
            if (microtime(true) > $deadline) self::fail('Workers did not reach the concurrency barrier.');
            usleep(1000);
        }
    }

    private function sqlCount(string $sql, array $params): int
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($params);
        return (int) $statement->fetchColumn();
    }
}
