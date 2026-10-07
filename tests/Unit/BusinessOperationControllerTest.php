<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Controller\BusinessOperationController;
use App\Repository\InMemory\{InMemoryBusinessOperationRepository,InMemoryStockRepository,InMemoryStockLedgerRepository,InMemoryAuditLogRepository,InMemoryProductRepository};
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Service\{BusinessOperationService,StockService};
use App\Security\{AuthContext,AuthGuard,SessionManager};
use App\Http\Request;
final class BusinessOperationControllerTest extends TestCase
{
    private BusinessOperationController $controller;
    private BusinessOperationService $service;
    private AuthContext $actor;
    protected function setUp(): void
    {
        $audit=new InMemoryAuditLogRepository();$balances=new InMemoryStockRepository();
        $this->service=new BusinessOperationService(new InMemoryBusinessOperationRepository(['1:1'=>['quantity'=>5],'1:2'=>['quantity'=>5],'2:1'=>['quantity'=>5],'2:2'=>['quantity'=>5]]),new StockService($balances,new InMemoryStockLedgerRepository(),$audit),$audit);
        $this->actor=new AuthContext(1,'admin@test','Admin');$session=new SessionManager();$session->login($this->actor);
        $warehouses=$this->createMock(WarehouseRepositoryInterface::class);$warehouses->method('all')->willReturn([]);
        $this->controller=new BusinessOperationController($this->service,new AuthGuard($session),new InMemoryProductRepository(),$warehouses);
    }
    private function payload(): array {return ['kind'=>'Transfer','warehouse_id'=>'1','destination_id'=>'2','product_id'=>'1','quantity'=>'1','reason'=>'Multiple products','items'=>[1=>['product_id'=>'2','quantity'=>'2']]];}
    public function testMultiItemProposalRetainsBothItemsAndLegacySingleItemWorks(): void
    {
        $response=$this->controller->store(new Request('POST','/inventory-operations',[],$this->payload(),[]));
        self::assertSame(302,$response->statusCode());self::assertCount(2,$this->service->show($this->actor,1)['items']);
        $post=$this->payload();unset($post['items']);self::assertSame(302,$this->controller->store(new Request('POST','/inventory-operations',[],$post,[]))->statusCode());
        self::assertCount(1,$this->service->show($this->actor,2)['items']);
    }
    public function testDuplicateAndMalformedItemsReturn422WithRetainedInput(): void
    {
        $post=$this->payload();$post['items'][1]['product_id']='1';$post['items'][1]['quantity']='42';
        $response=$this->controller->store(new Request('POST','/inventory-operations',[],$post,[]));
        self::assertSame(422,$response->statusCode());self::assertStringContainsString('name="items[1][quantity]"',$response->body());self::assertStringContainsString('value="42"',$response->body());
        $post['items']='bad';self::assertSame(422,$this->controller->store(new Request('POST','/inventory-operations',[],$post,[]))->statusCode());
        $post['items']=array_fill(0,100,['product_id'=>'2','quantity'=>'1']);self::assertSame(422,$this->controller->store(new Request('POST','/inventory-operations',[],$post,[]))->statusCode());
    }
}
