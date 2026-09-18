<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\LoginAttemptRepositoryInterface;

final class LoginRateLimiter
{
    public function __construct(
        private readonly LoginAttemptRepositoryInterface $attempts,
        private readonly int $maxFailures = 5,
        private readonly int $windowSeconds = 900,
    ) {
    }

    public function isBlocked(string $email, string $ipAddress): bool
    {
        return $this->attempts->countFailures($email, $ipAddress, time() - $this->windowSeconds) >= $this->maxFailures;
    }

    public function recordFailure(string $email, string $ipAddress): void
    {
        $this->attempts->record($email, $ipAddress, false);
    }

    public function recordSuccess(string $email, string $ipAddress): void
    {
        $this->attempts->record($email, $ipAddress, true);
        $this->attempts->clearFailures($email, $ipAddress);
    }
}
