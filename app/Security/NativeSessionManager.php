<?php

declare(strict_types=1);

namespace App\Security;

final class NativeSessionManager extends SessionManager
{
    private const AUTH_KEY = 'auth';
    private const CSRF_KEY = 'csrf_token';

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(self::cookieOptions(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
            session_start();
        }
    }

    /**
     * @return array{httponly: bool, samesite: string, secure: bool}
     */
    public static function cookieOptions(bool $secure): array
    {
        return ['httponly' => true, 'samesite' => 'Lax', 'secure' => $secure];
    }

    public function auth(): ?AuthContext
    {
        $auth = $_SESSION[self::AUTH_KEY] ?? null;
        if (!is_array($auth)) {
            return null;
        }

        $userId = $auth['user_id'] ?? null;
        $email = $auth['email'] ?? null;
        $role = $auth['role'] ?? null;

        if (!is_int($userId) || !is_string($email) || !is_string($role)) {
            return null;
        }

        return new AuthContext($userId, $email, $role);
    }

    public function login(AuthContext $authContext): void
    {
        session_regenerate_id(true);
        $_SESSION[self::AUTH_KEY] = [
            'user_id' => $authContext->userId(),
            'email' => $authContext->email(),
            'role' => $authContext->role(),
        ];
        // Non-HttpOnly companion cookie so client-side navigation can hide role-restricted
        // menu items (e.g. Users). This is a UI convenience only: every route still enforces
        // its own authorization server-side regardless of what this cookie says.
        $this->setRoleCookie($authContext->role());
    }

    public function logout(): void
    {
        unset($_SESSION[self::AUTH_KEY]);
        session_regenerate_id(true);
        $this->setRoleCookie(null);
    }

    private function setRoleCookie(?string $role): void
    {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('user_role', $role ?? '', [
            'expires' => $role === null ? time() - 3600 : 0,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
    }

    public function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $_SESSION[self::CSRF_KEY] = $token;
        }

        return $token;
    }

    public function isValidCsrfToken(string $token): bool
    {
        return Csrf::isValid($this->csrfToken(), $token);
    }
}
