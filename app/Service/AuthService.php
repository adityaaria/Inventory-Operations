<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\UserRepositoryInterface;
use App\Security\AuthContext;
use App\Security\SessionManager;
use App\Support\RequestOrigin;

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
            $this->auditLogger?->record(null, 'auth.login_blocked', 'auth', null, 'blocked', new RequestOrigin($ipAddress, $userAgent), ['email' => $email]);

            return false;
        }

        $user = $this->users->findByEmail($email);
        // Unknown and inactive accounts are audited without an actor; a wrong password on an active account names it.
        $activeUser = $user !== null && $user->isActive() ? $user : null;
        if ($activeUser === null || !password_verify($password, $activeUser->passwordHash())) {
            $this->rateLimiter?->recordFailure($email, $ipAddress);
            $this->auditLogger?->record($activeUser?->id(), 'auth.login_failed', 'auth', $activeUser?->id(), 'failure', new RequestOrigin($ipAddress, $userAgent), ['email' => $email]);

            return false;
        }

        $this->session->login(new AuthContext($activeUser->id(), $activeUser->email(), $activeUser->role()), $activeUser->passwordHash());
        $this->rateLimiter?->recordSuccess($email, $ipAddress);
        $this->auditLogger?->record($activeUser->id(), 'auth.login_success', 'auth', $activeUser->id(), 'success', new RequestOrigin($ipAddress, $userAgent), ['email' => $email]);

        return true;
    }

    public function logout(string $ipAddress = '', string $userAgent = ''): void
    {
        $auth = $this->session->auth();
        $this->session->logout();
        $this->auditLogger?->record($auth?->userId(), 'auth.logout', 'auth', $auth?->userId(), 'success', new RequestOrigin($ipAddress, $userAgent));
    }
}
