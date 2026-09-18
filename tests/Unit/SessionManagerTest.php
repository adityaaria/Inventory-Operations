<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\AuthContext;
use App\Security\SessionManager;
use PHPUnit\Framework\TestCase;

final class SessionManagerTest extends TestCase
{
    public function testStoresAndClearsAuthContext(): void
    {
        $session = new SessionManager();
        self::assertNull($session->auth());

        $session->login(new AuthContext(1, 'admin@example.test', 'Admin'));
        self::assertSame(1, $session->auth()?->userId());
        self::assertSame('Admin', $session->auth()?->role());

        $session->logout();
        self::assertNull($session->auth());
    }
}
