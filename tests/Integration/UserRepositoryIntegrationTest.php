<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\User;
use App\Repository\MySql\MySqlUserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserRepositoryIntegrationTest extends TestCase
{
    private PDO $pdo;

    private MySqlUserRepository $repository;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_DATABASE') ?: 'inventory_order_management';
        $username = getenv('DB_USERNAME') ?: 'inventory_app';
        $password = getenv('DB_PASSWORD') ?: 'change_me_for_local_only';

        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        $this->repository = new MySqlUserRepository($this->pdo);
    }

    public function testFindsSeededAdminUserByEmail(): void
    {
        $user = $this->repository->findByEmail('admin@example.test');

        self::assertNotNull($user);
        self::assertSame(User::ROLE_ADMIN, $user->role());
        self::assertTrue($user->isActive());
    }

    public function testCreatesAndUpdatesUserInMySql(): void
    {
        $email = 'integration-' . bin2hex(random_bytes(4)) . '@example.test';
        $hash = password_hash('password', PASSWORD_BCRYPT);
        self::assertIsString($hash);

        try {
            $created = $this->repository->create('Integration User', $email, $hash, User::ROLE_SALES, true);
            self::assertSame($email, $created->email());

            $updated = $this->repository->update($created->id(), 'Integration User Updated', $email, User::ROLE_WAREHOUSE_STAFF);
            self::assertSame('Integration User Updated', $updated->name());
            self::assertSame(User::ROLE_WAREHOUSE_STAFF, $updated->role());

            $this->repository->setActive($created->id(), false);
            self::assertFalse($this->repository->findById($created->id())?->isActive());
        } finally {
            $statement = $this->pdo->prepare('DELETE FROM users WHERE email = :email');
            $statement->execute(['email' => $email]);
        }
    }
}
