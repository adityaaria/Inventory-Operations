<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\MySqlProductRepository;
use App\Support\ProductSearchCriteria;
use PDO;
use PHPUnit\Framework\TestCase;

final class MasterDataRepositoryIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_DATABASE') ?: 'inventory_order_management';
        $username = getenv('DB_USERNAME') ?: 'inventory_app';
        $password = getenv('DB_PASSWORD') ?: 'change_me_for_local_only';

        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    public function testMasterDataSchemaSupportsSkuWarehouseAndStockQueries(): void
    {
        self::assertTrue($this->columnExists('categories', 'description'));
        self::assertTrue($this->columnExists('products', 'purchase_price'));
        self::assertTrue($this->columnExists('products', 'selling_price'));
        self::assertTrue($this->columnExists('suppliers', 'address'));
        self::assertTrue($this->columnExists('customers', 'address'));

        $skuIndex = $this->pdo->query("SHOW INDEX FROM products WHERE Key_name = 'uq_products_sku'");
        self::assertNotFalse($skuIndex);
        self::assertNotSame([], $skuIndex->fetchAll());

        $warehouseCount = $this->pdo->query('SELECT COUNT(*) AS total FROM warehouses WHERE is_active = 1');
        self::assertNotFalse($warehouseCount);
        self::assertGreaterThanOrEqual(2, (int) $warehouseCount->fetch()['total']);

        $stocks = $this->pdo->query(
            'SELECT p.sku, w.name AS warehouse_name, ps.quantity
             FROM product_stocks ps
             INNER JOIN products p ON p.id = ps.product_id
             INNER JOIN warehouses w ON w.id = ps.warehouse_id
             WHERE p.sku = "SKU-DEMO-001"
             ORDER BY w.name ASC'
        );
        self::assertNotFalse($stocks);
        $rows = $stocks->fetchAll();

        self::assertCount(2, $rows);
        self::assertNotSame((int) $rows[0]['quantity'], (int) $rows[1]['quantity']);
    }

    public function testProductSearchTermWorksWithNativeMySqlPreparedStatements(): void
    {
        $repository = new MySqlProductRepository($this->pdo);

        $result = $repository->search(ProductSearchCriteria::fromArray(['q' => 'Demo']));

        self::assertGreaterThanOrEqual(2, $result->total());
        self::assertSame('SKU-DEMO-001', $result->items()[0]->sku());
    }

    public function testProductStockQuantityCannotBeNegative(): void
    {
        $this->expectException(\PDOException::class);

        $statement = $this->pdo->prepare(
            'INSERT INTO product_stocks (product_id, warehouse_id, quantity)
             SELECT p.id, w.id, -1
             FROM products p
             CROSS JOIN warehouses w
             WHERE p.sku = :sku AND w.code = :warehouse_code
             LIMIT 1'
        );
        $statement->execute(['sku' => 'SKU-DEMO-002', 'warehouse_code' => 'MAIN']);
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        return (int) $statement->fetch()['total'] === 1;
    }
}
