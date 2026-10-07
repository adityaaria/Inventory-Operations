<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Repository\InMemory\{InMemoryBusinessOperationRepository,InMemoryStockRepository,InMemoryStockLedgerRepository,InMemoryAuditLogRepository};
use App\Service\{BusinessOperationService,StockService};
use App\Security\AuthContext;
final class BusinessOperationServiceTest extends TestCase
{
    private InMemoryStockRepository $balances;
    private InMemoryStockLedgerRepository $ledger;
    private BusinessOperationService $service;
    private AuthContext $creator;
    private AuthContext $reviewer;
    protected function setUp(): void
    {
        $this->balances=new InMemoryStockRepository();$this->balances->seed(1,1,5);$this->balances->seed(1,2,2);
        $this->ledger=new InMemoryStockLedgerRepository();$audit=new InMemoryAuditLogRepository();
        $this->service=new BusinessOperationService(new InMemoryBusinessOperationRepository(['1:1'=>['quantity'=>5],'1:2'=>['quantity'=>2]]),new StockService($this->balances,$this->ledger,$audit),$audit);
        $this->creator=new AuthContext(1,'creator@example.test','Admin');$this->reviewer=new AuthContext(2,'reviewer@example.test','Admin');
    }
    public function testTransferConservesStockAndPostingReplayDoesNothing(): void
    {
        $id=$this->service->propose($this->creator,'Transfer',1,2,'Balance warehouses',[['product_id'=>1,'quantity'=>3]]);
        $this->service->decide($this->reviewer,$id,'Approved','Checked');$this->service->post($this->creator,$id);$this->service->post($this->creator,$id);
        self::assertSame(2,$this->balances->quantity(1,1));self::assertSame(5,$this->balances->quantity(1,2));self::assertCount(2,$this->ledger->entries());self::assertSame(-3,$this->ledger->entries()[0]->delta());self::assertSame(3,$this->ledger->entries()[1]->delta());
    }
    public function testCreatorCannotApproveOwnProposal(): void
    {
        $id=$this->service->propose($this->creator,'Adjustment',1,null,'Counted',[['product_id'=>1,'quantity'=>4,'baseline'=>5]]);
        $this->expectException(\App\Exception\HttpException::class);$this->service->decide($this->creator,$id,'Approved','Self');
    }
    public function testStaleCountCannotPost(): void
    {
        $id=$this->service->propose($this->creator,'Adjustment',1,null,'Counted',[['product_id'=>1,'quantity'=>4,'baseline'=>5]]);$this->service->decide($this->reviewer,$id,'Approved','Checked');
        $this->balances->seed(1,1,6);$this->expectException(\App\Exception\HttpException::class);$this->service->post($this->creator,$id);
    }
    public function testSalesCannotProposeStockMutation(): void {$this->expectException(\App\Exception\HttpException::class);$this->service->propose(new AuthContext(3,'sales@example.test','Sales'),'Transfer',1,2,'Move',[['product_id'=>1,'quantity'=>1]]);}
    public function testDuplicateProductsAndZeroTransferRejected(): void
    {
        foreach([[['product_id'=>1,'quantity'=>0]],[['product_id'=>1,'quantity'=>1],['product_id'=>1,'quantity'=>1]]] as $items){try{$this->service->propose($this->creator,'Transfer',1,2,'Move',$items);self::fail('Invalid transfer accepted');}catch(\InvalidArgumentException $error){self::assertNotEmpty($error->getMessage());}}
    }
    public function testUnapprovedPostingRejectedWithoutMovement(): void
    {
        $id=$this->service->propose($this->creator,'Transfer',1,2,'Move',[['product_id'=>1,'quantity'=>1]]);
        try{$this->service->post($this->creator,$id);self::fail('Unapproved posting allowed');}catch(\InvalidArgumentException){self::assertSame(5,$this->balances->quantity(1,1));self::assertCount(0,$this->ledger->entries());}
    }
}
