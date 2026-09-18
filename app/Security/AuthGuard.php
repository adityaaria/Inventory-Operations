<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\HttpException;

final class AuthGuard
{
    public function __construct(private readonly SessionManager $session)
    {
    }

    public function requireAuth(): AuthContext
    {
        $auth = $this->session->auth();
        if ($auth === null) {
            throw new HttpException(401, 'Authentication required.');
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
