<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Controller\{AuditTrailController,BusinessOperationController,WorkQueueController};
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\MySql\{MySqlAuditLogRepository,MySqlAuditQueryRepository,MySqlBusinessOperationRepository,MySqlProductRepository,MySqlStockCatalogRepository,MySqlStockLedgerRepository,MySqlStockRepository,MySqlTransactionManager,MySqlUserRepository,MySqlWarehouseRepository,MySqlWorkQueueRepository};
use App\Security\{AuthContext,AuthGuard,SessionManager};
use App\Service\{AuditTrailService,BusinessOperationService,StockService,WorkQueueService};
use App\Support\RequestOrigin;
use PDO;
use PHPUnit\Framework\TestCase;
/** Server-rendered operations pages backed by MySQL: stock operations list, replenishment, work queue and audit trail. */
final class OperationsPagesCoverageIntegrationTest extends TestCase
{
    private PDO $pdo;
    private BusinessOperationService $operations;
    private MySqlStockRepository $balances;
    protected function setUp(): void
    {
        \Tests\Support\TestDatabase::reset();$this->pdo=\Tests\Support\TestDatabase::connect();
        $audit=new MySqlAuditLogRepository($this->pdo);$this->balances=new MySqlStockRepository($this->pdo);
        $stock=new StockService($this->balances,new MySqlStockLedgerRepository($this->pdo),$audit,new MySqlStockCatalogRepository($this->pdo),new MySqlTransactionManager($this->pdo));
        $this->operations=new BusinessOperationService(new MySqlBusinessOperationRepository($this->pdo),$stock,$audit);
    }
    private function guard(AuthContext $actor): AuthGuard {$session=new SessionManager();$session->login($actor);return new AuthGuard($session);}
    private function operationsController(AuthContext $actor): BusinessOperationController {return new BusinessOperationController($this->operations,$this->guard($actor),new MySqlProductRepository($this->pdo),new MySqlWarehouseRepository($this->pdo));}
    public function testStockOperationsListShowsEachStatusRouteAndFilters(): void
    {
        $admin=new AuthContext(1,'admin@example.test','Admin');
        $user=(new MySqlUserRepository($this->pdo))->create('Reviewer','reviewer-pages@example.test',password_hash('password',PASSWORD_DEFAULT),'Admin',true);$reviewer=new AuthContext($user->id(),$user->email(),'Admin');
        $pending=$this->operations->propose($admin,'Transfer',1,2,'Pages pending transfer',[['product_id'=>1,'quantity'=>1]]);
        $rejected=$this->operations->propose($admin,'Adjustment',1,null,'Pages rejected count',[['product_id'=>1,'quantity'=>1,'baseline'=>$this->balances->quantity(1,1)]]);
        $this->operations->decide($reviewer,$rejected,'Rejected','Recount');
        $posted=$this->operations->propose($admin,'Transfer',1,2,'Pages posted transfer',[['product_id'=>1,'quantity'=>2]]);
        $this->operations->decide($reviewer,$posted,'Approved','Ok');$this->operations->post($admin,$posted);
        $controller=$this->operationsController($admin);

        $response=$controller->index(new Request('GET','/inventory-operations',['q'=>'Pages'],[],[]));
        $body=$response->body();
        self::assertSame(200,$response->statusCode());
        self::assertStringContainsString('href="/inventory-operations/show?id='.$pending.'"',$body);
        self::assertStringContainsString(' → ',$body);
        self::assertStringContainsString('admin@example.test',$body);
        self::assertMatchesRegularExpression('/status-success">Posted</',$body);self::assertMatchesRegularExpression('/status-danger">Rejected</',$body);self::assertMatchesRegularExpression('/status-pending">PendingApproval</',$body);

        $filtered=$controller->index(new Request('GET','/inventory-operations',['q'=>'Pages','kind'=>'Adjustment','status'=>'Rejected'],[],[]))->body();
        self::assertStringContainsString('<option value="Adjustment" selected>',$filtered);self::assertStringContainsString('<option value="Rejected" selected>',$filtered);
        self::assertStringContainsString('show?id='.$rejected.'"',$filtered);self::assertStringNotContainsString('show?id='.$pending.'"',$filtered);

        $none=$controller->index(new Request('GET','/inventory-operations',['q'=>'no operation has this reason'],[],[]))->body();
        self::assertStringContainsString('No operations match these filters.',$none);

        $invalid=$controller->index(new Request('GET','/inventory-operations',['kind'=>'Teleport'],[],[]));
        self::assertSame(422,$invalid->statusCode());self::assertStringContainsString('Invalid kind.',$invalid->body());self::assertStringNotContainsString('Stock operations table',$invalid->body());
    }
    public function testReplenishmentListsSuggestionsAndOffersSelectionPerWarehouse(): void
    {
        $controller=$this->operationsController(new AuthContext(4,'warehouse@example.test','WarehouseStaff'));
        $all=$controller->recommendations(new Request('GET','/replenishment',[],[],[]));
        self::assertSame(200,$all->statusCode());
        self::assertStringContainsString('Choose one warehouse to select several recommendations',$all->body());
        self::assertStringContainsString('Review PO</a>',$all->body());self::assertStringNotContainsString('name="pick[]"',$all->body());

        $selecting=$controller->recommendations(new Request('GET','/replenishment',['warehouse_id'=>'1'],[],[]))->body();
        self::assertStringContainsString('<th scope="col" data-no-sort>Select</th>',$selecting);
        self::assertMatchesRegularExpression('/name="pick\[\]" value="\d+:\d+"/',$selecting);
        self::assertStringContainsString('Review PO for selected',$selecting);
        self::assertMatchesRegularExpression('/<option value="1" selected>/',$selecting);

        $empty=$controller->recommendations(new Request('GET','/replenishment',['q'=>'zz-no-product-zz','warehouse_id'=>'1'],[],[]))->body();
        self::assertStringContainsString('No products need additional supply.',$empty);self::assertStringContainsString('colspan="8"',$empty);self::assertStringNotContainsString('Review PO for selected',$empty);

        $invalid=$controller->recommendations(new Request('GET','/replenishment',['warehouse_id'=>'abc'],[],[]));
        self::assertSame(422,$invalid->statusCode());self::assertStringContainsString('Invalid warehouse.',$invalid->body());self::assertStringNotContainsString('Replenishment table',$invalid->body());
    }
    public function testWorkQueuePageRendersRoleTasksAndRejectsForeignTaskType(): void
    {
        $warehouse=new WorkQueueController(new WorkQueueService(new MySqlWorkQueueRepository($this->pdo)),$this->guard(new AuthContext(4,'warehouse@example.test','WarehouseStaff')));
        $response=$warehouse->index(new Request('GET','/work-queue',['type'=>'Receipt'],[],[]));
        $body=$response->body();
        self::assertSame(200,$response->statusCode());
        self::assertStringContainsString('<option value="Receipt" selected>PO receipt</option>',$body);
        self::assertMatchesRegularExpression('/href="\/purchase-orders\/show\?id=\d+"/',$body);
        self::assertStringNotContainsString('SO approval',$body);

        $none=$warehouse->index(new Request('GET','/work-queue',['q'=>'zz-no-document-zz'],[],[]))->body();
        self::assertStringContainsString('No tasks match these filters.',$none);

        $sales=new WorkQueueController(new WorkQueueService(new MySqlWorkQueueRepository($this->pdo)),$this->guard(new AuthContext(2,'sales@example.test','Sales')));
        $invalid=$sales->index(new Request('GET','/work-queue',['type'=>'Receipt'],[],[]));
        self::assertSame(422,$invalid->statusCode());self::assertStringContainsString('Invalid work queue filter.',$invalid->body());
        self::assertStringContainsString('My draft SO',$invalid->body());self::assertStringNotContainsString('PO receipt',$invalid->body());
    }
    public function testAuditTrailIsAdminOnlyAndRendersSystemActorsAndFailures(): void
    {
        $audit=new MySqlAuditLogRepository($this->pdo);
        $audit->append(null,'coverage.system_task','System',null,'failure',RequestOrigin::none());
        $audit->append(1,'coverage.admin_task','PO',7,'success',RequestOrigin::none());
        $controller=new AuditTrailController(new AuditTrailService(new MySqlAuditQueryRepository($this->pdo)),$this->guard(new AuthContext(1,'admin@example.test','Admin')));

        $failure=$controller->index(new Request('GET','/audit-trail',['action'=>'coverage.system_task','status'=>'failure'],[],[]));
        self::assertSame(200,$failure->statusCode());
        self::assertStringContainsString('System / unavailable',$failure->body());self::assertStringContainsString('status-danger">Failure',$failure->body());
        self::assertStringContainsString('<option value="failure" selected>Failure</option>',$failure->body());
        $success=$controller->index(new Request('GET','/audit-trail',['action'=>'coverage.admin_task','actor_id'=>'1'],[],[]))->body();
        self::assertStringContainsString('(#1)',$success);self::assertStringContainsString('PO 7',$success);self::assertStringContainsString('status-success">Success',$success);
        self::assertStringContainsString('No audit records match these filters.',$controller->index(new Request('GET','/audit-trail',['action'=>'coverage.never'],[],[]))->body());

        $invalid=$controller->index(new Request('GET','/audit-trail',['status'=>'maybe'],[],[]));
        self::assertSame(422,$invalid->statusCode());self::assertStringContainsString('Invalid status.',$invalid->body());self::assertStringNotContainsString('Audit trail table',$invalid->body());

        $this->expectException(HttpException::class);$this->expectExceptionMessage('Forbidden');
        (new AuditTrailController(new AuditTrailService(new MySqlAuditQueryRepository($this->pdo)),$this->guard(new AuthContext(4,'warehouse@example.test','WarehouseStaff'))))->index(new Request('GET','/audit-trail',[],[],[]));
    }
}
