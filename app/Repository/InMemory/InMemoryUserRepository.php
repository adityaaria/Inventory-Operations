<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;
use RuntimeException;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, User> */
    private array $users = [];

    private int $nextId = 1;

    /**
     * @param list<User> $users
     */
    public function __construct(array $users = [])
    {
        foreach ($users as $user) {
            $this->users[$user->id()] = $user;
            $this->nextId = max($this->nextId, $user->id() + 1);
        }
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if (strcasecmp($user->email(), $email) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->users);
    }

    public function create(string $name, string $email, string $passwordHash, string $role, bool $isActive): User
    {
        $user = new User($this->nextId++, $name, $email, $passwordHash, $role, $isActive);
        $this->users[$user->id()] = $user;

        return $user;
    }

    public function update(int $id, string $name, string $email, string $role): User
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new RuntimeException("User not found: {$id}");
        }

        $updated = new User($id, $name, $email, $existing->passwordHash(), $role, $existing->isActive());
        $this->users[$id] = $updated;

        return $updated;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new RuntimeException("User not found: {$id}");
        }

        $this->users[$id] = new User(
            $existing->id(),
            $existing->name(),
            $existing->email(),
            $existing->passwordHash(),
            $existing->role(),
            $isActive,
        );
    }
}
