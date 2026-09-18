<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserEntityTest extends TestCase
{
    public function testUserExposesIdentityRoleAndActiveState(): void
    {
        $user = new User(1, 'Admin User', 'admin@example.test', 'hash', 'Admin', true);

        self::assertSame(1, $user->id());
        self::assertSame('Admin User', $user->name());
        self::assertSame('admin@example.test', $user->email());
        self::assertSame('hash', $user->passwordHash());
        self::assertSame('Admin', $user->role());
        self::assertTrue($user->isActive());
    }

    public function testUserRejectsUnsupportedRole(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new User(1, 'Bad Role', 'bad@example.test', 'hash', 'Manager', true);
    }
}
