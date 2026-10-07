<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/** Native PHP adapter. Services never access cookies, session storage or superglobals. */
final class NativeSessionManager extends SessionManager
{
    private readonly SessionPolicy $policy;
    private readonly int $now;
    private readonly bool $secure;
    private bool $checked = false;

    /** @param \Closure(): int|null $clock */
    public function __construct(?SessionPolicy $policy = null, ?bool $secure = null, ?\Closure $clock = null, string $savePath = '')
    {
        $this->policy = $policy ?? new SessionPolicy();
        $this->now = $clock === null ? time() : $clock();
        // Never trust an arbitrary X-Forwarded-Proto header. TLS termination uses explicit config.
        $this->secure = $secure ?? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.gc_maxlifetime', (string) $this->policy->absoluteSeconds);
            if ($savePath !== '') {
                if (!is_dir($savePath) || !is_writable($savePath)) {
                    throw new \RuntimeException('Session storage is unavailable.');
                }
                session_save_path($savePath);
            }
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', ...self::cookieOptions($this->secure)]);
            if (!session_start()) throw new \RuntimeException('Unable to start session.');
        }
    }

    /** @return array{httponly: bool, samesite: string, secure: bool} */
    public static function cookieOptions(bool $secure): array
    {
        return ['httponly' => true, 'samesite' => 'Lax', 'secure' => $secure];
    }

    public function auth(): ?AuthContext
    {
        $auth = $_SESSION['auth'] ?? null;
        if ($auth === null) return null;
        $metadata = $_SESSION['lifecycle'] ?? null;
        if (!is_array($auth) || !is_array($metadata)
            || !is_int($auth['user_id'] ?? null) || $auth['user_id'] < 1
            || !is_string($auth['email'] ?? null) || $auth['email'] === ''
            || !is_string($auth['role'] ?? null) || !in_array($auth['role'], User::ROLES, true)
            || $this->policy->isExpired($metadata, $this->now)) {
            $this->logout();
            return null;
        }
        if (!$this->checked) {
            if ($this->policy->shouldRotate($metadata, $this->now)) {
                $this->rotate();
                $_SESSION['lifecycle']['rotated_at'] = $this->now;
            }
            $_SESSION['lifecycle']['last_seen_at'] = $this->now;
            $this->checked = true;
        }
        return new AuthContext($auth['user_id'], $auth['email'], $auth['role']);
    }

    public function login(AuthContext $authContext, ?string $passwordHash = null): void
    {
        $_SESSION = [];
        $this->rotate();
        $this->storeAuth($authContext);
        if ($passwordHash !== null) $_SESSION['credential_version'] = hash('sha256', $passwordHash);
        $_SESSION['lifecycle'] = ['created_at' => $this->now, 'last_seen_at' => $this->now, 'rotated_at' => $this->now];
        $this->checked = true;
        $this->csrfToken();
        $this->expireLegacyRoleCookie();
    }

    public function logout(): void
    {
        $_SESSION = [];
        $this->rotate();
        $this->checked = true;
        $this->csrfToken();
        $this->expireLegacyRoleCookie();
    }

    public function credentialsMatch(string $passwordHash): bool
    {
        $version = $_SESSION['credential_version'] ?? null;
        return is_string($version) && hash_equals($version, hash('sha256', $passwordHash));
    }

    public function refreshAuth(AuthContext $authContext): void
    {
        if (($_SESSION['auth']['role'] ?? null) !== $authContext->role()) {
            $this->rotate();
            unset($_SESSION['csrf_token']);
            $this->csrfToken();
            $_SESSION['lifecycle']['rotated_at'] = $this->now;
        }
        $this->storeAuth($authContext);
    }

    private function storeAuth(AuthContext $authContext): void
    {
        $_SESSION['auth'] = ['user_id' => $authContext->userId(), 'email' => $authContext->email(), 'role' => $authContext->role()];
    }

    private function rotate(): void
    {
        // Leave an empty old session as a tombstone. Waiting requests cannot restore old auth.
        // Do not copy credentials to the previous ID or accept it as a grace-period alias.
        $data = $_SESSION;
        $_SESSION = [];
        if (!session_regenerate_id(false)) throw new \RuntimeException('Unable to rotate session.');
        $_SESSION = $data;
    }

    private function expireLegacyRoleCookie(): void
    {
        if (isset($_COOKIE['user_role'])) {
            setcookie('user_role', '', ['expires' => time() - 3600, 'path' => '/', ...self::cookieOptions($this->secure)]);
        }
    }

    public function csrfToken(): string
    {
        $token = $_SESSION['csrf_token'] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $token;
        }
        return $token;
    }

    public function isValidCsrfToken(string $token): bool
    {
        return Csrf::isValid($this->csrfToken(), $token);
    }

    /** Commit and release the lock before streaming the HTTP response. */
    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
            throw new \RuntimeException('Unable to persist session.');
        }
    }
}
