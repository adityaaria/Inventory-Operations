<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlLoginAttemptRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class SecurityAuditRepositoryIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {

        $this->pdo = \Tests\Support\TestDatabase::connect();
    }

    public function testAuditLogRepositoryPersistsJsonMetadata(): void
    {
        $repository = new MySqlAuditLogRepository($this->pdo);

        $repository->append(null, 'auth.login_failed', 'auth', null, 'failure', new \App\Support\RequestOrigin('127.0.0.1', 'PHPUnit'), ['email' => 'missing@example.test']);

        $statement = $this->pdo->prepare('SELECT action, status, metadata_json FROM audit_logs WHERE action = :action ORDER BY id DESC LIMIT 1');
        $statement->execute(['action' => 'auth.login_failed']);
        $row = $statement->fetch();

        self::assertIsArray($row);
        self::assertSame('auth.login_failed', $row['action']);
        self::assertSame('failure', $row['status']);
        self::assertSame(['email' => 'missing@example.test'], json_decode((string) $row['metadata_json'], true));
    }

    public function testLoginAttemptRepositoryCountsAndClearsRecentFailures(): void
    {
        $repository = new MySqlLoginAttemptRepository($this->pdo);
        $email = 'attempt-' . bin2hex(random_bytes(4)) . '@example.test';

        $repository->record($email, '127.0.0.1', false);
        $repository->record($email, '127.0.0.1', false);
        $repository->record($email, '127.0.0.1', true);

        self::assertSame(2, $repository->countFailures($email, '127.0.0.1', time() - 900));

        $repository->clearFailures($email, '127.0.0.1');

        self::assertSame(0, $repository->countFailures($email, '127.0.0.1', time() - 900));
    }
}
