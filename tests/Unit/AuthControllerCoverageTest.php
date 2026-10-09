<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\AuthController;
use App\Entity\User;
use App\Http\Request;
use App\Repository\InMemory\InMemoryAuditLogRepository;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\AuthContext;
use App\Security\SessionManager;
use App\Service\AuditLogger;
use App\Service\AuthService;
use PHPUnit\Framework\TestCase;

final class AuthControllerCoverageTest extends TestCase
{
    private SessionManager $session;
    private InMemoryAuditLogRepository $audit;
    private AuthController $controller;

    protected function setUp(): void
    {
        $hash = password_hash('secret-pass', PASSWORD_BCRYPT);
        self::assertIsString($hash);
        $this->session = new SessionManager();
        $this->audit = new InMemoryAuditLogRepository();
        $this->controller = new AuthController(new AuthService(
            new InMemoryUserRepository([new User(7, 'Admin', 'admin@example.test', $hash, User::ROLE_ADMIN, true)]),
            $this->session,
            new AuditLogger($this->audit),
        ));
    }

    public function testShowLoginRendersEmptyFormWithoutErrorAlert(): void
    {
        $response = $this->controller->showLogin();
        $body = $response->body();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('<form method="post" action="/login"', $body);
        self::assertStringContainsString('name="email" type="email" required value=""', $body);
        self::assertStringNotContainsString('alert-danger', $body);
    }

    public function testSuccessfulLoginRedirectsHomeAndPassesClientOriginToAudit(): void
    {
        $response = $this->controller->login(new Request('POST', '/login', [], [
            'email' => 'admin@example.test',
            'password' => 'secret-pass',
        ], [
            'REMOTE_ADDR' => '10.0.0.' . str_repeat('9', 60),
            'HTTP_USER_AGENT' => str_repeat('A', 300),
        ]));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/', $response->headers()['Location']);
        self::assertSame(7, $this->session->auth()?->userId());
        $entry = $this->audit->entries()[0];
        self::assertSame('auth.login_success', $entry['action']);
        self::assertSame(45, strlen($entry['ip_address']));
        self::assertSame(255, strlen($entry['user_agent']));
    }

    public function testFailedLoginRerendersFormWithEscapedEmailAndError(): void
    {
        $response = $this->controller->login(new Request('POST', '/login', [], [
            'email' => '"><script>x</script>@example.test',
            'password' => 'wrong',
        ], []));
        $body = $response->body();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Invalid email, password, or inactive account.', $body);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $body);
        self::assertStringNotContainsString('<script>x</script>', $body);
        self::assertNull($this->session->auth());
    }

    public function testNonStringCredentialsAndServerValuesAreTreatedAsEmpty(): void
    {
        $response = $this->controller->login(new Request('POST', '/login', [], [
            'email' => ['admin@example.test'],
            'password' => ['secret-pass'],
        ], ['REMOTE_ADDR' => ['1.1.1.1'], 'HTTP_USER_AGENT' => 42]));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('required value=""', $response->body());
        $entry = $this->audit->entries()[0];
        self::assertSame('auth.login_failed', $entry['action']);
        self::assertSame('', $entry['ip_address']);
        self::assertSame('', $entry['user_agent']);
    }

    public function testOverlongEmailIsTruncatedBeforeAuthentication(): void
    {
        $this->controller->login(new Request('POST', '/login', [], ['email' => str_repeat('e', 250), 'password' => 'x'], []));

        self::assertSame(190, strlen((string) $this->audit->entries()[0]['metadata']['email']));
    }

    public function testLogoutClearsSessionAndRedirectsToLogin(): void
    {
        $this->session->login(new AuthContext(7, 'admin@example.test', User::ROLE_ADMIN));

        $response = $this->controller->logout(new Request('POST', '/logout', [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit']));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/login', $response->headers()['Location']);
        self::assertNull($this->session->auth());
        $entry = $this->audit->entries()[0];
        self::assertSame('auth.logout', $entry['action']);
        self::assertSame(7, $entry['actor_id']);
        self::assertSame('127.0.0.1', $entry['ip_address']);
    }
}
