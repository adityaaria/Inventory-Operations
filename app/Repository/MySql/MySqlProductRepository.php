<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\Product;
use App\Entity\ProductStock;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\ProductSearchCriteria;
use PDO;
use RuntimeException;

final class MySqlProductRepository implements ProductRepositoryInterface
{
    private const SORT_COLUMNS = [
        'name' => 'p.name',
        'sku' => 'p.sku',
        'price' => 'p.selling_price',
        'quantity' => 'total_quantity',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(ProductSearchCriteria $criteria): PaginatedResult
    {
        [$where, $params] = $this->whereClause($criteria);
        $count = $this->count($where, $params);
        $sort = self::SORT_COLUMNS[$criteria->sortBy()] ?? self::SORT_COLUMNS['name'];
        $direction = $criteria->direction() === 'desc' ? 'DESC' : 'ASC';

        $sql = "SELECT p.id, p.sku, p.name, p.unit, p.purchase_price, p.selling_price, p.reorder_point, p.category_id, p.is_active
                FROM products p
                LEFT JOIN (
                    SELECT product_id, SUM(quantity) AS total_quantity
                    FROM product_stocks
                    GROUP BY product_id
                ) stock ON stock.product_id = p.id
                {$where}
                ORDER BY {$sort} {$direction}, p.id ASC
                LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $criteria->perPage(), PDO::PARAM_INT);
        $statement->bindValue('offset', $criteria->offset(), PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(fn (array $row): Product => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));

        return new PaginatedResult($items, $count, $criteria->page(), $criteria->perPage());
    }

    public function findById(int $id): ?Product
    {
        $statement = $this->pdo->prepare(
            'SELECT id, sku, name, unit, purchase_price, selling_price, reorder_point, category_id, is_active FROM products WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(string $sku, string $name, string $unit, float $purchasePrice, float $sellingPrice, int $reorderPoint, int $categoryId, bool $isActive): Product
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO products (sku, name, unit, purchase_price, selling_price, price, reorder_point, category_id, is_active)
             VALUES (:sku, :name, :unit, :purchase_price, :selling_price, :price, :reorder_point, :category_id, :is_active)'
        );
        $statement->execute([
            'sku' => $sku,
            'name' => $name,
            'unit' => $unit,
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'price' => $sellingPrice,
            'reorder_point' => $reorderPoint,
            'category_id' => $categoryId,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return $this->findRequired((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, string $sku, string $name, string $unit, float $purchasePrice, float $sellingPrice, int $reorderPoint, int $categoryId): Product
    {
        $statement = $this->pdo->prepare(
            'UPDATE products
             SET sku = :sku, name = :name, unit = :unit, purchase_price = :purchase_price, selling_price = :selling_price, price = :price, reorder_point = :reorder_point, category_id = :category_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'sku' => $sku,
            'name' => $name,
            'unit' => $unit,
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'price' => $sellingPrice,
            'reorder_point' => $reorderPoint,
            'category_id' => $categoryId,
        ]);

        return $this->findRequired($id);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE products SET is_active = :is_active WHERE id = :id');
        $statement->execute(['id' => $id, 'is_active' => $isActive ? 1 : 0]);
    }

    public function stocksForProduct(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id AS product_id, w.id AS warehouse_id, p.sku, p.name AS product_name,
                    w.name AS warehouse_name, ps.quantity, p.reorder_point
             FROM product_stocks ps
             INNER JOIN products p ON p.id = ps.product_id
             INNER JOIN warehouses w ON w.id = ps.warehouse_id
             WHERE p.id = :product_id
             ORDER BY w.name ASC'
        );
        $statement->execute(['product_id' => $productId]);

        return array_map(static fn (array $row): ProductStock => new ProductStock(
            (int) $row['product_id'],
            (int) $row['warehouse_id'],
            (string) $row['sku'],
            (string) $row['product_name'],
            (string) $row['warehouse_name'],
            (int) $row['quantity'],
            (int) $row['reorder_point'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function whereClause(ProductSearchCriteria $criteria): array
    {
        $parts = ['p.is_active = 1'];
        $params = [];
        if ($criteria->term() !== '') {
            $parts[] = '(p.name LIKE :term_name OR p.sku LIKE :term_sku)';
            $params['term_name'] = '%' . $criteria->term() . '%';
            $params['term_sku'] = '%' . $criteria->term() . '%';
        }
        if ($criteria->categoryId() !== null) {
            $parts[] = 'p.category_id = :category_id';
            $params['category_id'] = $criteria->categoryId();
        }
        if ($criteria->stockStatus() === 'low') {
            $parts[] = 'COALESCE(stock.total_quantity, 0) <= p.reorder_point';
        } elseif ($criteria->stockStatus() === 'normal') {
            $parts[] = 'COALESCE(stock.total_quantity, 0) > p.reorder_point';
        }

        return ['WHERE ' . implode(' AND ', $parts), $params];
    }

    /** @param array<string, mixed> $params */
    private function count(string $where, array $params): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS total
             FROM products p
             LEFT JOIN (
                 SELECT product_id, SUM(quantity) AS total_quantity
                 FROM product_stocks
                 GROUP BY product_id
             ) stock ON stock.product_id = p.id
             {$where}"
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unable to count products.');
        }

        return (int) $row['total'];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Product
    {
        return new Product(
            (int) $row['id'],
            (string) $row['sku'],
            (string) $row['name'],
            (string) $row['unit'],
            (float) $row['purchase_price'],
            (float) $row['selling_price'],
            (int) $row['reorder_point'],
            (int) $row['category_id'],
            (bool) $row['is_active'],
        );
    }

    private function findRequired(int $id): Product
    {
        return $this->findById($id) ?? throw new RuntimeException("Product not found after write: {$id}");
    }
}
