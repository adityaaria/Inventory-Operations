<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Http\Response;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Security\AuthContext;
use App\Service\AuditLogger;
use App\Support\RequestAuditRecorder;
use PHPUnit\Framework\TestCase;

final class RequestAuditRecorderTest extends TestCase
{
    public function testRecordsSuccessfulBusinessPostAndSkipsLoginLogout(): void
    {
        $repository = new InMemoryAuditLogRepository();
        $recorder = new RequestAuditRecorder(new AuditLogger($repository));
        $actor = new AuthContext(5, 'admin@example.test', 'Admin');

        $recorder->record(new Request('POST', '/sales-orders/approve', [], ['id' => '12'], ['REMOTE_ADDR' => '127.0.0.1']), new Response('', 302), $actor);
        $recorder->record(new Request('POST', '/login', [], [], []), new Response('', 302), null);

        self::assertCount(1, $repository->entries());
        self::assertSame('sales-orders.approve', $repository->entries()[0]['action']);
        self::assertSame('sales-orders', $repository->entries()[0]['entity_type']);
        self::assertSame(12, $repository->entries()[0]['entity_id']);
        self::assertSame(5, $repository->entries()[0]['actor_id']);
    }
}
