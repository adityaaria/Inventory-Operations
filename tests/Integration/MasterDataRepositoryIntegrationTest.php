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

        $this->pdo = \Tests\Support\TestDatabase::connect();
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
             WHERE p.sku = "BIS-0001"
             ORDER BY w.name ASC'
        );
        self::assertNotFalse($stocks);
        $rows = $stocks->fetchAll();

        // One balance per warehouse (3 in the FMCG seed), each kept separately.
        self::assertCount(3, $rows);
        self::assertGreaterThan(1, count(array_unique(array_map(static fn (array $row): int => (int) $row['quantity'], $rows))));
    }

    public function testProductSearchTermWorksWithNativeMySqlPreparedStatements(): void
    {
        $repository = new MySqlProductRepository($this->pdo);

        $result = $repository->search(ProductSearchCriteria::fromArray(['q' => 'Tirta Alam']));

        self::assertGreaterThanOrEqual(2, $result->total());
        self::assertSame('MIN-0038', $result->items()[0]->sku());
    }

    public function testProductStockQuantityCannotBeNegative(): void
    {
        $this->expectException(\PDOException::class);

        // The seed already has a balance for every product/warehouse pair; a negative value must be rejected.
        $statement = $this->pdo->prepare(
            'UPDATE product_stocks ps
             INNER JOIN products p ON p.id = ps.product_id
             SET ps.quantity = -1
             WHERE p.sku = :sku'
        );
        $statement->execute(['sku' => 'MIN-0038']);
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
