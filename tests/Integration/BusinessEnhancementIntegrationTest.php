<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Entity\{Supplier,Customer,Warehouse};
use App\Repository\MySql\{MySqlProductRepository,MySqlPurchaseOrderRepository,MySqlSalesOrderRepository,MySqlStockRepository,MySqlStockLedgerRepository,MySqlAuditLogRepository,MySqlTransactionManager,MySqlStockCatalogRepository,MySqlBusinessOperationRepository,MySqlOrderExceptionRepository,MySqlUserRepository,MySqlOperationRequestRepository,MySqlOperationalQueryRepository};
use App\Service\{StockService,StockMovement,ProductService,BusinessOperationService,OrderExceptionService,PurchaseOrderService,SalesOrderService,OperationIdempotency};
use App\Security\AuthContext;
use App\Support\ProductInput;
use PDO;
use PHPUnit\Framework\TestCase;
final class BusinessEnhancementIntegrationTest extends TestCase
{
    private PDO $pdo;private StockService $stock;private MySqlStockRepository $balances;private MySqlProductRepository $products;private MySqlBusinessOperationRepository $repository;private BusinessOperationService $service;private AuthContext $creator;private AuthContext $reviewer;private MySqlAuditLogRepository $audit;private MySqlOrderExceptionRepository $exceptions;
    protected function setUp(): void
    {
        \Tests\Support\TestDatabase::reset();$this->pdo=\Tests\Support\TestDatabase::connect();$this->balances=new MySqlStockRepository($this->pdo);$this->audit=new MySqlAuditLogRepository($this->pdo);$this->stock=$this->stock();$this->products=new MySqlProductRepository($this->pdo);$this->repository=new MySqlBusinessOperationRepository($this->pdo);$this->service=new BusinessOperationService($this->repository,$this->stock,$this->audit);$this->exceptions=new MySqlOrderExceptionRepository($this->pdo);
        $this->creator=new AuthContext(1,'admin@example.test','Admin');$user=(new MySqlUserRepository($this->pdo))->create('Reviewer','reviewer@example.test',password_hash('password',PASSWORD_DEFAULT),'Admin',true);$this->reviewer=new AuthContext($user->id(),$user->email(),'Admin');
    }
    private function stock(?\App\Repository\Contract\AuditLogRepositoryInterface $audit=null): StockService {return new StockService($this->balances,new MySqlStockLedgerRepository($this->pdo),$audit??$this->audit,new MySqlStockCatalogRepository($this->pdo),new MySqlTransactionManager($this->pdo));}
    private function product(int $quantity=10): int
    {
        $product=(new ProductService($this->products,new \App\Service\MasterDataAuthorizationService(),$this->stock))->create($this->creator,new ProductInput('BUSINESS-TEST','Business test','pcs',10.0,20.0,15,1))->id();
        if($quantity>0)$this->stock->receive(1,[new StockMovement($product,$quantity)],1,'TEST',1);return $product;
    }
    private function purchase(): PurchaseOrderService {return new PurchaseOrderService(new MySqlPurchaseOrderRepository($this->pdo),$this->products,[1=>new Supplier(1,'Supplier','','','',true)],[1=>new Warehouse(1,'Warehouse','',true)],$this->stock,new OperationIdempotency(new MySqlOperationRequestRepository($this->pdo)),$this->exceptions);}
    private function sales(): SalesOrderService {return new SalesOrderService(new MySqlSalesOrderRepository($this->pdo),$this->products,[1=>new Customer(1,'Customer','','','',true)],[1=>new Warehouse(1,'Warehouse','',true)],$this->stock);}
    private function orderExceptions(): OrderExceptionService {return new OrderExceptionService($this->exceptions,new MySqlPurchaseOrderRepository($this->pdo),new MySqlSalesOrderRepository($this->pdo),$this->stock,$this->audit);}
    public function testMultipleProductTransferRollsBackTogetherAndPostsOnce(): void
    {
        $first=$this->product(10);
        $second=(new ProductService($this->products,new \App\Service\MasterDataAuthorizationService(),$this->stock))->create($this->creator,new ProductInput('BUSINESS-MULTI','Second product','pcs',10.0,20.0,15,1))->id();
        $this->stock->receive(1,[new StockMovement($second,10)],1,'TEST',2);
        $bad=$this->service->propose($this->creator,'Transfer',1,2,'Multiple items insufficient',[['product_id'=>$first,'quantity'=>3],['product_id'=>$second,'quantity'=>11]]);
        $this->service->decide($this->reviewer,$bad,'Approved','Reviewed');
        try{$this->service->post($this->creator,$bad);self::fail('Insufficient item accepted');}catch(\InvalidArgumentException){
            foreach([$first,$second] as $id){self::assertSame(10,$this->balances->quantity($id,1));self::assertSame(0,$this->balances->quantity($id,2));}
            self::assertSame('Approved',$this->service->show($this->creator,$bad)['status']);
            self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM stock_ledger')->fetchColumn());
        }
        $good=$this->service->propose($this->creator,'Transfer',1,2,'Multiple products',[['product_id'=>$first,'quantity'=>3],['product_id'=>$second,'quantity'=>4]]);
        $this->service->decide($this->reviewer,$good,'Approved','Reviewed');$this->service->post($this->creator,$good);$this->service->post($this->creator,$good);
        self::assertSame(7,$this->balances->quantity($first,1));self::assertSame(3,$this->balances->quantity($first,2));
        self::assertSame(6,$this->balances->quantity($second,1));self::assertSame(4,$this->balances->quantity($second,2));
        self::assertSame(6,(int)$this->pdo->query('SELECT COUNT(*) FROM stock_ledger')->fetchColumn());
    }
    public function testRecommendationWarehouseFilterKeepsCountAndPageInOneWarehouse(): void
    {
        $this->product(0);
        $all=$this->repository->recommendations('BUSINESS-TEST',10,0);self::assertGreaterThan(1,count(array_unique(array_column($all,'warehouse_id'))),'Seed must stock the product in several active warehouses.');
        $one=$this->repository->recommendations('BUSINESS-TEST',10,0,1);
        self::assertSame(['1'],array_values(array_unique(array_map('strval',array_column($one,'warehouse_id')))));
        self::assertSame(count($one),$this->repository->recommendationCount('BUSINESS-TEST',1));
        self::assertSame(count($all),$this->repository->recommendationCount('BUSINESS-TEST'));
        $page=$this->service->recommendations($this->creator,['q'=>'BUSINESS-TEST','warehouse_id'=>'1']);self::assertSame(count($one),$page->total());
        try{$this->service->recommendations($this->creator,['warehouse_id'=>'0']);self::fail('Invalid warehouse accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}
    }
    public function testCloseRemainderPreservesReceiptAndChangesInboundRecommendation(): void
    {
        $product=$this->product(0);$purchase=$this->purchase();$order=$purchase->createDraft($this->creator,'PO-CLOSE',1,1,[['product_id'=>$product,'quantity'=>10,'purchase_price'=>10.0]]);$purchase->markOrdered($this->creator,$order->id());$item=$order->items()[0]->id();$key=str_repeat('c',32);$purchase->receive($this->creator,$order->id(),[$item=>3],$key);
        $before=$this->repository->recommendations('BUSINESS-TEST',10,0);$source=array_values(array_filter($before,static fn(array $r):bool=>(int)$r['warehouse_id']===1))[0];self::assertSame(7,(int)$source['inbound']);self::assertSame(5,(int)$source['suggested']);
        $this->orderExceptions()->close($this->creator,$order->id(),'Supplier cannot deliver remaining goods');$purchase->receive($this->creator,$order->id(),[$item=>3],$key);
        try{$purchase->receive($this->creator,$order->id(),[$item=>1],str_repeat('d',32));self::fail('Closed PO received again');}catch(\InvalidArgumentException){self::assertSame(3,$this->balances->quantity($product,1));}
        $after=$this->repository->recommendations('BUSINESS-TEST',10,0);$source=array_values(array_filter($after,static fn(array $r):bool=>(int)$r['warehouse_id']===1))[0];self::assertSame(0,(int)$source['inbound']);self::assertSame(12,(int)$source['suggested']);
        self::assertSame('PartiallyReceived',(new MySqlPurchaseOrderRepository($this->pdo))->findById($order->id())->status());self::assertCount(1,(new MySqlStockLedgerRepository($this->pdo))->forReference('PO',$order->id()));
    }
    public function testRejectPendingSalesOrderRequiresReasonAndKeepsOfficialStatus(): void
    {
        $product=$this->product();$sales=$this->sales();$order=$sales->createDraft($this->creator,'SO-REJECT',1,1,[['product_id'=>$product,'quantity'=>1,'selling_price'=>20.0]]);$sales->submit($this->creator,$order->id());
        try{$this->orderExceptions()->reject($this->creator,$order->id(),'');self::fail('Empty reason accepted');}catch(\InvalidArgumentException){self::assertSame('PendingApproval',(new MySqlSalesOrderRepository($this->pdo))->findById($order->id())->status());}
        $this->orderExceptions()->reject($this->reviewer,$order->id(),'Customer credit needs review');self::assertSame('Cancelled',(new MySqlSalesOrderRepository($this->pdo))->findById($order->id())->status());self::assertSame('Customer credit needs review',$this->exceptions->rejection($order->id())['reason']);self::assertSame(10,$this->balances->quantity($product,1));
    }
    public function testAdjustmentSignedLedgerAndReportNet(): void
    {
        $product=$this->product();$id=$this->service->propose($this->creator,'Adjustment',1,null,'Counted less',[['product_id'=>$product,'quantity'=>7,'baseline'=>10]]);$this->service->decide($this->reviewer,$id,'Approved','Verified count');$this->service->post($this->creator,$id);$this->service->post($this->creator,$id);
        self::assertSame(7,$this->balances->quantity($product,1));$ledger=(new MySqlStockLedgerRepository($this->pdo))->forReference('ADJUSTMENT',$id);self::assertCount(1,$ledger);self::assertSame(-3,$ledger[0]->delta());self::assertSame(3,$ledger[0]->quantity());
        $query=new MySqlOperationalQueryRepository($this->pdo);$summary=$query->reportSummary('stock-ledger',null,null,null);self::assertSame(-3,$summary['adjusted']);$rows=$query->stockLedgerRows(null,null);$adjustments=array_values(array_filter($rows,static fn(array $r):bool=>$r['Movement']==='Adjustment'));self::assertSame(-3,(int)$adjustments[0]['Quantity']);
    }
    public function testTransferConservationAndAuditFailureRollsEverythingBack(): void
    {
        $product=$this->product();$id=$this->service->propose($this->creator,'Transfer',1,2,'Move stock',[['product_id'=>$product,'quantity'=>4]]);$this->service->decide($this->reviewer,$id,'Approved','Verified');
        $bad=new class implements \App\Repository\Contract\AuditLogRepositoryInterface {public function append(?int $actorId,string $action,string $entityType,?int $entityId,string $status,string $ipAddress,string $userAgent,array $metadata=[]):void{throw new \RuntimeException('Forced audit failure');}};
        try{(new BusinessOperationService($this->repository,$this->stock($bad),$bad))->post($this->creator,$id);self::fail('Expected failure');}catch(\RuntimeException $error){self::assertSame('Forced audit failure',$error->getMessage());}
        self::assertSame(10,$this->balances->quantity($product,1));self::assertSame(0,$this->balances->quantity($product,2));self::assertSame('Approved',$this->repository->find($id)['status']);self::assertCount(0,(new MySqlStockLedgerRepository($this->pdo))->forReference('TRANSFER',$id));
        $this->service->post($this->creator,$id);self::assertSame(6,$this->balances->quantity($product,1));self::assertSame(4,$this->balances->quantity($product,2));$movements=(new MySqlStockLedgerRepository($this->pdo))->forReference('TRANSFER',$id);self::assertCount(2,$movements);self::assertSame(0,array_sum(array_map(static fn($m):int=>$m->delta(),$movements)));
    }
    public function testSupplierReturnCannotExceedOriginalReceiptOrAvailableStock(): void
    {
        $product=$this->product(0);$purchase=$this->purchase();$order=$purchase->createDraft($this->creator,'PO-RETURN',1,1,[['product_id'=>$product,'quantity'=>5,'purchase_price'=>10.0]]);$purchase->markOrdered($this->creator,$order->id());$purchase->receive($this->creator,$order->id(),[$order->items()[0]->id()=>5]);$source=(new MySqlStockLedgerRepository($this->pdo))->forReference('PO',$order->id())[0]->id();
        $ids=[];foreach([3,3] as $quantity){$id=$this->service->propose($this->creator,'SupplierReturn',0,null,'Return to supplier',[['quantity'=>$quantity,'source_ledger_id'=>$source]]);$this->service->decide($this->reviewer,$id,'Approved','Checked');$ids[]=$id;}
        $this->service->post($this->creator,$ids[0]);$this->service->post($this->creator,$ids[0]);
        try{$this->service->post($this->creator,$ids[1]);self::fail('Over-return accepted');}catch(\InvalidArgumentException){self::assertSame(2,$this->balances->quantity($product,1));self::assertSame('Approved',$this->repository->find($ids[1])['status']);}
        self::assertSame(3,(int)$this->pdo->query('SELECT returned_quantity FROM inventory_return_totals WHERE source_ledger_id='.$source)->fetchColumn());self::assertSame('Received',(new MySqlPurchaseOrderRepository($this->pdo))->findById($order->id())->status());
    }
    public function testCustomerReturnRequiresFitConfirmationAndPreservesFulfilledOrder(): void
    {
        $product=$this->product();$sales=$this->sales();$order=$sales->createDraft($this->creator,'SO-RETURN',1,1,[['product_id'=>$product,'quantity'=>4,'selling_price'=>20.0]]);$sales->submit($this->creator,$order->id());$sales->approve($this->creator,$order->id());$sales->issue($this->creator,$order->id());$source=(new MySqlStockLedgerRepository($this->pdo))->forReference('SO',$order->id())[0]->id();
        try{$this->service->propose($this->creator,'CustomerReturn',0,null,'Customer return',[['quantity'=>2,'source_ledger_id'=>$source]]);self::fail('Unconfirmed condition accepted');}catch(\InvalidArgumentException){self::assertSame(6,$this->balances->quantity($product,1));}
        $id=$this->service->propose($this->creator,'CustomerReturn',0,null,'Customer return',[['quantity'=>2,'source_ledger_id'=>$source,'fit_for_stock'=>true]]);$this->service->decide($this->reviewer,$id,'Approved','Goods inspected');$this->service->post($this->creator,$id);self::assertSame(8,$this->balances->quantity($product,1));self::assertSame('Fulfilled',(new MySqlSalesOrderRepository($this->pdo))->findById($order->id())->status());
    }
    public function testStaleApprovedCountAndInsufficientTransferLeaveOperationUnposted(): void
    {
        $product=$this->product();$count=$this->service->propose($this->creator,'Adjustment',1,null,'Count',[['product_id'=>$product,'quantity'=>9,'baseline'=>10]]);$this->service->decide($this->reviewer,$count,'Approved','Checked');$this->stock->receive(1,[new StockMovement($product,1)],1,'TEST',2);
        try{$this->service->post($this->creator,$count);self::fail('Stale count posted');}catch(\App\Exception\HttpException $error){self::assertSame(409,$error->statusCode());self::assertSame(11,$this->balances->quantity($product,1));self::assertSame('Approved',$this->repository->find($count)['status']);}
        $transfer=$this->service->propose($this->creator,'Transfer',1,2,'Move too many',[['product_id'=>$product,'quantity'=>12]]);$this->service->decide($this->reviewer,$transfer,'Approved','Checked');try{$this->service->post($this->creator,$transfer);self::fail('Negative balance accepted');}catch(\InvalidArgumentException){self::assertSame(11,$this->balances->quantity($product,1));self::assertSame(0,$this->balances->quantity($product,2));}
    }
    public function testMigrationCanRepeatWithoutReseedingOrChangingLedger(): void
    {
        $product=$this->product();$before=$this->balances->quantity($product,1);$command=[PHP_BINARY,'scripts/migrate-business-operations.php'];
        foreach([1,2] as $run){$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));self::assertIsResource($process);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process));self::assertSame('',$error);self::assertSame('ready',json_decode($output,true)['status']);}self::assertSame($before,$this->balances->quantity($product,1));
    }
}
