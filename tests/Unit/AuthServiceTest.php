<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryLoginAttemptRepository;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\SessionManager;
use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\LoginRateLimiter;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    public function testLoginStoresAuthContextForActiveUserWithValidPassword(): void
    {
        $hash = password_hash('password', PASSWORD_BCRYPT);
        self::assertIsString($hash);

        $session = new SessionManager();
        $service = new AuthService(new InMemoryUserRepository([
            new User(1, 'Admin User', 'admin@example.test', $hash, User::ROLE_ADMIN, true),
        ]), $session);

        self::assertTrue($service->login('admin@example.test', 'password'));
        self::assertSame(1, $session->auth()?->userId());
    }

    public function testLoginRejectsInvalidPasswordAndInactiveUser(): void
    {
        $hash = password_hash('password', PASSWORD_BCRYPT);
        self::assertIsString($hash);

        $session = new SessionManager();
        $service = new AuthService(new InMemoryUserRepository([
            new User(1, 'Admin User', 'admin@example.test', $hash, User::ROLE_ADMIN, true),
            new User(2, 'Inactive User', 'inactive@example.test', $hash, User::ROLE_SALES, false),
        ]), $session);

        self::assertFalse($service->login('admin@example.test', 'wrong'));
        self::assertFalse($service->login('inactive@example.test', 'password'));
        self::assertNull($session->auth());
    }

    public function testLogoutClearsSession(): void
    {
        $session = new SessionManager();
        $service = new AuthService(new InMemoryUserRepository(), $session);

        $session->login(new \App\Security\AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        $service->logout();

        self::assertNull($session->auth());
    }

    public function testLoginFailureIsAuditedAndRateLimited(): void
    {
        $hash = password_hash('password', PASSWORD_BCRYPT);
        self::assertIsString($hash);
        $auditRepository = new InMemoryAuditLogRepository();
        $attemptRepository = new InMemoryLoginAttemptRepository();
        $service = new AuthService(
            new InMemoryUserRepository([
                new User(1, 'Admin User', 'admin@example.test', $hash, User::ROLE_ADMIN, true),
            ]),
            new SessionManager(),
            new AuditLogger($auditRepository),
            new LoginRateLimiter($attemptRepository, 2, 900),
        );

        self::assertFalse($service->login('admin@example.test', 'wrong', '127.0.0.1', 'PHPUnit'));
        self::assertFalse($service->login('admin@example.test', 'wrong', '127.0.0.1', 'PHPUnit'));
        self::assertFalse($service->login('admin@example.test', 'password', '127.0.0.1', 'PHPUnit'));

        self::assertSame(['auth.login_failed', 'auth.login_failed', 'auth.login_blocked'], array_column($auditRepository->entries(), 'action'));
    }

    public function testLoginSuccessResetsRateLimitAndWritesAuditEvent(): void
    {
        $hash = password_hash('password', PASSWORD_BCRYPT);
        self::assertIsString($hash);
        $auditRepository = new InMemoryAuditLogRepository();
        $attemptRepository = new InMemoryLoginAttemptRepository();
        $limiter = new LoginRateLimiter($attemptRepository, 5, 900);
        $service = new AuthService(
            new InMemoryUserRepository([
                new User(1, 'Admin User', 'admin@example.test', $hash, User::ROLE_ADMIN, true),
            ]),
            new SessionManager(),
            new AuditLogger($auditRepository),
            $limiter,
        );

        $limiter->recordFailure('admin@example.test', '127.0.0.1');
        self::assertTrue($service->login('admin@example.test', 'password', '127.0.0.1', 'PHPUnit'));

        self::assertFalse($limiter->isBlocked('admin@example.test', '127.0.0.1'));
        self::assertSame('auth.login_success', $auditRepository->entries()[0]['action']);
        self::assertSame(1, $auditRepository->entries()[0]['actor_id']);
    }
}
