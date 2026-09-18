<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\Warehouse;
use App\Repository\Contract\WarehouseRepositoryInterface;
use PDO;
use RuntimeException;

final class MySqlWarehouseRepository implements WarehouseRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, name, location, is_active FROM warehouses ORDER BY name ASC');
        if ($statement === false) {
            throw new RuntimeException('Unable to query warehouses.');
        }
        return array_map(fn (array $row): Warehouse => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function active(): array
    {
        $statement = $this->pdo->query('SELECT id, name, location, is_active FROM warehouses WHERE is_active = 1 ORDER BY name ASC');
        if ($statement === false) {
            throw new RuntimeException('Unable to query active warehouses.');
        }
        return array_map(fn (array $row): Warehouse => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?Warehouse
    {
        $statement = $this->pdo->prepare('SELECT id, name, location, is_active FROM warehouses WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(string $name, string $location, bool $isActive): Warehouse
    {
        $statement = $this->pdo->prepare('INSERT INTO warehouses (name, location, is_active) VALUES (:name, :location, :is_active)');
        $statement->execute(['name' => $name, 'location' => $location, 'is_active' => $isActive ? 1 : 0]);
        return $this->findRequired((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, string $name, string $location): Warehouse
    {
        $statement = $this->pdo->prepare('UPDATE warehouses SET name = :name, location = :location WHERE id = :id');
        $statement->execute(['id' => $id, 'name' => $name, 'location' => $location]);
        return $this->findRequired($id);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE warehouses SET is_active = :is_active WHERE id = :id');
        $statement->execute(['id' => $id, 'is_active' => $isActive ? 1 : 0]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Warehouse
    {
        return new Warehouse((int) $row['id'], (string) $row['name'], (string) $row['location'], (bool) $row['is_active']);
    }

    private function findRequired(int $id): Warehouse
    {
        return $this->findById($id) ?? throw new RuntimeException("Warehouse not found after write: {$id}");
    }
}
