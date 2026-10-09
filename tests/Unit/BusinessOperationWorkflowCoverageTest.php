<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Controller\{BusinessOperationController,DocumentTimelineController};
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\Contract\{DocumentTimelineRepositoryInterface,WarehouseRepositoryInterface};
use App\Repository\InMemory\{InMemoryAuditLogRepository,InMemoryBusinessOperationRepository,InMemoryProductRepository,InMemoryStockLedgerRepository,InMemoryStockRepository};
use App\Security\{AuthContext,AuthGuard,SessionManager};
use App\Service\{BusinessOperationService,DocumentTimelineService,StockService};
use PHPUnit\Framework\TestCase;
/** Stock-operation decide/post/cancel through the controller, plus timeline rendering of recorded proposal decisions. */
final class BusinessOperationWorkflowCoverageTest extends TestCase
{
    private BusinessOperationService $service;
    private InMemoryStockRepository $balances;
    private InMemoryStockLedgerRepository $ledger;
    private InMemoryAuditLogRepository $audit;
    private AuthContext $creator;
    protected function setUp(): void
    {
        $this->audit=new InMemoryAuditLogRepository();$this->balances=new InMemoryStockRepository();$this->ledger=new InMemoryStockLedgerRepository();
        $this->balances->seed(1,1,5);
        $this->service=new BusinessOperationService(new InMemoryBusinessOperationRepository(['1:1'=>['quantity'=>5],'1:2'=>['quantity'=>0]]),new StockService($this->balances,$this->ledger,$this->audit),$this->audit);
        $this->creator=new AuthContext(1,'admin@test','Admin');
    }
    private function controller(AuthContext $actor): BusinessOperationController
    {
        $session=new SessionManager();$session->login($actor);
        $warehouses=$this->createMock(WarehouseRepositoryInterface::class);$warehouses->method('all')->willReturn([]);
        return new BusinessOperationController($this->service,new AuthGuard($session),new InMemoryProductRepository(),$warehouses);
    }
    private function proposeTransfer(int $quantity=2): int
    {
        return $this->service->propose($this->creator,'Transfer',1,2,'Rebalance stock',[['product_id'=>1,'quantity'=>$quantity]]);
    }
    public function testApprovedTransferPostsOnceWithBalancedLedger(): void
    {
        $id=$this->proposeTransfer();
        $this->service->decide(new AuthContext(2,'reviewer@test','Admin'),$id,'Approved','Counted');
        $response=$this->controller($this->creator)->post(new Request('POST','/inventory-operations/post',[],['id'=>(string)$id],[]));
        self::assertSame(302,$response->statusCode());self::assertSame('/inventory-operations/show?id='.$id,$response->headers()['Location']);
        self::assertSame('Posted',$this->service->show($this->creator,$id)['status']);
        self::assertSame(3,$this->balances->quantity(1,1));self::assertSame(2,$this->balances->quantity(1,2));
        self::assertCount(2,$this->ledger->entries());self::assertSame(0,array_sum(array_map(static fn($entry): int => $entry->delta(),$this->ledger->entries())));
        // A repeated post is a no-op: no second ledger pair.
        self::assertSame(302,$this->controller($this->creator)->post(new Request('POST','/inventory-operations/post',[],['id'=>(string)$id],[]))->statusCode());
        self::assertCount(2,$this->ledger->entries());
    }
    public function testPostingWithoutIndependentApprovalRenders422AndChangesNothing(): void
    {
        $id=$this->proposeTransfer();
        $response=$this->controller($this->creator)->post(new Request('POST','/inventory-operations/post',[],['id'=>(string)$id],[]));
        self::assertSame(422,$response->statusCode());self::assertStringContainsString('Independent approval is required before posting.',$response->body());
        self::assertSame('PendingApproval',$this->service->show($this->creator,$id)['status']);
        self::assertSame(5,$this->balances->quantity(1,1));self::assertSame([],$this->ledger->entries());
    }
    public function testCreatorCancelsPendingProposal(): void
    {
        $id=$this->proposeTransfer();
        $response=$this->controller($this->creator)->decide(new Request('POST','/inventory-operations/decide',[],['id'=>(string)$id,'decision'=>'Cancelled','reason'=>'No longer needed'],[]));
        self::assertSame(302,$response->statusCode());self::assertSame('Cancelled',$this->service->show($this->creator,$id)['status']);
        self::assertSame(5,$this->balances->quantity(1,1));self::assertSame([],$this->ledger->entries());
    }
    public function testOtherWarehouseStaffCannotCancelSomeoneElsesProposal(): void
    {
        $id=$this->proposeTransfer();
        try{$this->controller(new AuthContext(5,'warehouse@test','WarehouseStaff'))->decide(new Request('POST','/inventory-operations/decide',[],['id'=>(string)$id,'decision'=>'Cancelled','reason'=>'Not mine'],[]));self::fail('Foreign cancellation accepted');}
        catch(HttpException $exception){self::assertSame(403,$exception->statusCode());}
        self::assertSame('PendingApproval',$this->service->show($this->creator,$id)['status']);
    }
    public function testFinishedProposalCannotBeCancelledOrReviewedAgainAndErrorShowsOnPage(): void
    {
        $id=$this->proposeTransfer();
        $reviewer=new AuthContext(2,'reviewer@test','Admin');
        $this->service->decide($reviewer,$id,'Rejected','Wrong count');
        $cancel=$this->controller($this->creator)->decide(new Request('POST','/inventory-operations/decide',[],['id'=>(string)$id,'decision'=>'Cancelled','reason'=>'Late cancel'],[]));
        self::assertSame(422,$cancel->statusCode());self::assertStringContainsString('This operation cannot be cancelled.',$cancel->body());
        $approve=$this->controller($reviewer)->decide(new Request('POST','/inventory-operations/decide',[],['id'=>(string)$id,'decision'=>'Approved','reason'=>'Second look'],[]));
        self::assertSame(422,$approve->statusCode());self::assertStringContainsString('Only pending operations can be reviewed.',$approve->body());
        self::assertDoesNotMatchRegularExpression('/id="reject-dialog" >/',$approve->body());
        self::assertSame('Rejected',$this->service->show($this->creator,$id)['status']);
    }
    public function testCreateFormKeepsRequestedKindAndSourceAndSalesIsForbidden(): void
    {
        $response=$this->controller($this->creator)->create(new Request('GET','/inventory-operations/create',['kind'=>'SupplierReturn','source_ledger_id'=>'7','ignored'=>'x'],[],[]));
        self::assertSame(200,$response->statusCode());self::assertStringContainsString('value="7"',$response->body());self::assertStringContainsString('SupplierReturn',$response->body());
        $this->expectException(HttpException::class);$this->expectExceptionMessage('Forbidden');
        $this->controller(new AuthContext(3,'sales@test','Sales'))->create(new Request('GET','/inventory-operations/create',[],[],[]));
    }
    public function testIndexAndRecommendationsAreForbiddenForSales(): void
    {
        foreach(['index','recommendations'] as $action){
            try{$this->controller(new AuthContext(3,'sales@test','Sales'))->{$action}(new Request('GET','/inventory-operations',[],[],[]));self::fail($action.' allowed for Sales');}
            catch(HttpException $exception){self::assertSame(403,$exception->statusCode());}
        }
    }
    public function testTimelineControllerRendersDecisionTitlesLinksAndLimitNotice(): void
    {
        $event=static fn(string $decision,int $operation): array => ['event_at'=>'2026-10-01 10:00:00','title'=>'inventory-operations.decide','actor'=>'admin@test','detail'=>'','metadata'=>json_encode(['decision'=>$decision,'reason'=>'Because '.$decision]),'operation_id'=>$operation];
        $events=[$event('Approved',4),$event('Rejected',0),$event('Cancelled',0),$event('Other',0),['event_at'=>'2026-10-01','title'=>'purchase-orders.order','actor'=>null,'detail'=>'Ordered','metadata'=>null,'operation_id'=>0]];
        $events=array_merge($events,array_fill(0,96,$event('Approved',0)));
        $repo=$this->createMock(DocumentTimelineRepositoryInterface::class);
        $repo->method('document')->willReturn(['created_by'=>1]);$repo->method('links')->willReturn([['kind'=>'PO','id'=>9],['kind'=>'SO','id'=>8]]);
        $repo->expects(self::once())->method('events')->with('Operation',3,true,101)->willReturn($events);
        $session=new SessionManager();$session->login($this->creator);
        $response=(new DocumentTimelineController(new DocumentTimelineService($repo),new AuthGuard($session)))->index(new Request('GET','/timeline',['kind'=>'Operation','id'=>'3'],[],[]));
        $body=$response->body();
        self::assertSame(200,$response->statusCode());
        foreach(['Stock proposal approved','Stock proposal rejected','Stock proposal cancelled','Stock proposal reviewed','PO ordered','By Unavailable actor','Because Rejected'] as $text){self::assertStringContainsString($text,$body);}
        self::assertStringContainsString('href="/inventory-operations/show?id=4">View related return',$body);
        self::assertStringContainsString('href="/purchase-orders/show?id=9">Original PO #9',$body);self::assertStringContainsString('href="/sales-orders/show?id=8">Original SO #8',$body);
        self::assertStringContainsString('Showing the latest 100 events.',$body);
        self::assertStringContainsString('href="/inventory-operations/show?id=3">Back to document',$body);
    }
    public function testTimelineBackLinkFollowsDocumentKindAndUnknownKindIs404(): void
    {
        $repo=$this->createMock(DocumentTimelineRepositoryInterface::class);$repo->method('document')->willReturn(['created_by'=>1]);$repo->method('links')->willReturn([]);$repo->method('events')->willReturn([]);
        $session=new SessionManager();$session->login($this->creator);$controller=new DocumentTimelineController(new DocumentTimelineService($repo),new AuthGuard($session));
        self::assertStringContainsString('href="/purchase-orders/show?id=2">Back to document',$controller->index(new Request('GET','/timeline',['kind'=>'PO','id'=>'2'],[],[]))->body());
        $so=$controller->index(new Request('GET','/timeline',['kind'=>'SO','id'=>'5'],[],[]))->body();
        self::assertStringContainsString('href="/sales-orders/show?id=5">Back to document',$so);self::assertStringNotContainsString('Showing the latest 100 events.',$so);
        $this->expectException(HttpException::class);$this->expectExceptionMessage('Document not found.');
        $controller->index(new Request('GET','/timeline',['kind'=>'Invoice','id'=>'5'],[],[]));
    }
}
