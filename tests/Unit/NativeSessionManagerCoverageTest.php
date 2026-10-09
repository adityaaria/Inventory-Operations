<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Exception\InfrastructureException;
use App\Security\AuthContext;
use App\Security\NativeSessionManager;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class NativeSessionManagerCoverageTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testMissingSavePathFailsClosedBeforeStartingSession(): void
    {
        try {
            new NativeSessionManager(null, false, null, sys_get_temp_dir() . '/ioms-missing-' . bin2hex(random_bytes(4)));
            self::fail('Expected unavailable session storage to be rejected.');
        } catch (InfrastructureException $exception) {
            self::assertSame('Session storage is unavailable.', $exception->getMessage());
        }

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testWritableSavePathPersistsSessionFilesThere(): void
    {
        $dir = sys_get_temp_dir() . '/ioms-sess-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);

        try {
            $session = new NativeSessionManager(null, false, null, $dir);
            self::assertSame($dir, session_save_path());

            $session->login(new AuthContext(3, 'sales@example.test', User::ROLE_SALES));
            $session->close();

            self::assertSame(PHP_SESSION_NONE, session_status());
            self::assertFileExists($dir . '/sess_' . session_id());
            self::assertStringContainsString('sales@example.test', (string) file_get_contents($dir . '/sess_' . session_id()));
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    #[RunInSeparateProcess]
    public function testLoginAndLogoutExpireLegacyRoleCookieWithoutBreakingSession(): void
    {
        $_COOKIE['user_role'] = 'admin';
        $session = new NativeSessionManager(null, true);
        error_clear_last();

        $session->login(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN));
        self::assertSame(User::ROLE_ADMIN, $session->auth()?->role());

        $session->logout();
        self::assertNull($session->auth());
        self::assertNull(error_get_last());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $session->csrfToken());
    }

    #[RunInSeparateProcess]
    public function testCloseIsNoOpWhenSessionAlreadyClosed(): void
    {
        $session = new NativeSessionManager(null, false);
        $session->close();
        $session->close();

        self::assertSame(PHP_SESSION_NONE, session_status());
    }
}
