<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Repository\MySql\MySqlOperationalHealthRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;
final class OperationalHealthIntegrationTest extends TestCase
{
    public function testReadyProbeAndFingerprintDoNotMutateBusinessData(): void
    {
        $repo = new MySqlOperationalHealthRepository(TestDatabase::connect());
        $before = $repo->fingerprint();
        $repo->assertReady();
        self::assertSame($before, $repo->fingerprint());
        self::assertArrayHasKey('stock_ledger', $before);
        self::assertGreaterThan(0, $before['products']['rows']);
    }
    public function testMissingSchemaFailsReadiness(): void
    {
        $pdo = TestDatabase::connect();
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM schema_versions WHERE version = 'phase-0'");
            $this->expectException(\RuntimeException::class);
            (new MySqlOperationalHealthRepository($pdo))->assertReady();
        } finally { $pdo->rollBack(); }
    }
}
