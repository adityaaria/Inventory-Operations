<?php
declare(strict_types=1);
namespace Tests\Integration;
use PHPUnit\Framework\TestCase;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlAuditQueryRepository;
final class AuditTrailQueryIntegrationTest extends TestCase
{
    public function testPreparedFiltersStablePaginationAndNoSensitiveColumns(): void
    {
        $pdo=\Tests\Support\TestDatabase::connect(); $pdo->beginTransaction();
        try {
            $action='audit.test.'.bin2hex(random_bytes(4)); $writer=new MySqlAuditLogRepository($pdo);
            for ($i=0;$i<12;$i++) $writer->append(null,$action,'test',null,'success','127.0.0.1','test',['secret'=>'must not be selected']);
            $queries=new MySqlAuditQueryRepository($pdo); $filters=['action'=>$action,'status'=>'success'];
            self::assertSame(12,$queries->count($filters));
            $first=$queries->page($filters,10,0); $last=$queries->page($filters,10,10);
            self::assertCount(10,$first); self::assertCount(2,$last);
            self::assertGreaterThan((int)$last[0]['id'],(int)$first[9]['id']);
            self::assertArrayNotHasKey('metadata_json',$first[0]);
            self::assertArrayNotHasKey('ip_address',$first[0]);
            self::assertSame(0,$queries->count(['action'=>"' OR 1=1 --"]));
            $date=substr($first[0]['created_at'],0,10);
            self::assertSame(12,$queries->count(['action'=>$action,'from'=>$date,'to'=>$date]));
        } finally { $pdo->rollBack(); }
    }
}
