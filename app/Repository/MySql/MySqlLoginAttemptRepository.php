<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\LoginAttemptRepositoryInterface;
use PDO;

final class MySqlLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function countFailures(string $email, string $ipAddress, int $sinceTimestamp): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM login_attempts
             WHERE email = :email
               AND ip_address = :ip_address
               AND successful = 0
               AND created_at >= FROM_UNIXTIME(:since_timestamp)'
        );
        $statement->execute([
            'email' => strtolower(trim($email)),
            'ip_address' => $ipAddress,
            'since_timestamp' => $sinceTimestamp,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) $row['total'] : 0;
    }

    public function record(string $email, string $ipAddress, bool $successful): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, successful)
             VALUES (:email, :ip_address, :successful)'
        );
        $statement->execute([
            'email' => strtolower(trim($email)),
            'ip_address' => $ipAddress,
            'successful' => $successful ? 1 : 0,
        ]);
    }

    public function clearFailures(string $email, string $ipAddress): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM login_attempts
             WHERE email = :email
               AND ip_address = :ip_address
               AND successful = 0'
        );
        $statement->execute([
            'email' => strtolower(trim($email)),
            'ip_address' => $ipAddress,
        ]);
    }
}
