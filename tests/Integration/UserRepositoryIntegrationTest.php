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

        $this->pdo = \Tests\Support\TestDatabase::connect();

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
