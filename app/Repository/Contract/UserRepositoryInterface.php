<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function findById(int $id): ?User;

    /**
     * @return list<User>
     */
    public function all(): array;

    public function create(string $name, string $email, string $passwordHash, string $role, bool $isActive): User;

    public function update(int $id, string $name, string $email, string $role): User;

    public function setActive(int $id, bool $isActive): void;
}
