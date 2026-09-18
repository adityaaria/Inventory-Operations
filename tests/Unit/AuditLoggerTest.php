<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Service\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    public function testRecordsAuditEventWithContextAndMetadata(): void
    {
        $repository = new InMemoryAuditLogRepository();
        $logger = new AuditLogger($repository);

        $logger->record(7, 'user.updated', 'users', 12, 'success', '127.0.0.1', 'Unit Test', ['field' => 'role']);

        self::assertCount(1, $repository->entries());
        self::assertSame('user.updated', $repository->entries()[0]['action']);
        self::assertSame(7, $repository->entries()[0]['actor_id']);
        self::assertSame(['field' => 'role'], $repository->entries()[0]['metadata']);
    }
}
