<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\Category;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\Pagination;
use PDO;
use RuntimeException;

final class MySqlCategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function paginate(Pagination $pagination): PaginatedResult
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM categories');
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT id, name, description, is_active FROM categories
             ORDER BY name ASC, id ASC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('limit', Pagination::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue('offset', $pagination->offsetForTotal($total), PDO::PARAM_INT);
        $statement->execute();

        return new PaginatedResult(
            array_map(fn (array $row): Category => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC)),
            $total,
            $pagination->pageForTotal($total),
            Pagination::PER_PAGE,
        );
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, name, description, is_active FROM categories ORDER BY name ASC');
        if ($statement === false) {
            throw new RuntimeException('Unable to query categories.');
        }

        return array_map(fn (array $row): Category => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function active(): array
    {
        $statement = $this->pdo->query('SELECT id, name, description, is_active FROM categories WHERE is_active = 1 ORDER BY name ASC');
        if ($statement === false) {
            throw new RuntimeException('Unable to query active categories.');
        }

        return array_map(fn (array $row): Category => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?Category
    {
        $statement = $this->pdo->prepare('SELECT id, name, description, is_active FROM categories WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(string $name, string $description, bool $isActive): Category
    {
        return PersistenceErrors::write(function () use ($name, $description, $isActive): Category {
            $statement = $this->pdo->prepare('INSERT INTO categories (name, description, is_active) VALUES (:name, :description, :is_active)');
            $statement->execute(['name' => $name, 'description' => $description, 'is_active' => $isActive ? 1 : 0]);

            return $this->findRequired((int) $this->pdo->lastInsertId());

        });
    }

    public function update(int $id, string $name, string $description): Category
    {
        return PersistenceErrors::write(function () use ($id, $name, $description): Category {
            $statement = $this->pdo->prepare('UPDATE categories SET name = :name, description = :description WHERE id = :id');
            $statement->execute(['id' => $id, 'name' => $name, 'description' => $description]);

            return $this->findRequired($id);

        });
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE categories SET is_active = :is_active WHERE id = :id');
        $statement->execute(['id' => $id, 'is_active' => $isActive ? 1 : 0]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Category
    {
        return new Category((int) $row['id'], (string) $row['name'], (string) $row['description'], (bool) $row['is_active']);
    }

    private function findRequired(int $id): Category
    {
        return $this->findById($id) ?? throw new RuntimeException("Category not found after write: {$id}");
    }
}
