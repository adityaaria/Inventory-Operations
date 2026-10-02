<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\AuthContext;
use App\Security\NativeSessionManager;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class NativeSessionManagerTest extends TestCase
{
    public function testCookieOptionsReflectSecureFlag(): void
    {
        self::assertSame(
            ['httponly' => true, 'samesite' => 'Lax', 'secure' => true],
            NativeSessionManager::cookieOptions(true),
        );
        self::assertSame(
            ['httponly' => true, 'samesite' => 'Lax', 'secure' => false],
            NativeSessionManager::cookieOptions(false),
        );
    }

    /** @runInSeparateProcess */
    public function testStartsSessionAndHasNoAuthByDefault(): void
    {
        $session = new NativeSessionManager();

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertNull($session->auth());
    }

    /** @runInSeparateProcess */
    public function testLoginStoresAuthContextAndAuthReconstructsIt(): void
    {
        $session = new NativeSessionManager();

        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $restored = $session->auth();

        self::assertSame(1, $restored?->userId());
        self::assertSame('admin@example.test', $restored?->email());
        self::assertSame(User::ROLE_ADMIN, $restored?->role());
    }

    /** @runInSeparateProcess */
    public function testLogoutClearsAuth(): void
    {
        $session = new NativeSessionManager();
        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));

        $session->logout();

        self::assertNull($session->auth());
    }

    /** @runInSeparateProcess */
    public function testAuthReturnsNullForMalformedSessionData(): void
    {
        $session = new NativeSessionManager();

        $_SESSION['auth'] = 'not-an-array';
        self::assertNull($session->auth());

        $_SESSION['auth'] = ['user_id' => 'not-an-int', 'email' => 'a@example.test', 'role' => User::ROLE_ADMIN];
        self::assertNull($session->auth());
    }

    /** @runInSeparateProcess */
    public function testCsrfTokenIsGeneratedOnceAndReusedFromSession(): void
    {
        $session = new NativeSessionManager();

        $token = $session->csrfToken();

        self::assertNotSame('', $token);
        self::assertSame($token, $session->csrfToken());
        self::assertSame($token, $_SESSION['csrf_token']);
    }

    /** @runInSeparateProcess */
    public function testIsValidCsrfTokenValidatesAgainstStoredToken(): void
    {
        $session = new NativeSessionManager();
        $token = $session->csrfToken();

        self::assertTrue($session->isValidCsrfToken($token));
        self::assertFalse($session->isValidCsrfToken('wrong-token'));
    }
}
