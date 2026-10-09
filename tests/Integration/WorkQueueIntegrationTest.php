<?php
declare(strict_types=1);
namespace Tests\Integration;
use PHPUnit\Framework\TestCase;
use App\Repository\MySql\MySqlWorkQueueRepository;
use App\Service\WorkQueueService;
use App\Security\AuthContext;
use PDO;
final class WorkQueueIntegrationTest extends TestCase
{
    private PDO $pdo;
    private WorkQueueService $service;
    protected function setUp(): void {\Tests\Support\TestDatabase::reset();$this->pdo=\Tests\Support\TestDatabase::connect();$this->service=new WorkQueueService(new MySqlWorkQueueRepository($this->pdo));}
    public function testSalesQueueIsOwnOrderScopedAndCannotRequestWarehouseTasks(): void
    {
        foreach([2,3] as $actor){$data=$this->service->search(new AuthContext($actor,'sales@test','Sales'),[]);foreach($data['result']->items() as $row){$statement=$this->pdo->prepare('SELECT created_by FROM sales_orders WHERE id=:id');$statement->execute(['id'=>$row['id']]);self::assertSame($actor,(int)$statement->fetchColumn());self::assertContains($row['task_type'],['SalesDraft','SalesFollowUp']);self::assertStringStartsWith('/sales-orders/show?id=',$row['url']);}}
        $this->expectException(\InvalidArgumentException::class);$this->service->search(new AuthContext(2,'sales@test','Sales'),['type'=>'Receipt']);
    }
    public function testClosedRemainderIsAbsentAndWarehouseHasNoApprovalTasks(): void
    {
        $id=(int)$this->pdo->query("SELECT id FROM purchase_orders WHERE status='PartiallyReceived' AND id NOT IN (SELECT purchase_order_id FROM purchase_order_closures) LIMIT 1")->fetchColumn();self::assertGreaterThan(0,$id);
        $before=$this->service->search(new AuthContext(4,'warehouse@test','WarehouseStaff'),['type'=>'Receipt']);
        $statement=$this->pdo->prepare('INSERT INTO purchase_order_closures(purchase_order_id,closed_by,reason) VALUES(:id,1,:reason)');$statement->execute(['id'=>$id,'reason'=>'Supply closed']);
        $after=$this->service->search(new AuthContext(4,'warehouse@test','WarehouseStaff'),['type'=>'Receipt']);self::assertSame($before['result']->total()-1,$after['result']->total());
        foreach($after['result']->items() as $row)self::assertNotSame($id,(int)$row['id']);
        $this->expectException(\InvalidArgumentException::class);$this->service->search(new AuthContext(4,'warehouse@test','WarehouseStaff'),['type'=>'OperationApproval']);
    }
    public function testIndependentApprovalQueueExcludesCreatorAndSearchTreatsWildcardsLiterally(): void
    {
        $statement=$this->pdo->prepare("INSERT INTO inventory_operations(kind,warehouse_id,reason,created_by) VALUES('Adjustment',1,:reason,:actor)");
        $statement->execute(['reason'=>'Own proposal','actor'=>1]);$own=(int)$this->pdo->lastInsertId();
        for($i=0;$i<12;$i++)$statement->execute(['reason'=>'Review queue '.$i,'actor'=>4]);
        $data=$this->service->search(new AuthContext(1,'admin@test','Admin'),['type'=>'OperationApproval']);self::assertSame(12,$data['result']->total());self::assertCount(10,$data['result']->items());foreach($data['result']->items() as $row){self::assertNotSame($own,(int)$row['id']);self::assertGreaterThanOrEqual(0,(int)$row['age_days']);}
        $page=$this->service->search(new AuthContext(1,'admin@test','Admin'),['type'=>'OperationApproval','page'=>'2']);self::assertCount(2,$page['result']->items());
        self::assertSame(0,$this->service->search(new AuthContext(1,'admin@test','Admin'),['q'=>'%'])['result']->total());
        self::assertSame(0,$this->service->search(new AuthContext(1,'admin@test','Admin'),['q'=>'_'])['result']->total());
    }
}
