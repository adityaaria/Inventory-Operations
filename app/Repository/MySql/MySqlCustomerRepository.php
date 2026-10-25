<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\Customer;
use App\Exception\EntityNotFoundException;
use App\Exception\PersistenceException;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\Pagination;
use PDO;

final class MySqlCustomerRepository implements CustomerRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function paginate(Pagination $pagination): PaginatedResult
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM customers');
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, phone, address, is_active, price_list_id, npwp, nik, tax_name, tax_address FROM customers
             ORDER BY name ASC, id ASC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('limit', Pagination::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue('offset', $pagination->offsetForTotal($total), PDO::PARAM_INT);
        $statement->execute();

        return new PaginatedResult(
            array_map(fn (array $row): Customer => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC)),
            $total,
            $pagination->pageForTotal($total),
            Pagination::PER_PAGE,
        );
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, name, email, phone, address, is_active, price_list_id, npwp, nik, tax_name, tax_address FROM customers ORDER BY name ASC');
        if ($statement === false) {
            throw new PersistenceException('Unable to query customers.');
        }
        return array_map(fn (array $row): Customer => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?Customer
    {
        $statement = $this->pdo->prepare('SELECT id, name, email, phone, address, is_active, price_list_id, npwp, nik, tax_name, tax_address FROM customers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(string $name, string $email, string $phone, string $address, bool $isActive): Customer
    {
        return PersistenceErrors::write(function () use ($name, $email, $phone, $address, $isActive): Customer {
            $statement = $this->pdo->prepare('INSERT INTO customers (name, email, phone, address, is_active) VALUES (:name, :email, :phone, :address, :is_active)');
            $statement->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'is_active' => $isActive ? 1 : 0]);
            return $this->findRequired((int) $this->pdo->lastInsertId());

        });
    }

    public function update(int $id, string $name, string $email, string $phone, string $address): Customer
    {
        return PersistenceErrors::write(function () use ($id, $name, $email, $phone, $address): Customer {
            $statement = $this->pdo->prepare('UPDATE customers SET name = :name, email = :email, phone = :phone, address = :address WHERE id = :id');
            $statement->execute(['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address]);
            return $this->findRequired($id);

        });
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE customers SET is_active = :is_active WHERE id = :id');
        $statement->execute(['id' => $id, 'is_active' => $isActive ? 1 : 0]);
    }

    public function updateTaxProfile(int $id, ?string $npwp, ?string $nik, ?string $taxName, ?string $taxAddress): Customer
    {
        return PersistenceErrors::write(function () use ($id, $npwp, $nik, $taxName, $taxAddress): Customer {
            $statement = $this->pdo->prepare('UPDATE customers SET npwp = :npwp, nik = :nik, tax_name = :tax_name, tax_address = :tax_address WHERE id = :id');
            $statement->execute(['id' => $id, 'npwp' => $npwp, 'nik' => $nik, 'tax_name' => $taxName, 'tax_address' => $taxAddress]);

            return $this->findRequired($id);
        });
    }

    public function assignPriceList(int $customerId, ?int $priceListId): void
    {
        $statement = $this->pdo->prepare('UPDATE customers SET price_list_id = :price_list_id WHERE id = :id');
        $statement->execute(['id' => $customerId, 'price_list_id' => $priceListId]);
    }

    public function forPriceList(int $priceListId): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, email, phone, address, is_active, price_list_id, npwp, nik, tax_name, tax_address FROM customers WHERE price_list_id = :id ORDER BY name ASC, id ASC');
        $statement->execute(['id' => $priceListId]);

        return array_map(fn (array $row): Customer => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Customer
    {
        $text = static fn (string $key): ?string => isset($row[$key]) ? (string) $row[$key] : null;

        return new Customer((int) $row['id'], (string) $row['name'], (string) $row['email'], (string) $row['phone'], (string) $row['address'], (bool) $row['is_active'],
            isset($row['price_list_id']) ? (int) $row['price_list_id'] : null, $text('npwp'), $text('nik'), $text('tax_name'), $text('tax_address'));
    }

    private function findRequired(int $id): Customer
    {
        return $this->findById($id) ?? throw new EntityNotFoundException("Customer not found after write: {$id}");
    }
}
