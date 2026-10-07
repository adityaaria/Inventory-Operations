<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Service\OperationIdempotency;
use App\Repository\InMemory\InMemoryOperationRequestRepository;
final class OperationIdempotencyTest extends TestCase
{
    public function testCanonicalPayloadReplayAndActorScope(): void
    {
        $service=new OperationIdempotency(new InMemoryOperationRequestRepository()); $key=str_repeat('a',32);
        self::assertFalse($service->replay(1,$key,'receipt',10,[2=>3,1=>2]));
        $service->complete(1,$key);
        self::assertTrue($service->replay(1,$key,'receipt',10,[1=>2,2=>3,4=>0]));
        self::assertFalse($service->replay(2,$key,'receipt',10,[1=>2,2=>3]));
    }
    public function testDifferentPayloadConflicts(): void
    {
        $service=new OperationIdempotency(new InMemoryOperationRequestRepository()); $key=str_repeat('b',32);
        $service->replay(1,$key,'receipt',10,[1=>2]); $service->complete(1,$key);
        $this->expectException(\App\Exception\HttpException::class); $service->replay(1,$key,'receipt',10,[1=>3]);
    }
    public function testMalformedKeysRejected(): void
    {
        foreach ([null,[],str_repeat('a',33),'../file',str_repeat('A',32)] as $key) {
            try { OperationIdempotency::validateKey($key); self::fail('Invalid key accepted'); }
            catch (\App\Exception\ValidationException $error) { self::assertSame(422,$error->statusCode()); }
        }
    }
}
