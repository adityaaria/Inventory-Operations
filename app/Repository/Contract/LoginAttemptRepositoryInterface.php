<?php

declare(strict_types=1);

namespace App\Repository\Contract;

interface LoginAttemptRepositoryInterface
{
    public function countFailures(string $email, string $ipAddress, int $sinceTimestamp): int;

    public function record(string $email, string $ipAddress, bool $successful): void;

    public function clearFailures(string $email, string $ipAddress): void;
}
