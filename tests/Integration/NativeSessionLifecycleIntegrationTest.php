<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\User;
use App\Security\{AuthContext, NativeSessionManager, SessionPolicy};
use PHPUnit\Framework\TestCase;

final class NativeSessionLifecycleIntegrationTest extends TestCase
{
    /** @runInSeparateProcess */
    public function testStrictCookieOnlySessionsRejectAttackerChosenUnknownId(): void
    {
        session_id('attackerchosenidentifier123456789');
        $session = new NativeSessionManager(null, true);
        self::assertNotSame('attackerchosenidentifier123456789', session_id());
        self::assertSame('1', ini_get('session.use_strict_mode'));
        self::assertSame('1', ini_get('session.use_only_cookies'));
        self::assertSame('0', ini_get('session.use_trans_sid'));
        self::assertSame(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>true,'httponly'=>true,'samesite'=>'Lax'], session_get_cookie_params());
        $session->close();
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    /** @runInSeparateProcess */
    public function testLoginLogoutRotateIdCsrfAndRemoveAllPreviousData(): void
    {
        $session = new NativeSessionManager();
        $anonymousId = session_id();
        $anonymousToken = $session->csrfToken();
        $_SESSION['unexpected'] = 'old privileged data';
        $session->login(new AuthContext(1, 'admin@test', User::ROLE_ADMIN), 'hash');
        $loginId = session_id();
        self::assertNotSame($anonymousId, $loginId);
        self::assertNotSame($anonymousToken, $session->csrfToken());
        self::assertArrayNotHasKey('unexpected', $_SESSION);
        self::assertTrue($session->credentialsMatch('hash'));
        self::assertFalse($session->credentialsMatch('new hash'));
        $loginToken = $session->csrfToken();
        $_SESSION['unexpected'] = 'privileged data';
        $session->logout();
        self::assertNotSame($loginId, session_id());
        self::assertNotSame($loginToken, $session->csrfToken());
        self::assertFalse($session->isValidCsrfToken($loginToken));
        self::assertNull($session->auth());
        self::assertSame(['csrf_token'], array_keys($_SESSION));
        $session->close();
        // Replay the previous authenticated ID: its file is an empty tombstone.
        session_id($loginId);
        $replayed = new NativeSessionManager();
        self::assertNull($replayed->auth());
        $replayed->close();
    }

    /** @runInSeparateProcess */
    public function testPeriodicRotationKeepsCsrfAndAbsoluteOriginButInvalidatesPreviousId(): void
    {
        $policy = new SessionPolicy(30, 100, 15);
        $session = new NativeSessionManager($policy, false, static fn (): int => 100);
        $session->login(new AuthContext(1, 'admin@test', User::ROLE_ADMIN), 'hash');
        $oldId = session_id();
        $token = $session->csrfToken();
        $session->close();
        $next = new NativeSessionManager($policy, false, static fn (): int => 115);
        self::assertSame(1, $next->auth()?->userId());
        self::assertNotSame($oldId, session_id());
        self::assertSame($token, $next->csrfToken());
        self::assertSame(100, $_SESSION['lifecycle']['created_at']);
        self::assertSame(115, $_SESSION['lifecycle']['last_seen_at']);
        $next->close();
        session_id($oldId);
        $replayed = new NativeSessionManager($policy, false, static fn (): int => 116);
        self::assertNull($replayed->auth());
        $replayed->close();
    }

    /** @runInSeparateProcess */
    public function testIdleExpiryClearsAuthAndCsrfBeforeRequestCanMutate(): void
    {
        $policy = new SessionPolicy(30, 100, 15);
        $session = new NativeSessionManager($policy, false, static fn (): int => 100);
        $session->login(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));
        $token = $session->csrfToken();
        $session->close();
        $expired = new NativeSessionManager($policy, false, static fn (): int => 130);
        self::assertNull($expired->auth());
        self::assertFalse($expired->isValidCsrfToken($token));
        $expired->close();
    }

    /** @runInSeparateProcess */
    public function testActiveTrafficCannotExtendAbsoluteLifetime(): void
    {
        $policy = new SessionPolicy(30, 100, 15);
        $session = new NativeSessionManager($policy, false, static fn (): int => 100);
        $session->login(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));
        $session->close();
        foreach ([120, 140, 160, 180, 199] as $now) {
            $session = new NativeSessionManager($policy, false, static fn (): int => $now);
            self::assertNotNull($session->auth());
            $session->close();
        }
        $expired = new NativeSessionManager($policy, false, static fn (): int => 200);
        self::assertNull($expired->auth());
        $expired->close();
    }

    /** @runInSeparateProcess */
    public function testInvalidRoleFailsClosedInsteadOfThrowingServerError(): void
    {
        $session = new NativeSessionManager();
        $session->login(new AuthContext(1, 'admin@test', User::ROLE_ADMIN));
        $_SESSION['auth']['role'] = 'SuperAdmin';
        self::assertNull($session->auth());
        $session->close();
    }

    /** @runInSeparateProcess */
    public function testPrivilegeChangeRotatesIdAndCsrfWithoutResettingLoginTime(): void
    {
        $session = new NativeSessionManager();
        $session->login(new AuthContext(1, 'admin@test', User::ROLE_ADMIN), 'hash');
        $id = session_id(); $token = $session->csrfToken(); $created = $_SESSION['lifecycle']['created_at'];
        $session->refreshAuth(new AuthContext(1, 'admin@test', User::ROLE_SALES));
        self::assertNotSame($id, session_id());
        self::assertNotSame($token, $session->csrfToken());
        self::assertSame($created, $_SESSION['lifecycle']['created_at']);
        self::assertSame(User::ROLE_SALES, $session->auth()?->role());
        $session->close();
    }

}
