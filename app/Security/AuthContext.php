<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use InvalidArgumentException;

final class AuthContext
{
    public function __construct(
        private readonly int $userId,
        private readonly string $email,
        private readonly string $role,
    ) {
        if (!in_array($role, User::ROLES, true)) {
            throw new InvalidArgumentException("Unsupported role: {$role}");
        }
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function role(): string
    {
        return $this->role;
    }
}
