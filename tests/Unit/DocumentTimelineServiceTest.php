<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Repository\Contract\DocumentTimelineRepositoryInterface;
use App\Service\DocumentTimelineService;
use App\Security\AuthContext;
use App\Exception\HttpException;
final class DocumentTimelineServiceTest extends TestCase
{
    public function testSalesCannotReadOtherOrderOrStockTimeline(): void
    {
        $repo=$this->createMock(DocumentTimelineRepositoryInterface::class);$repo->method('document')->willReturn(['created_by'=>3]);$repo->expects(self::never())->method('events');$service=new DocumentTimelineService($repo);
        foreach(['SO','PO','Operation'] as $kind){try{$service->forDocument(new AuthContext(2,'sales@test','Sales'),$kind,1);self::fail('Unauthorized timeline accepted');}catch(HttpException $error){self::assertSame(403,$error->statusCode());}}
    }
    public function testSalesOwnHistoryOmitsPrivateMetadataAndLimitsEvents(): void
    {
        $repo=$this->createMock(DocumentTimelineRepositoryInterface::class);$repo->method('document')->willReturn(['created_by'=>2]);
        $repo->expects(self::once())->method('events')->with('SO',1,false,101)->willReturn(array_fill(0,101,['metadata'=>'{"reason":"Recorded reason","ip":"private","decision":"Rejected"}','operation_id'=>0]));
        $data=(new DocumentTimelineService($repo))->forDocument(new AuthContext(2,'sales@test','Sales'),'SO',1);self::assertTrue($data['limited']);self::assertCount(100,$data['events']);self::assertArrayNotHasKey('metadata',$data['events'][0]);self::assertSame('Recorded reason',$data['events'][0]['reason']);
    }
}
