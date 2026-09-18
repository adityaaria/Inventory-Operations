<?php

declare(strict_types=1);

namespace App\Security;

class SessionManager
{
    private ?AuthContext $authContext = null;
    private string $csrfToken = '';

    public function auth(): ?AuthContext
    {
        return $this->authContext;
    }

    public function login(AuthContext $authContext): void
    {
        $this->authContext = $authContext;
    }

    public function logout(): void
    {
        $this->authContext = null;
    }

    public function csrfToken(): string
    {
        if ($this->csrfToken === '') {
            $this->csrfToken = bin2hex(random_bytes(16));
        }

        return $this->csrfToken;
    }

    public function isValidCsrfToken(string $token): bool
    {
        return Csrf::isValid($this->csrfToken(), $token);
    }
}
