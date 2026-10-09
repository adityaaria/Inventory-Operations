<?php
declare(strict_types=1);
namespace Tests\Integration;
use PHPUnit\Framework\TestCase;
use App\Repository\MySql\{MySqlDocumentTimelineRepository,MySqlAuditLogRepository};
use App\Service\DocumentTimelineService;
use App\Security\AuthContext;
final class DocumentTimelineIntegrationTest extends TestCase
{
    public function testRecordedEventsIncludeSuccessfulReasonAndLedgerWithoutPrivateOrFailedData(): void
    {
        \Tests\Support\TestDatabase::reset();$pdo=\Tests\Support\TestDatabase::connect();
        $id=(int)$pdo->query('SELECT id FROM purchase_orders ORDER BY id LIMIT 1')->fetchColumn();
        $audit=new MySqlAuditLogRepository($pdo);$audit->append(1,'purchase-orders.cancel','purchase-orders',$id,'success',new \App\Support\RequestOrigin('private-ip', 'private-agent'),['reason'=>'Recorded cancellation','secret'=>'not exposed']);
        $audit->append(1,'purchase-orders.receive','PO',$id,'failure',new \App\Support\RequestOrigin('', ''),['reason'=>'Failed-only reason']);
        $service=new DocumentTimelineService(new MySqlDocumentTimelineRepository($pdo));$data=$service->forDocument(new AuthContext(1,'admin@test','Admin'),'PO',$id);
        self::assertContains('Document created',array_column($data['events'],'title'));self::assertContains('Recorded cancellation',array_column($data['events'],'reason'));self::assertNotContains('Failed-only reason',array_column($data['events'],'reason'));
        self::assertStringNotContainsString('private-ip',json_encode($data,JSON_THROW_ON_ERROR));self::assertStringNotContainsString('not exposed',json_encode($data,JSON_THROW_ON_ERROR));
        $dates=array_column($data['events'],'event_at');$sorted=$dates;rsort($sorted);self::assertSame($sorted,$dates);
    }
    public function testOwnSalesOrderOnlyAndNoRelatedStockLinks(): void
    {
        \Tests\Support\TestDatabase::reset();$pdo=\Tests\Support\TestDatabase::connect();$id=(int)$pdo->query('SELECT id FROM sales_orders WHERE created_by=2 LIMIT 1')->fetchColumn();self::assertGreaterThan(0,$id);
        $data=(new DocumentTimelineService(new MySqlDocumentTimelineRepository($pdo)))->forDocument(new AuthContext(2,'sales@test','Sales'),'SO',$id);self::assertSame([],$data['links']);foreach($data['events'] as $event)self::assertSame('',$event['url']);
    }
}
