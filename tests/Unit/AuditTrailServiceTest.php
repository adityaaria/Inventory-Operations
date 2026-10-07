<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Service\AuditTrailService;
use App\Repository\InMemory\InMemoryAuditQueryRepository;
use App\Security\AuthContext;
final class AuditTrailServiceTest extends TestCase
{
    public function testNonAdminIsDeniedBeforeReading(): void
    {
        foreach (['Sales','WarehouseStaff'] as $role) {
            try { (new AuditTrailService(new InMemoryAuditQueryRepository()))->preview(new AuthContext(1,'test@example.test',$role),[]); self::fail('Non-admin access allowed'); }
            catch (\App\Exception\HttpException $error) { self::assertSame('Forbidden',$error->getMessage()); }
        }
    }
    public function testPaginationFiltersAndDateBoundary(): void
    {
        $rows=[];
        for ($id=1;$id<=12;$id++) $rows[]=['id'=>$id,'created_at'=>'2026-10-07 23:59:59','action'=>'receipt','status'=>'success','actor_id'=>1];
        $rows[]=['id'=>13,'created_at'=>'2026-10-08 00:00:00','action'=>'receipt','status'=>'success','actor_id'=>1];
        $service=new AuditTrailService(new InMemoryAuditQueryRepository($rows));
        $result=$service->preview(new AuthContext(1,'admin@example.test','Admin'),['action'=>'receipt','to'=>'2026-10-07','page'=>'2']);
        self::assertSame(12,$result->total()); self::assertCount(2,$result->items()); self::assertSame(2,$result->items()[0]['id']);
    }
    public function testInvalidInputRejected(): void
    {
        $service=new AuditTrailService(new InMemoryAuditQueryRepository());
        foreach ([['status'=>'invented'],['from'=>'2026-02-30'],['from'=>'2026-10-08','to'=>'2026-10-07'],['actor_id'=>'0'],['action'=>[]]] as $input) {
            try { $service->filters($input); self::fail('Invalid filter accepted'); } catch (\InvalidArgumentException $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }
}
