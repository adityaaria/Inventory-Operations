<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\Supplier;
use App\Exception\EntityNotFoundException;
use App\Exception\PersistenceException;
use App\Repository\Contract\SupplierRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\Pagination;
use PDO;

final class MySqlSupplierRepository implements SupplierRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function paginate(Pagination $pagination): PaginatedResult
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM suppliers');
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, phone, address, is_active FROM suppliers
             ORDER BY name ASC, id ASC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('limit', Pagination::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue('offset', $pagination->offsetForTotal($total), PDO::PARAM_INT);
        $statement->execute();

        return new PaginatedResult(
            array_map(fn (array $row): Supplier => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC)),
            $total,
            $pagination->pageForTotal($total),
            Pagination::PER_PAGE,
        );
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, name, email, phone, address, is_active FROM suppliers ORDER BY name ASC');
        if ($statement === false) {
            throw new PersistenceException('Unable to query suppliers.');
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
        return PersistenceErrors::write(function () use ($name, $email, $phone, $address, $isActive): Supplier {
            $statement = $this->pdo->prepare('INSERT INTO suppliers (name, email, phone, address, is_active) VALUES (:name, :email, :phone, :address, :is_active)');
            $statement->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'is_active' => $isActive ? 1 : 0]);
            return $this->findRequired((int) $this->pdo->lastInsertId());

        });
    }

    public function update(int $id, string $name, string $email, string $phone, string $address): Supplier
    {
        return PersistenceErrors::write(function () use ($id, $name, $email, $phone, $address): Supplier {
            $statement = $this->pdo->prepare('UPDATE suppliers SET name = :name, email = :email, phone = :phone, address = :address WHERE id = :id');
            $statement->execute(['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address]);
            return $this->findRequired($id);

        });
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
        return $this->findById($id) ?? throw new EntityNotFoundException("Supplier not found after write: {$id}");
    }
}
