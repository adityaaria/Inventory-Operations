<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Service\AuditLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditLoggerTest extends TestCase
{
    public function testRecordsAuditEventWithContextAndMetadata(): void
    {
        $repository = new InMemoryAuditLogRepository();
        $logger = new AuditLogger($repository);

        $logger->record(7, 'user.updated', 'users', 12, 'success', new \App\Support\RequestOrigin('127.0.0.1', 'Unit Test'), ['field' => 'role']);

        self::assertCount(1, $repository->entries());
        self::assertSame('user.updated', $repository->entries()[0]['action']);
        self::assertSame(7, $repository->entries()[0]['actor_id']);
        self::assertSame(['field' => 'role'], $repository->entries()[0]['metadata']);
    }

    public function testSwallowsRepositoryFailureWithoutPropagating(): void
    {
        $repository = new class implements AuditLogRepositoryInterface {
            public function append(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, \App\Support\RequestOrigin $origin, array $metadata = []): void
            {
                throw new RuntimeException('Audit storage unavailable.');
            }
        };
        $logger = new AuditLogger($repository);

        $logger->record(1, 'user.updated', 'users', 1, 'success', new \App\Support\RequestOrigin('127.0.0.1', 'Unit Test'));

        self::assertTrue(true);
    }
}
