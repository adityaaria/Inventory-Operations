<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Entity\{Customer,Supplier,Warehouse};
use App\Repository\MySql\{MySqlProductRepository,MySqlPurchaseOrderRepository,MySqlSalesOrderRepository,MySqlStockRepository,MySqlStockLedgerRepository,MySqlOperationRequestRepository,MySqlAuditLogRepository,MySqlTransactionManager};
use App\Security\AuthContext;
use App\Service\{OperationIdempotency,PurchaseOrderService,SalesOrderService,StockService};
use PHPUnit\Framework\TestCase;
use PDO;
final class OperationIdempotencyIntegrationTest extends TestCase
{
    private PDO $pdo;
    private AuthContext $actor;
    protected function setUp(): void
    {
        \Tests\Support\TestDatabase::reset(); $this->pdo=\Tests\Support\TestDatabase::connect(); $this->actor=new AuthContext(1,'admin@example.test','Admin');
    }
    private function stock(): StockService
    {
        return new StockService(new MySqlStockRepository($this->pdo),new MySqlStockLedgerRepository($this->pdo),new MySqlAuditLogRepository($this->pdo),null,new MySqlTransactionManager($this->pdo));
    }
    private function purchase(?StockService $stock=null): PurchaseOrderService
    {
        return new PurchaseOrderService(new MySqlPurchaseOrderRepository($this->pdo),new MySqlProductRepository($this->pdo),[1=>new Supplier(1,'Supplier','','','',true)],[1=>new Warehouse(1,'Warehouse','',true)],$stock??$this->stock(),new OperationIdempotency(new MySqlOperationRequestRepository($this->pdo)));
    }
    public function testReceiptReplayConflictAndNewReceiptIntent(): void
    {
        $service=$this->purchase(); $order=$service->createDraft($this->actor,'PO-IDEMPOTENCY',1,1,[['product_id'=>1,'quantity'=>10,'purchase_price'=>10.0]]); $service->markOrdered($this->actor,$order->id());
        $item=$order->items()[0]->id(); $key=str_repeat('c',32); $before=(new MySqlStockRepository($this->pdo))->quantity(1,1);
        $service->receive($this->actor,$order->id(),[$item=>3],$key); $service->receive($this->actor,$order->id(),[$item=>3],$key);
        self::assertSame($before+3,(new MySqlStockRepository($this->pdo))->quantity(1,1)); self::assertCount(1,(new MySqlStockLedgerRepository($this->pdo))->forReference('PO',$order->id()));
        try { $service->receive($this->actor,$order->id(),[$item=>4],$key); self::fail('Conflict accepted'); } catch (\App\Exception\HttpException $error) { self::assertSame(409,$error->statusCode()); }
        $service->receive($this->actor,$order->id(),[$item=>7],str_repeat('d',32));
        $service->receive($this->actor,$order->id(),[$item=>3],$key);
        self::assertSame($before+10,(new MySqlStockRepository($this->pdo))->quantity(1,1));
        self::assertSame('Received',(new MySqlPurchaseOrderRepository($this->pdo))->findById($order->id())->status());
        self::assertCount(2,(new MySqlStockLedgerRepository($this->pdo))->forReference('PO',$order->id()));
    }
    public function testIssueReplayStillEnforcesRoleAndDoesNotWriteSecondAudit(): void
    {
        $service=new SalesOrderService(new MySqlSalesOrderRepository($this->pdo),new MySqlProductRepository($this->pdo),[1=>new Customer(1,'Customer','','','',true)],[1=>new Warehouse(1,'Warehouse','',true)],$this->stock(),new OperationIdempotency(new MySqlOperationRequestRepository($this->pdo)));
        $order=$service->createDraft($this->actor,'SO-IDEMPOTENCY',1,1,[['product_id'=>1,'quantity'=>1,'selling_price'=>20.0]]); $service->submit($this->actor,$order->id());$service->approve($this->actor,$order->id());
        $key=str_repeat('e',32); $before=(new MySqlStockRepository($this->pdo))->quantity(1,1);
        $service->issue($this->actor,$order->id(),$key); $service->issue($this->actor,$order->id(),$key);
        self::assertSame($before-1,(new MySqlStockRepository($this->pdo))->quantity(1,1));self::assertCount(1,(new MySqlStockLedgerRepository($this->pdo))->forReference('SO',$order->id()));
        $query=$this->pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action='sales-orders.issue' AND entity_id=?");$query->execute([$order->id()]);self::assertSame(1,(int)$query->fetchColumn());
        $this->expectException(\App\Exception\HttpException::class);$service->issue(new AuthContext(1,'admin@example.test','Sales'),$order->id(),$key);
    }
    public function testFailedAuditRollsBackKeyStockLedgerAndOrderThenKeyCanRetry(): void
    {
        $badAudit=new class implements \App\Repository\Contract\AuditLogRepositoryInterface {
            public function append(?int $actorId,string $action,string $entityType,?int $entityId,string $status,string $ipAddress,string $userAgent,array $metadata=[]): void { throw new \RuntimeException('Forced audit failure'); }
        };
        $stock=new StockService(new MySqlStockRepository($this->pdo),new MySqlStockLedgerRepository($this->pdo),$badAudit,null,new MySqlTransactionManager($this->pdo));
        $service=$this->purchase($stock);$order=$service->createDraft($this->actor,'PO-IDEMPOTENCY-ROLLBACK',1,1,[['product_id'=>1,'quantity'=>5,'purchase_price'=>10.0]]);$service->markOrdered($this->actor,$order->id());
        $key=str_repeat('f',32);$item=$order->items()[0]->id();$before=(new MySqlStockRepository($this->pdo))->quantity(1,1);
        try {$service->receive($this->actor,$order->id(),[$item=>2],$key);self::fail('Expected failure');}catch(\RuntimeException $error){self::assertSame('Forced audit failure',$error->getMessage());}
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM operation_requests')->fetchColumn());self::assertSame($before,(new MySqlStockRepository($this->pdo))->quantity(1,1));self::assertCount(0,(new MySqlStockLedgerRepository($this->pdo))->forReference('PO',$order->id()));
        self::assertSame(0,(new MySqlPurchaseOrderRepository($this->pdo))->findById($order->id())->items()[0]->receivedQuantity());
        $this->purchase()->receive($this->actor,$order->id(),[$item=>2],$key);self::assertSame($before+2,(new MySqlStockRepository($this->pdo))->quantity(1,1));
    }
    public function testRepositoryRefusesAutocommit(): void
    {
        $this->expectException(\LogicException::class);(new MySqlOperationRequestRepository($this->pdo))->replay(1,str_repeat('a',32),str_repeat('b',64));
    }
}
