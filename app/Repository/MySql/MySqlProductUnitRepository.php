<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\ProductUnit;
use App\Exception\EntityNotFoundException;
use App\Exception\PersistenceException;
use App\Repository\Contract\ProductUnitRepositoryInterface;
use PDO;

/** product_units (D-16, ADR-022). UNIQUE keys guard one base unit and at most one default per purpose per product. */
final class MySqlProductUnitRepository implements ProductUnitRepositoryInterface
{
    private const COLUMNS = 'id, product_id, unit_code, factor_to_base, barcode, purchase_price, selling_price, is_base, is_purchase_default, is_sales_default, is_active';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function forProduct(int $productId): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM product_units WHERE product_id = :product_id ORDER BY factor_to_base DESC, id ASC');
        $statement->execute(['product_id' => $productId]);

        return array_map(self::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM product_units WHERE product_id IN ({$placeholders}) ORDER BY product_id, factor_to_base DESC, id ASC");
        $statement->execute($productIds);
        $units = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $units[(int) $row['product_id']][] = self::hydrate($row);
        }

        return $units;
    }

    public function find(int $unitId): ?ProductUnit
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM product_units WHERE id = :id');
        $statement->execute(['id' => $unitId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function findByBarcode(string $barcode): ?ProductUnit
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM product_units WHERE barcode = :barcode');
        $statement->execute(['barcode' => $barcode]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function lockForProduct(int $productId): array
    {
        $this->requireTransaction();
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM product_units WHERE product_id = :product_id ORDER BY id FOR UPDATE');
        $statement->execute(['product_id' => $productId]);

        return array_map(self::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function shareLock(int $productId, array $codes): array
    {
        $this->requireTransaction();
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM product_units WHERE product_id = ? AND unit_code IN ({$placeholders}) ORDER BY id FOR SHARE");
        $statement->execute([$productId, ...$codes]);

        return array_map(self::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function create(int $productId, array $unit): ProductUnit
    {
        return PersistenceErrors::write(function () use ($productId, $unit): ProductUnit {
            $statement = $this->pdo->prepare(
                'INSERT INTO product_units (product_id, unit_code, factor_to_base, barcode, purchase_price, selling_price, is_base, is_purchase_default, is_sales_default, is_active)
                 VALUES (:product_id, :unit_code, :factor, :barcode, :purchase_price, :selling_price, :is_base, :is_purchase_default, :is_sales_default, :is_active)'
            );
            $statement->execute([
                'product_id' => $productId,
                'unit_code' => $unit['code'],
                'factor' => $unit['factor'],
                'barcode' => $unit['barcode'],
                'purchase_price' => $unit['purchase_price'],
                'selling_price' => $unit['selling_price'],
                'is_base' => $unit['is_base'] ? 1 : 0,
                'is_purchase_default' => $unit['is_purchase_default'] ? 1 : 0,
                'is_sales_default' => $unit['is_sales_default'] ? 1 : 0,
                'is_active' => $unit['is_active'] ? 1 : 0,
            ]);

            return $this->find((int) $this->pdo->lastInsertId()) ?? throw new EntityNotFoundException('Product unit not found after write.');
        });
    }

    public function update(int $unitId, array $unit): ProductUnit
    {
        return PersistenceErrors::write(function () use ($unitId, $unit): ProductUnit {
            $statement = $this->pdo->prepare(
                'UPDATE product_units SET unit_code = :unit_code, factor_to_base = :factor, barcode = :barcode, purchase_price = :purchase_price,
                 selling_price = :selling_price, is_purchase_default = :is_purchase_default, is_sales_default = :is_sales_default, is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $unitId,
                'unit_code' => $unit['code'],
                'factor' => $unit['factor'],
                'barcode' => $unit['barcode'],
                'purchase_price' => $unit['purchase_price'],
                'selling_price' => $unit['selling_price'],
                'is_purchase_default' => $unit['is_purchase_default'] ? 1 : 0,
                'is_sales_default' => $unit['is_sales_default'] ? 1 : 0,
                'is_active' => $unit['is_active'] ? 1 : 0,
            ]);

            return $this->find($unitId) ?? throw new EntityNotFoundException('Product unit not found after write.');
        });
    }

    public function clearDefault(int $productId, string $purpose, int $exceptUnitId): void
    {
        $column = match ($purpose) {
            'purchase' => 'is_purchase_default',
            'sales' => 'is_sales_default',
            default => throw new \LogicException('Unknown default purpose.'),
        };
        $statement = $this->pdo->prepare("UPDATE product_units SET {$column} = 0 WHERE product_id = :product_id AND id <> :id AND {$column} = 1");
        $statement->execute(['product_id' => $productId, 'id' => $exceptUnitId]);
    }

    public function isUsed(int $productId, string $code): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT EXISTS (SELECT 1 FROM purchase_order_items WHERE product_id = :p1 AND unit_code = :c1)
                 OR EXISTS (SELECT 1 FROM sales_order_items WHERE product_id = :p2 AND unit_code = :c2)
                 OR EXISTS (SELECT 1 FROM inventory_operation_items WHERE product_id = :p3 AND unit_code = :c3)'
        );
        $statement->execute(['p1' => $productId, 'c1' => $code, 'p2' => $productId, 'c2' => $code, 'p3' => $productId, 'c3' => $code]);

        return (bool) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): ProductUnit
    {
        return new ProductUnit(
            (int) $row['id'],
            (int) $row['product_id'],
            (string) $row['unit_code'],
            (int) $row['factor_to_base'],
            $row['barcode'] === null ? null : (string) $row['barcode'],
            $row['purchase_price'] === null ? null : (float) $row['purchase_price'],
            $row['selling_price'] === null ? null : (float) $row['selling_price'],
            (bool) $row['is_base'],
            (bool) $row['is_purchase_default'],
            (bool) $row['is_sales_default'],
            (bool) $row['is_active'],
        );
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new PersistenceException('Product unit locks require a transaction.');
        }
    }
}
