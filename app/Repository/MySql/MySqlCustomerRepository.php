<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\Customer;
use App\Repository\Contract\CustomerRepositoryInterface;
use PDO;
use RuntimeException;

final class MySqlCustomerRepository implements CustomerRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, name, email, phone, address, is_active FROM customers ORDER BY name ASC');
        if ($statement === false) {
            throw new RuntimeException('Unable to query customers.');
        }
        return array_map(fn (array $row): Customer => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?Customer
    {
        $statement = $this->pdo->prepare('SELECT id, name, email, phone, address, is_active FROM customers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(string $name, string $email, string $phone, string $address, bool $isActive): Customer
    {
        $statement = $this->pdo->prepare('INSERT INTO customers (name, email, phone, address, is_active) VALUES (:name, :email, :phone, :address, :is_active)');
        $statement->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'is_active' => $isActive ? 1 : 0]);
        return $this->findRequired((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, string $name, string $email, string $phone, string $address): Customer
    {
        $statement = $this->pdo->prepare('UPDATE customers SET name = :name, email = :email, phone = :phone, address = :address WHERE id = :id');
        $statement->execute(['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address]);
        return $this->findRequired($id);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE customers SET is_active = :is_active WHERE id = :id');
        $statement->execute(['id' => $id, 'is_active' => $isActive ? 1 : 0]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Customer
    {
        return new Customer((int) $row['id'], (string) $row['name'], (string) $row['email'], (string) $row['phone'], (string) $row['address'], (bool) $row['is_active']);
    }

    private function findRequired(int $id): Customer
    {
        return $this->findById($id) ?? throw new RuntimeException("Customer not found after write: {$id}");
    }
}
