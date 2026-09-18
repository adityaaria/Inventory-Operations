<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Repository\InMemory\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class UserRepositoryTest extends TestCase
{
    public function testFindsUsersByEmailAndId(): void
    {
        $repository = new InMemoryUserRepository([
            new User(1, 'Admin User', 'admin@example.test', 'hash', User::ROLE_ADMIN, true),
        ]);

        self::assertSame('Admin User', $repository->findByEmail('admin@example.test')?->name());
        self::assertSame('admin@example.test', $repository->findById(1)?->email());
        self::assertNull($repository->findByEmail('missing@example.test'));
    }

    public function testCreatesUpdatesAndDeactivatesUsers(): void
    {
        $repository = new InMemoryUserRepository();

        $created = $repository->create('Sales User', 'sales@example.test', 'hash', User::ROLE_SALES, true);
        self::assertSame(1, $created->id());

        $updated = $repository->update($created->id(), 'Sales Renamed', 'sales2@example.test', User::ROLE_WAREHOUSE_STAFF);
        self::assertSame('Sales Renamed', $updated->name());
        self::assertSame(User::ROLE_WAREHOUSE_STAFF, $updated->role());

        $repository->setActive($created->id(), false);
        self::assertFalse($repository->findById($created->id())?->isActive());
    }
}
