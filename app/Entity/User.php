<?php

declare(strict_types=1);

namespace App\Entity;

use InvalidArgumentException;

final class User
{
    public const ROLE_ADMIN = 'Admin';
    public const ROLE_SALES = 'Sales';
    public const ROLE_WAREHOUSE_STAFF = 'WarehouseStaff';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_SALES,
        self::ROLE_WAREHOUSE_STAFF,
    ];

    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $role,
        private readonly bool $isActive,
    ) {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("Unsupported role: {$role}");
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
