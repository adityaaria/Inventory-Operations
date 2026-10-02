<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Exception\HttpException;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\AuthContext;
use App\Service\UserService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    public function testAdminCanCreateUpdateAndDeactivateUsers(): void
    {
        $repository = new InMemoryUserRepository();
        $service = new UserService($repository);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        $created = $service->createUser($admin, 'Sales User', 'sales@example.test', 'password', User::ROLE_SALES);
        self::assertSame('sales@example.test', $created->email());
        self::assertTrue(password_verify('password', $created->passwordHash()));

        $updated = $service->updateUser($admin, $created->id(), 'Warehouse User', 'warehouse@example.test', User::ROLE_WAREHOUSE_STAFF);
        self::assertSame(User::ROLE_WAREHOUSE_STAFF, $updated->role());

        $service->setActive($admin, $created->id(), false);
        self::assertFalse($repository->findById($created->id())?->isActive());
    }

    public function testNonAdminCannotManageUsers(): void
    {
        $service = new UserService(new InMemoryUserRepository());

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Forbidden');

        $service->createUser(new AuthContext(2, 'sales@example.test', User::ROLE_SALES), 'User', 'user@example.test', 'password', User::ROLE_SALES);
    }

    public function testAdminCanListUsers(): void
    {
        $repository = new InMemoryUserRepository();
        $service = new UserService($repository);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $service->createUser($admin, 'Sales User', 'sales@example.test', 'password', User::ROLE_SALES);

        $users = $service->listUsers($admin);

        self::assertNotEmpty($users);
    }

    public function testNonAdminCannotListUsers(): void
    {
        $service = new UserService(new InMemoryUserRepository());

        $this->expectException(HttpException::class);

        $service->listUsers(new AuthContext(2, 'sales@example.test', User::ROLE_SALES));
    }

    public function testCreateUserRejectsShortPassword(): void
    {
        $service = new UserService(new InMemoryUserRepository());
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least 8 characters.');

        $service->createUser($admin, 'Sales User', 'sales@example.test', 'short', User::ROLE_SALES);
    }

    public function testCreateUserRejectsBlankName(): void
    {
        $service = new UserService(new InMemoryUserRepository());
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name is required.');

        $service->createUser($admin, '   ', 'sales@example.test', 'password', User::ROLE_SALES);
    }

    public function testCreateUserRejectsInvalidEmail(): void
    {
        $service = new UserService(new InMemoryUserRepository());
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Valid email is required.');

        $service->createUser($admin, 'Sales User', 'not-an-email', 'password', User::ROLE_SALES);
    }

    public function testCreateUserRejectsInvalidRole(): void
    {
        $service = new UserService(new InMemoryUserRepository());
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Valid role is required.');

        $service->createUser($admin, 'Sales User', 'sales@example.test', 'password', 'SuperAdmin');
    }
}
