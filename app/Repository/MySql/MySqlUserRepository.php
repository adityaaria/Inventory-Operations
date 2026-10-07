<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\Pagination;
use PDO;
use RuntimeException;

final class MySqlUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, role, is_active FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function paginate(Pagination $pagination): PaginatedResult
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM users');
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, role, is_active FROM users
             ORDER BY id ASC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('limit', Pagination::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue('offset', $pagination->offsetForTotal($total), PDO::PARAM_INT);
        $statement->execute();

        return new PaginatedResult(
            array_map(fn (array $row): User => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC)),
            $total,
            $pagination->pageForTotal($total),
            Pagination::PER_PAGE,
        );
    }

    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, email, password_hash, role, is_active FROM users ORDER BY id ASC'
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to query users.');
        }

        $users = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $users[] = $this->hydrate($row);
            }
        }

        return $users;
    }

    public function create(string $name, string $email, string $passwordHash, string $role, bool $isActive): User
    {
        return PersistenceErrors::write(function () use ($name, $email, $passwordHash, $role, $isActive): User {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, is_active)
                 VALUES (:name, :email, :password_hash, :role, :is_active)'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => $role,
                'is_active' => $isActive ? 1 : 0,
            ]);

            return $this->findRequiredById((int) $this->pdo->lastInsertId());

        });
    }

    public function update(int $id, string $name, string $email, string $role): User
    {
        return PersistenceErrors::write(function () use ($id, $name, $email, $role): User {
            $statement = $this->pdo->prepare(
                'UPDATE users SET name = :name, email = :email, role = :role WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ]);

            return $this->findRequiredById($id);

        });
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET is_active = :is_active WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['password_hash'],
            (string) $row['role'],
            (bool) $row['is_active'],
        );
    }

    private function findRequiredById(int $id): User
    {
        $user = $this->findById($id);
        if ($user === null) {
            throw new RuntimeException("User not found after write: {$id}");
        }

        return $user;
    }
}
