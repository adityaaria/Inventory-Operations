<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\Product;
use App\Entity\ProductStock;
use App\Exception\EntityNotFoundException;
use App\Exception\PersistenceException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Support\PaginatedResult;
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;
use PDO;

final class MySqlProductRepository implements ProductRepositoryInterface
{
    private const SORT_COLUMNS = [
        'name' => 'p.name',
        'sku' => 'p.sku',
        'unit' => 'p.unit',
        'purchase_price' => 'p.purchase_price',
        'price' => 'p.selling_price',
        'reorder' => 'p.reorder_point',
        'quantity' => 'total_quantity',
    ];

    private const STOCK_COLUMNS = 'p.id AS product_id, w.id AS warehouse_id, p.sku, p.name AS product_name, w.name AS warehouse_name,
        ps.quantity, ps.reserved_quantity, p.reorder_point, ps.reorder_point_override, w.is_active AS warehouse_active, p.is_active AS product_active';

    /** Expired units and nearest expiry of the pair (D-17, ADR-023). */
    private static function lotColumns(): string
    {
        return ExpirySql::expiredQuantity('ps') . ' AS expired_quantity, ' . ExpirySql::nearestExpiry('ps') . ' AS nearest_expiry';
    }

    private static function lowPair(): string
    {
        return 'SELECT 1 FROM product_stocks ps INNER JOIN warehouses w ON w.id = ps.warehouse_id
        WHERE ps.product_id = p.id AND w.is_active = 1 AND ' . LowStockSql::isLow();
    }

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(ProductSearchCriteria $criteria): PaginatedResult
    {
        [$where, $params] = $this->whereClause($criteria);
        $count = $this->count($where, $params);
        $sort = self::SORT_COLUMNS[$criteria->sortBy()] ?? self::SORT_COLUMNS['name'];
        $direction = $criteria->direction() === 'desc' ? 'DESC' : 'ASC';

        $sql = "SELECT p.id, p.sku, p.name, p.unit, p.purchase_price, p.selling_price, p.reorder_point, p.category_id, p.is_active, p.tracks_expiry
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

    public function active(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM products WHERE is_active = 1 ORDER BY name ASC, id ASC');
        $statement->execute();
        return array_map(fn (array $row): Product => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?Product
    {
        $statement = $this->pdo->prepare(
            'SELECT id, sku, name, unit, purchase_price, selling_price, reorder_point, category_id, is_active, tracks_expiry FROM products WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(ProductInput $input, bool $isActive): Product
    {
        // The product and its base unit row (D-16, ADR-022: products.unit = base unit code, factor 1) commit together.
        return PersistenceErrors::write(fn (): Product => (new MySqlTransactionManager($this->pdo))->run(function () use ($input, $isActive): Product {
            $statement = $this->pdo->prepare(
                'INSERT INTO products (sku, name, unit, purchase_price, selling_price, price, reorder_point, category_id, is_active, tracks_expiry)
                 VALUES (:sku, :name, :unit, :purchase_price, :selling_price, :price, :reorder_point, :category_id, :is_active, :tracks_expiry)'
            );
            $statement->execute([
                'sku' => $input->sku,
                'name' => $input->name,
                'unit' => $input->unit,
                'purchase_price' => $input->purchasePrice,
                'selling_price' => $input->sellingPrice,
                'price' => $input->sellingPrice,
                'reorder_point' => $input->reorderPoint,
                'category_id' => $input->categoryId,
                'is_active' => $isActive ? 1 : 0,
                'tracks_expiry' => $input->tracksExpiry ? 1 : 0,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $unit = $this->pdo->prepare('INSERT INTO product_units (product_id, unit_code, factor_to_base, is_base) VALUES (:product_id, :unit_code, 1, 1)');
            $unit->execute(['product_id' => $id, 'unit_code' => $input->unit]);

            return $this->findRequired($id);
        }));
    }

    public function update(int $id, ProductInput $input): Product
    {
        // Renaming products.unit renames the base unit row in the same transaction (D-16, ADR-022); a collision with
        // another unit code of the product hits UNIQUE(product_id, unit_code) and is refused with 422.
        return PersistenceErrors::write(fn (): Product => (new MySqlTransactionManager($this->pdo))->run(function () use ($id, $input): Product {
            $statement = $this->pdo->prepare(
                'UPDATE products
                 SET sku = :sku, name = :name, unit = :unit, purchase_price = :purchase_price, selling_price = :selling_price, price = :price, reorder_point = :reorder_point, category_id = :category_id, tracks_expiry = :tracks_expiry
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'sku' => $input->sku,
                'name' => $input->name,
                'unit' => $input->unit,
                'purchase_price' => $input->purchasePrice,
                'selling_price' => $input->sellingPrice,
                'price' => $input->sellingPrice,
                'reorder_point' => $input->reorderPoint,
                'category_id' => $input->categoryId,
                'tracks_expiry' => $input->tracksExpiry ? 1 : 0,
            ]);
            $unit = $this->pdo->prepare('UPDATE product_units SET unit_code = :unit_code WHERE product_id = :product_id AND is_base = 1');
            $unit->execute(['unit_code' => $input->unit, 'product_id' => $id]);

            return $this->findRequired($id);
        }));
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE products SET is_active = :is_active WHERE id = :id');
        $statement->execute(['id' => $id, 'is_active' => $isActive ? 1 : 0]);
    }

    public function stocksForProduct(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::STOCK_COLUMNS . ', ' . self::lotColumns() . ', COALESCE(transit.units, 0) AS in_transit_quantity
             FROM product_stocks ps
             INNER JOIN products p ON p.id = ps.product_id
             INNER JOIN warehouses w ON w.id = ps.warehouse_id
             LEFT JOIN (SELECT o.destination_id, SUM(i.dispatched_quantity) AS units FROM inventory_operation_items i
                        INNER JOIN inventory_operations o ON o.id = i.operation_id
                        WHERE o.kind = \'Transfer\' AND o.status = \'Dispatched\' AND i.product_id = :transit_product_id
                        GROUP BY o.destination_id) transit ON transit.destination_id = ps.warehouse_id
             WHERE p.id = :product_id
             ORDER BY w.name ASC'
        );
        $statement->execute(['product_id' => $productId, 'transit_product_id' => $productId]);

        return array_map(fn (array $row): ProductStock => $this->hydrateStock($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function stocksForProducts(array $productIds): array
    {
        if ($productIds === []) { return []; }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = $this->pdo->prepare('SELECT ' . self::STOCK_COLUMNS . ', ' . self::lotColumns() . ' FROM product_stocks ps
            INNER JOIN products p ON p.id = ps.product_id INNER JOIN warehouses w ON w.id = ps.warehouse_id
            WHERE ps.product_id IN (' . $placeholders . ') ORDER BY ps.product_id, w.name');
        $statement->execute($productIds);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['product_id']][] = $this->hydrateStock($row);
        }
        return $result;
    }

    public function lockStock(int $productId, int $warehouseId): ?ProductStock
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::STOCK_COLUMNS . '
             FROM product_stocks ps
             INNER JOIN products p ON p.id = ps.product_id
             INNER JOIN warehouses w ON w.id = ps.warehouse_id
             WHERE ps.product_id = :product_id AND ps.warehouse_id = :warehouse_id
             FOR UPDATE'
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrateStock($row) : null;
    }

    public function setReorderPointOverride(int $productId, int $warehouseId, ?int $override): void
    {
        PersistenceErrors::write(function () use ($productId, $warehouseId, $override): void {
            $statement = $this->pdo->prepare(
                'UPDATE product_stocks SET reorder_point_override = :override
                 WHERE product_id = :product_id AND warehouse_id = :warehouse_id'
            );
            $statement->bindValue('override', $override, $override === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $statement->bindValue('product_id', $productId, PDO::PARAM_INT);
            $statement->bindValue('warehouse_id', $warehouseId, PDO::PARAM_INT);
            $statement->execute();
        });
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
        // Low = low in at least one active warehouse (D-10); normal = low in none. Total stock is not used.
        if ($criteria->stockStatus() === 'low') {
            $parts[] = 'EXISTS (' . self::lowPair() . ')';
        } elseif ($criteria->stockStatus() === 'normal') {
            $parts[] = 'NOT EXISTS (' . self::lowPair() . ')';
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
            throw new PersistenceException('Unable to count products.');
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
            (bool) ($row['tracks_expiry'] ?? false),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateStock(array $row): ProductStock
    {
        return new ProductStock(
            (int) $row['product_id'],
            (int) $row['warehouse_id'],
            (string) $row['sku'],
            (string) $row['product_name'],
            (string) $row['warehouse_name'],
            (int) $row['quantity'],
            (int) $row['reorder_point'],
            $row['reorder_point_override'] === null ? null : (int) $row['reorder_point_override'],
            (bool) $row['warehouse_active'],
            (bool) $row['product_active'],
            (int) $row['reserved_quantity'],
            (int) ($row['in_transit_quantity'] ?? 0),
            (int) ($row['expired_quantity'] ?? 0),
            isset($row['nearest_expiry']) ? (string) $row['nearest_expiry'] : null,
        );
    }

    private function findRequired(int $id): Product
    {
        return $this->findById($id) ?? throw new EntityNotFoundException("Product not found after write: {$id}");
    }
}
