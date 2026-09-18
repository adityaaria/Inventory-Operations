<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\HttpException;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\AuthContext;
use App\Security\Authorization;
use InvalidArgumentException;

final class UserService
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /**
     * @return list<User>
     */
    public function listUsers(AuthContext $actor): array
    {
        $this->assertCanManageUsers($actor);

        return $this->users->all();
    }

    public function createUser(AuthContext $actor, string $name, string $email, string $password, string $role): User
    {
        $this->assertCanManageUsers($actor);
        $this->assertValidUserInput($name, $email, $role);

        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        if (!is_string($hash)) {
            throw new InvalidArgumentException('Unable to hash password.');
        }

        return $this->users->create(trim($name), strtolower(trim($email)), $hash, $role, true);
    }

    public function updateUser(AuthContext $actor, int $id, string $name, string $email, string $role): User
    {
        $this->assertCanManageUsers($actor);
        $this->assertValidUserInput($name, $email, $role);

        return $this->users->update($id, trim($name), strtolower(trim($email)), $role);
    }

    public function setActive(AuthContext $actor, int $id, bool $isActive): void
    {
        $this->assertCanManageUsers($actor);
        $this->users->setActive($id, $isActive);
    }

    private function assertCanManageUsers(AuthContext $actor): void
    {
        if (!Authorization::canManageUsers($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertValidUserInput(string $name, string $email, string $role): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name is required.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Valid email is required.');
        }

        if (!in_array($role, User::ROLES, true)) {
            throw new InvalidArgumentException('Valid role is required.');
        }
    }
}
