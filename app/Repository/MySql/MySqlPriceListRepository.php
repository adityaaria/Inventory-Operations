<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\PriceListRepositoryInterface;
use PDO;

/** Customer price lists and tiers (D-19, ADR-025). Prepared statements only. */
final class MySqlPriceListRepository implements PriceListRepositoryInterface
{
    private const HEADER = 'SELECT pl.id, pl.name, pl.description, pl.is_active, pl.valid_from, pl.valid_to,
            (SELECT COUNT(*) FROM price_list_items i WHERE i.price_list_id = pl.id) AS item_count,
            (SELECT COUNT(*) FROM customers c WHERE c.price_list_id = pl.id) AS customer_count
        FROM price_lists pl';

    private const ITEM = 'SELECT i.id, i.price_list_id, i.product_id, p.sku, p.name AS product_name, i.unit_code, i.min_quantity, i.unit_price
        FROM price_list_items i JOIN products p ON p.id = i.product_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare(self::HEADER . ' ORDER BY pl.name ASC, pl.id ASC');
        $statement->execute();

        return array_map(self::header(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::HEADER . ' WHERE pl.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::header($row) : null;
    }

    public function create(string $name, string $description, bool $isActive, ?string $validFrom, ?string $validTo): int
    {
        return PersistenceErrors::write(function () use ($name, $description, $isActive, $validFrom, $validTo): int {
            $statement = $this->pdo->prepare('INSERT INTO price_lists (name, description, is_active, valid_from, valid_to) VALUES (:name, :description, :active, :from, :to)');
            $statement->execute(['name' => $name, 'description' => $description, 'active' => $isActive ? 1 : 0, 'from' => $validFrom, 'to' => $validTo]);

            return (int) $this->pdo->lastInsertId();
        });
    }

    public function update(int $id, string $name, string $description, bool $isActive, ?string $validFrom, ?string $validTo): void
    {
        PersistenceErrors::write(function () use ($id, $name, $description, $isActive, $validFrom, $validTo): void {
            $statement = $this->pdo->prepare('UPDATE price_lists SET name = :name, description = :description, is_active = :active, valid_from = :from, valid_to = :to WHERE id = :id');
            $statement->execute(['id' => $id, 'name' => $name, 'description' => $description, 'active' => $isActive ? 1 : 0, 'from' => $validFrom, 'to' => $validTo]);
        });
    }

    public function items(int $priceListId): array
    {
        $statement = $this->pdo->prepare(self::ITEM . ' WHERE i.price_list_id = :id ORDER BY p.sku ASC, i.unit_code ASC, i.min_quantity ASC');
        $statement->execute(['id' => $priceListId]);

        return array_map(self::item(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findItem(int $itemId): ?array
    {
        $statement = $this->pdo->prepare(self::ITEM . ' WHERE i.id = :id');
        $statement->execute(['id' => $itemId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::item($row) : null;
    }

    public function addItem(int $priceListId, int $productId, string $unitCode, int $minQuantity, string $unitPrice): int
    {
        return PersistenceErrors::write(function () use ($priceListId, $productId, $unitCode, $minQuantity, $unitPrice): int {
            $statement = $this->pdo->prepare('INSERT INTO price_list_items (price_list_id, product_id, unit_code, min_quantity, unit_price) VALUES (:list, :product, :unit, :min, :price)');
            $statement->execute(['list' => $priceListId, 'product' => $productId, 'unit' => $unitCode, 'min' => $minQuantity, 'price' => $unitPrice]);

            return (int) $this->pdo->lastInsertId();
        });
    }

    public function updateItem(int $itemId, int $minQuantity, string $unitPrice): void
    {
        PersistenceErrors::write(function () use ($itemId, $minQuantity, $unitPrice): void {
            $statement = $this->pdo->prepare('UPDATE price_list_items SET min_quantity = :min, unit_price = :price WHERE id = :id');
            $statement->execute(['id' => $itemId, 'min' => $minQuantity, 'price' => $unitPrice]);
        });
    }

    public function deleteItem(int $itemId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM price_list_items WHERE id = :id');
        $statement->execute(['id' => $itemId]);
    }

    public function tiersFor(int $customerId, int $productId, string $unitCode, string $today): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id AS item_id, pl.id AS price_list_id, pl.name AS price_list_name, i.min_quantity, i.unit_price
             FROM customers c
             JOIN price_lists pl ON pl.id = c.price_list_id AND pl.is_active = 1
                AND (pl.valid_from IS NULL OR pl.valid_from <= :today_from) AND (pl.valid_to IS NULL OR pl.valid_to >= :today_to)
             JOIN price_list_items i ON i.price_list_id = pl.id AND i.product_id = :product AND i.unit_code = :unit
             WHERE c.id = :customer
             ORDER BY i.min_quantity ASC'
        );
        $statement->execute(['customer' => $customerId, 'product' => $productId, 'unit' => $unitCode, 'today_from' => $today, 'today_to' => $today]);

        return array_map(static fn (array $row): array => [
            'item_id' => (int) $row['item_id'],
            'price_list_id' => (int) $row['price_list_id'],
            'price_list_name' => (string) $row['price_list_name'],
            'min_quantity' => (int) $row['min_quantity'],
            'unit_price' => (string) $row['unit_price'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function today(): string
    {
        $statement = $this->pdo->query('SELECT ' . ExpirySql::today());

        return $statement === false ? \App\Support\BusinessDate::today() : (string) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $row @return array{id: int, name: string, description: string, is_active: bool, valid_from: ?string, valid_to: ?string, item_count: int, customer_count: int} */
    private static function header(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'is_active' => (bool) $row['is_active'],
            'valid_from' => $row['valid_from'] === null ? null : (string) $row['valid_from'],
            'valid_to' => $row['valid_to'] === null ? null : (string) $row['valid_to'],
            'item_count' => (int) $row['item_count'],
            'customer_count' => (int) $row['customer_count'],
        ];
    }

    /** @param array<string, mixed> $row @return array{id: int, price_list_id: int, product_id: int, sku: string, product_name: string, unit_code: string, min_quantity: int, unit_price: string} */
    private static function item(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'price_list_id' => (int) $row['price_list_id'],
            'product_id' => (int) $row['product_id'],
            'sku' => (string) $row['sku'],
            'product_name' => (string) $row['product_name'],
            'unit_code' => (string) $row['unit_code'],
            'min_quantity' => (int) $row['min_quantity'],
            'unit_price' => (string) $row['unit_price'],
        ];
    }
}
