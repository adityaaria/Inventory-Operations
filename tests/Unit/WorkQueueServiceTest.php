<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Repository\Contract\WorkQueueRepositoryInterface;
use App\Service\WorkQueueService;
use App\Security\AuthContext;
final class WorkQueueServiceTest extends TestCase
{
    public function testRoleAndActorScopePaginationAndLinkArePassedThroughBoundary(): void
    {
        $repo=$this->createMock(WorkQueueRepositoryInterface::class);
        $repo->expects(self::once())->method('counts')->with('Sales',7,'SalesDraft','order')->willReturn(['SalesDraft'=>12]);
        $repo->expects(self::once())->method('page')->with('Sales',7,'SalesDraft','order',10,10)->willReturn([['id'=>99,'task_type'=>'SalesDraft']]);
        $result=(new WorkQueueService($repo))->search(new AuthContext(7,'sales@test','Sales'),['type'=>'SalesDraft','q'=>' order ','page'=>'2']);
        self::assertSame(12,$result['result']->total());self::assertSame('/sales-orders/show?id=99',$result['result']->items()[0]['url']);
    }
    public function testInvalidFiltersNeverQueryRepository(): void
    {
        $repo=$this->createMock(WorkQueueRepositoryInterface::class);$repo->expects(self::never())->method('counts');$service=new WorkQueueService($repo);$actor=new AuthContext(7,'sales@test','Sales');
        foreach([['type'=>'Posting'],['q'=>['bad']],['q'=>str_repeat('a',121)]] as $input){try{$service->search($actor,$input);self::fail('Invalid filter accepted');}catch(\InvalidArgumentException $error){self::assertNotEmpty($error->getMessage());}}
    }
}
