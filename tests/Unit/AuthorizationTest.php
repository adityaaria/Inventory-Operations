<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Security\AuthContext;
use App\Security\Authorization;
use PHPUnit\Framework\TestCase;

final class AuthorizationTest extends TestCase
{
    public function testAdminCanManageUsers(): void
    {
        self::assertTrue(Authorization::canManageUsers(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN)));
    }

    public function testNonAdminCannotManageUsers(): void
    {
        self::assertFalse(Authorization::canManageUsers(new AuthContext(2, 'sales@example.test', User::ROLE_SALES)));
        self::assertFalse(Authorization::canManageUsers(null));
    }
}
