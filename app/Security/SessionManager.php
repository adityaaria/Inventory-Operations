<?php

declare(strict_types=1);

namespace App\Security;

class SessionManager
{
    private ?AuthContext $authContext = null;
    private string $csrfToken = '';
    private ?string $credentialVersion = null;

    public function auth(): ?AuthContext
    {
        return $this->authContext;
    }

    public function login(AuthContext $authContext, ?string $passwordHash = null): void
    {
        $this->authContext = $authContext;
        $this->credentialVersion = $passwordHash === null ? null : hash('sha256', $passwordHash);
        $this->csrfToken = '';
    }

    public function logout(): void
    {
        $this->authContext = null;
        $this->credentialVersion = null;
        $this->csrfToken = '';
    }

    public function credentialsMatch(string $passwordHash): bool
    {
        return $this->credentialVersion === null || hash_equals($this->credentialVersion, hash('sha256', $passwordHash));
    }

    public function refreshAuth(AuthContext $authContext): void
    {
        if ($this->authContext?->role() !== $authContext->role()) $this->csrfToken = '';
        $this->authContext = $authContext;
    }

    public function csrfToken(): string
    {
        if ($this->csrfToken === '') {
            $this->csrfToken = bin2hex(random_bytes(32));
        }

        return $this->csrfToken;
    }

    public function isValidCsrfToken(string $token): bool
    {
        return Csrf::isValid($this->csrfToken(), $token);
    }
}
