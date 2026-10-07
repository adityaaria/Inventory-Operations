<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\HttpException;
use App\Repository\Contract\UserRepositoryInterface;

final class AuthGuard
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly ?UserRepositoryInterface $users = null,
    )
    {
    }

    public function requireAuth(): AuthContext
    {
        $auth = $this->session->auth();
        if ($auth === null) {
            throw new HttpException(401, 'Authentication required.');
        }

        if ($this->users !== null) {
            $user = $this->users->findById($auth->userId());
            if ($user === null || !$user->isActive() || !$this->session->credentialsMatch($user->passwordHash())) {
                $this->session->logout();
                throw new HttpException(401, 'Authentication required.');
            }
            $auth = new AuthContext($user->id(), $user->email(), $user->role());
            $this->session->refreshAuth($auth);
        }
        return $auth;
    }

    public function requireUserManagement(): AuthContext
    {
        $auth = $this->requireAuth();
        if (!Authorization::canManageUsers($auth)) {
            throw new HttpException(403, 'Forbidden');
        }

        return $auth;
    }
}
