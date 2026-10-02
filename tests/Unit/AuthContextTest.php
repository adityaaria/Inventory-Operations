<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Security\AuthContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuthContextTest extends TestCase
{
    public function testExposesUserIdEmailAndRole(): void
    {
        $auth = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        self::assertSame(1, $auth->userId());
        self::assertSame('admin@example.test', $auth->email());
        self::assertSame(User::ROLE_ADMIN, $auth->role());
    }

    public function testRejectsUnsupportedRole(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuthContext(1, 'admin@example.test', 'SuperAdmin');
    }
}
