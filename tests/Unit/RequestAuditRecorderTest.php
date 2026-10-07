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

    public function testAtomicBusinessSuccessIsNotDuplicatedButFailuresAreRecorded(): void
    {
        $repository=new InMemoryAuditLogRepository();$recorder=new RequestAuditRecorder(new AuditLogger($repository));$actor=new AuthContext(1,'admin@example.test','Admin');
        foreach(['/purchase-orders/close-remainder','/sales-orders/reject','/inventory-operations','/inventory-operations/decide','/inventory-operations/post'] as $path){$recorder->record(new Request('POST',$path,[],['id'=>'1'],[]),new Response('',302),$actor);}
        self::assertCount(0,$repository->entries());$recorder->record(new Request('POST','/inventory-operations/post',[],['id'=>'1'],[]),new Response('',422),$actor);self::assertCount(1,$repository->entries());self::assertSame('failure',$repository->entries()[0]['status']);
    }

    public function testSkipsNonPostRequests(): void
    {
        $repository = new InMemoryAuditLogRepository();
        $recorder = new RequestAuditRecorder(new AuditLogger($repository));

        $recorder->record(new Request('GET', '/products', [], [], []), new Response('', 200), null);

        self::assertCount(0, $repository->entries());
    }

    public function testSkipsRequestsWithoutPathSegments(): void
    {
        $repository = new InMemoryAuditLogRepository();
        $recorder = new RequestAuditRecorder(new AuditLogger($repository));

        $recorder->record(new Request('POST', '/', [], [], []), new Response('', 200), null);

        self::assertCount(0, $repository->entries());
    }

    public function testRecordsFailureStatusAndDefaultsOperationAndActorWhenMissing(): void
    {
        $repository = new InMemoryAuditLogRepository();
        $recorder = new RequestAuditRecorder(new AuditLogger($repository));

        $recorder->record(new Request('POST', '/products', [], [], []), new Response('', 422), null);

        self::assertCount(1, $repository->entries());
        self::assertSame('products.create', $repository->entries()[0]['action']);
        self::assertSame('failure', $repository->entries()[0]['status']);
        self::assertNull($repository->entries()[0]['entity_id']);
        self::assertNull($repository->entries()[0]['actor_id']);
    }
}
