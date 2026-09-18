<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\Supplier;
use App\Repository\Contract\SupplierRepositoryInterface;
use PDO;
use RuntimeException;

final class MySqlSupplierRepository implements SupplierRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, name, email, phone, address, is_active FROM suppliers ORDER BY name ASC');
        if ($statement === false) {
            throw new RuntimeException('Unable to query suppliers.');
        }
        return array_map(fn (array $row): Supplier => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?Supplier
    {
        $statement = $this->pdo->prepare('SELECT id, name, email, phone, address, is_active FROM suppliers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(string $name, string $email, string $phone, string $address, bool $isActive): Supplier
    {
        $statement = $this->pdo->prepare('INSERT INTO suppliers (name, email, phone, address, is_active) VALUES (:name, :email, :phone, :address, :is_active)');
        $statement->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'is_active' => $isActive ? 1 : 0]);
        return $this->findRequired((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, string $name, string $email, string $phone, string $address): Supplier
    {
        $statement = $this->pdo->prepare('UPDATE suppliers SET name = :name, email = :email, phone = :phone, address = :address WHERE id = :id');
        $statement->execute(['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address]);
        return $this->findRequired($id);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE suppliers SET is_active = :is_active WHERE id = :id');
        $statement->execute(['id' => $id, 'is_active' => $isActive ? 1 : 0]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Supplier
    {
        return new Supplier((int) $row['id'], (string) $row['name'], (string) $row['email'], (string) $row['phone'], (string) $row['address'], (bool) $row['is_active']);
    }

    private function findRequired(int $id): Supplier
    {
        return $this->findById($id) ?? throw new RuntimeException("Supplier not found after write: {$id}");
    }
}
