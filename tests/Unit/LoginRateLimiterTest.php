<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repository\InMemory\InMemoryLoginAttemptRepository;
use App\Service\LoginRateLimiter;
use PHPUnit\Framework\TestCase;

final class LoginRateLimiterTest extends TestCase
{
    public function testBlocksAfterFiveFailuresWithinWindowAndResetsOnSuccess(): void
    {
        $repository = new InMemoryLoginAttemptRepository();
        $limiter = new LoginRateLimiter($repository, 5, 900);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::assertFalse($limiter->isBlocked('admin@example.test', '127.0.0.1'));
            $limiter->recordFailure('admin@example.test', '127.0.0.1');
        }

        self::assertTrue($limiter->isBlocked('admin@example.test', '127.0.0.1'));

        $limiter->recordSuccess('admin@example.test', '127.0.0.1');

        self::assertFalse($limiter->isBlocked('admin@example.test', '127.0.0.1'));
    }
}
