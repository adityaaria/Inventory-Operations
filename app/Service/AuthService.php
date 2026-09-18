<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\UserRepositoryInterface;
use App\Security\AuthContext;
use App\Security\SessionManager;

final class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly SessionManager $session,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?LoginRateLimiter $rateLimiter = null,
    ) {
    }

    public function login(string $email, string $password, string $ipAddress = '', string $userAgent = ''): bool
    {
        $email = trim($email);
        if ($this->rateLimiter !== null && $this->rateLimiter->isBlocked($email, $ipAddress)) {
            $this->auditLogger?->record(null, 'auth.login_blocked', 'auth', null, 'blocked', $ipAddress, $userAgent, ['email' => $email]);

            return false;
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || !$user->isActive()) {
            $this->rateLimiter?->recordFailure($email, $ipAddress);
            $this->auditLogger?->record(null, 'auth.login_failed', 'auth', null, 'failure', $ipAddress, $userAgent, ['email' => $email]);

            return false;
        }

        if (!password_verify($password, $user->passwordHash())) {
            $this->rateLimiter?->recordFailure($email, $ipAddress);
            $this->auditLogger?->record($user->id(), 'auth.login_failed', 'auth', $user->id(), 'failure', $ipAddress, $userAgent, ['email' => $email]);

            return false;
        }

        $this->session->login(new AuthContext($user->id(), $user->email(), $user->role()));
        $this->rateLimiter?->recordSuccess($email, $ipAddress);
        $this->auditLogger?->record($user->id(), 'auth.login_success', 'auth', $user->id(), 'success', $ipAddress, $userAgent, ['email' => $email]);

        return true;
    }

    public function logout(string $ipAddress = '', string $userAgent = ''): void
    {
        $auth = $this->session->auth();
        $this->session->logout();
        $this->auditLogger?->record($auth?->userId(), 'auth.logout', 'auth', $auth?->userId(), 'success', $ipAddress, $userAgent);
    }
}
