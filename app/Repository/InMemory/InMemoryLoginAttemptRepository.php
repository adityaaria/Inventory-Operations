<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contract\LoginAttemptRepositoryInterface;

final class InMemoryLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    /** @var list<array{email: string, ip_address: string, successful: bool, created_at: int}> */
    private array $attempts = [];

    public function countFailures(string $email, string $ipAddress, int $sinceTimestamp): int
    {
        return count(array_filter(
            $this->attempts,
            static fn (array $attempt): bool => $attempt['email'] === strtolower(trim($email))
                && $attempt['ip_address'] === $ipAddress
                && $attempt['successful'] === false
                && $attempt['created_at'] >= $sinceTimestamp,
        ));
    }

    public function record(string $email, string $ipAddress, bool $successful): void
    {
        $this->attempts[] = [
            'email' => strtolower(trim($email)),
            'ip_address' => $ipAddress,
            'successful' => $successful,
            'created_at' => time(),
        ];
    }

    public function clearFailures(string $email, string $ipAddress): void
    {
        $email = strtolower(trim($email));
        $this->attempts = array_values(array_filter(
            $this->attempts,
            static fn (array $attempt): bool => !($attempt['email'] === $email && $attempt['ip_address'] === $ipAddress && $attempt['successful'] === false),
        ));
    }
}
