<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Exception\HttpException;
use App\Http\Response;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\{AuthContext, AuthGuard, SessionManager};
use PHPUnit\Framework\TestCase;

final class SessionLifecycleTest extends TestCase
{
    public function testChangedPasswordRevokesSessionThroughAuthoritativeGuard(): void
    {
        $session = new SessionManager();
        $session->login(new AuthContext(1, 'admin@test', User::ROLE_ADMIN), 'old password hash');
        $users = new InMemoryUserRepository([new User(1, 'Admin', 'admin@test', 'new password hash', User::ROLE_ADMIN, true)]);
        try {
            (new AuthGuard($session, $users))->requireAuth();
            self::fail('Changed credentials must revoke access.');
        } catch (HttpException $exception) { self::assertSame(401, $exception->statusCode()); }
        self::assertNull($session->auth());
    }

    public function testNoStoreHeadersPreserveCsvStreamingAndRedirectHeaders(): void
    {
        $response = new Response(static function (): void { echo 'csv'; }, 200, ['Content-Type'=>'text/csv']);
        $secured = $response->withHeaders(['Cache-Control'=>'no-store, private']);
        self::assertSame('csv', $secured->body());
        self::assertSame('text/csv', $secured->headers()['Content-Type']);
        self::assertSame('no-store, private', $secured->headers()['Cache-Control']);
        self::assertSame('/login', (new Response('', 302, ['Location'=>'/login']))->withHeaders(['Cache-Control'=>'no-store'])->headers()['Location']);
    }
}
