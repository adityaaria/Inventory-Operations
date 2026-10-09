<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exception\EntityNotFoundException;
use App\Exception\PersistenceException;
use App\Exception\ValidationException;
use App\Repository\MySql\MySqlCategoryRepository;
use App\Repository\MySql\MySqlCustomerRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlSupplierRepository;
use App\Repository\MySql\MySqlUserRepository;
use App\Repository\MySql\MySqlWarehouseRepository;
use App\Repository\MySql\PersistenceErrors;
use App\Support\Config;
use App\Support\DatabaseFactory;
use App\Support\ProductInput;
use App\Support\ProductSearchCriteria;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** Covers master-data repository writes, filters and the database error mapping against the real test schema. */
final class MySqlMasterDataCoverageIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connect();
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    public function testDatabaseFactoryBuildsStrictPreparedConnectionFromConfig(): void
    {
        $pdo = (new DatabaseFactory(new Config([
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_DATABASE' => (string) getenv('DB_DATABASE'),
            'DB_USERNAME' => getenv('DB_USERNAME') ?: 'inventory_app',
            'DB_PASSWORD' => (string) getenv('DB_PASSWORD'),
        ])))->create();

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        self::assertSame(getenv('DB_DATABASE'), $pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame('utf8mb4', $pdo->query('SELECT @@character_set_client')->fetchColumn());
        $row = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch();
        self::assertSame(['id'], array_keys($row));
    }

    public function testCategoryUpdateActivationAndDuplicateNameMapping(): void
    {
        $repository = new MySqlCategoryRepository($this->pdo);
        $created = $repository->create('Coverage Category', 'first', true);
        $updated = $repository->update($created->id(), 'Coverage Category Renamed', 'second');
        self::assertSame('Coverage Category Renamed', $updated->name());
        self::assertSame('second', $updated->description());

        $repository->setActive($created->id(), false);
        self::assertFalse($repository->findById($created->id())->isActive());
        self::assertNotContains($created->id(), array_map(static fn ($c): int => $c->id(), $repository->active()));
        self::assertContains($created->id(), array_map(static fn ($c): int => $c->id(), $repository->all()));

        $existing = $repository->all()[0]->name();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A record with that unique value already exists.');
        $repository->update($created->id(), $existing, 'duplicate');
    }

    public function testCategoryWriteMapsLengthViolation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A value exceeds the allowed length or numeric range.');
        (new MySqlCategoryRepository($this->pdo))->create(str_repeat('x', 121), '', true);
    }

    public function testCategoryUpdateOfMissingRowReportsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        (new MySqlCategoryRepository($this->pdo))->update(999999, 'Ghost Category', '');
    }

    public function testCustomerUpdateAndActivation(): void
    {
        $repository = new MySqlCustomerRepository($this->pdo);
        $customer = $repository->create('Coverage Customer', 'a@cov.test', '1', 'Street 1', true);
        $updated = $repository->update($customer->id(), 'Coverage Customer 2', 'b@cov.test', '2', 'Street 2');
        self::assertSame(['Coverage Customer 2', 'b@cov.test', '2', 'Street 2'], [$updated->name(), $updated->email(), $updated->phone(), $updated->address()]);
        $repository->setActive($customer->id(), false);
        self::assertFalse($repository->findById($customer->id())->isActive());
        $repository->setActive($customer->id(), true);
        self::assertTrue($repository->findById($customer->id())->isActive());
    }

    public function testSupplierUpdateAndActivation(): void
    {
        $repository = new MySqlSupplierRepository($this->pdo);
        $supplier = $repository->create('Coverage Supplier', 'a@cov.test', '1', 'Street 1', true);
        $updated = $repository->update($supplier->id(), 'Coverage Supplier 2', 'b@cov.test', '2', 'Street 2');
        self::assertSame(['Coverage Supplier 2', 'b@cov.test', '2', 'Street 2'], [$updated->name(), $updated->email(), $updated->phone(), $updated->address()]);
        $repository->setActive($supplier->id(), false);
        self::assertFalse($repository->findById($supplier->id())->isActive());
    }

    public function testWarehouseActiveListUpdateAndActivation(): void
    {
        $repository = new MySqlWarehouseRepository($this->pdo);
        $warehouse = $repository->create('Coverage Warehouse', 'Somewhere', true);
        self::assertContains($warehouse->id(), array_map(static fn ($w): int => $w->id(), $repository->active()));

        $updated = $repository->update($warehouse->id(), 'Coverage Warehouse 2', 'Elsewhere');
        self::assertSame(['Coverage Warehouse 2', 'Elsewhere'], [$updated->name(), $updated->location()]);

        $repository->setActive($warehouse->id(), false);
        $active = $repository->active();
        self::assertNotContains($warehouse->id(), array_map(static fn ($w): int => $w->id(), $active));
        foreach ($active as $row) {
            self::assertTrue($row->isActive());
        }
        $names = array_map(static fn ($w): string => $w->name(), $active);
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);
    }

    public function testUserUpdateOfMissingRowReportsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage('User not found after write: 999999');
        (new MySqlUserRepository($this->pdo))->update(999999, 'Ghost', 'ghost@cov.test', 'Sales');
    }

    public function testUnmappedDatabaseErrorIsRethrownUnchanged(): void
    {
        // An invalid ENUM role raises MySQL 1265 (data truncated), which has no friendly mapping.
        try {
            (new MySqlUserRepository($this->pdo))->create('Bad Role', 'badrole@cov.test', 'hash', 'Superuser', true);
            self::fail('Invalid role must be rejected by the database.');
        } catch (PDOException $exception) {
            self::assertSame(1265, (int) $exception->errorInfo[1]);
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $statement->execute(['email' => 'badrole@cov.test']);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testPersistenceErrorsPassesThroughSuccessfulWrite(): void
    {
        self::assertSame(42, PersistenceErrors::write(static fn (): int => 42));
    }

    public function testProductUpdatePersistsAllFieldsAndStocksAreListedPerWarehouse(): void
    {
        $repository = new MySqlProductRepository($this->pdo);
        $product = $repository->findById(1);
        self::assertNotNull($product);
        $updated = $repository->update(1, new ProductInput('BIS-0001X', 'Renamed Biscuit', 'box', 1500.5, 2500.25, 33, 2));
        self::assertSame(['BIS-0001X', 'Renamed Biscuit', 'box', 1500.5, 2500.25, 33, 2], [
            $updated->sku(), $updated->name(), $updated->unit(), $updated->purchasePrice(), $updated->sellingPrice(), $updated->reorderPoint(), $updated->categoryId(),
        ]);
        self::assertSame('2500.25', (string) $this->pdo->query('SELECT price FROM products WHERE id = 1')->fetchColumn());

        $stocks = $repository->stocksForProduct(1);
        $expected = $this->pdo->query('SELECT w.name, ps.quantity FROM product_stocks ps JOIN warehouses w ON w.id = ps.warehouse_id WHERE ps.product_id = 1 ORDER BY w.name')->fetchAll();
        self::assertCount(count($expected), $stocks);
        foreach ($stocks as $index => $stock) {
            self::assertSame(1, $stock->productId());
            self::assertSame('BIS-0001X', $stock->sku());
            self::assertSame('Renamed Biscuit', $stock->productName());
            self::assertSame($expected[$index]['name'], $stock->warehouseName());
            self::assertSame((int) $expected[$index]['quantity'], $stock->quantity());
            self::assertSame(33, $stock->reorderPoint());
        }
        self::assertSame([], $repository->stocksForProduct(999999));
    }

    public function testProductSearchFiltersByCategoryAndStockStatus(): void
    {
        $repository = new MySqlProductRepository($this->pdo);
        $category = (int) $this->pdo->query('SELECT category_id FROM products WHERE is_active = 1 GROUP BY category_id ORDER BY category_id LIMIT 1')->fetchColumn();
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE is_active = 1 AND category_id = :category');
        $statement->execute(['category' => $category]);
        $result = $repository->search(ProductSearchCriteria::fromArray(['category_id' => (string) $category]));
        self::assertSame((int) $statement->fetchColumn(), $result->total());
        foreach ($result->items() as $product) {
            self::assertSame($category, $product->categoryId());
        }

        $totals = 'SELECT p.id, p.reorder_point, COALESCE(SUM(ps.quantity), 0) AS qty FROM products p LEFT JOIN product_stocks ps ON ps.product_id = p.id WHERE p.is_active = 1 GROUP BY p.id, p.reorder_point';
        $low = (int) $this->pdo->query("SELECT COUNT(*) FROM ({$totals}) t WHERE qty <= reorder_point")->fetchColumn();
        $normal = (int) $this->pdo->query("SELECT COUNT(*) FROM ({$totals}) t WHERE qty > reorder_point")->fetchColumn();
        self::assertGreaterThan(0, $normal);
        self::assertSame($low, $repository->search(ProductSearchCriteria::fromArray(['stock_status' => 'low']))->total());
        $normalResult = $repository->search(ProductSearchCriteria::fromArray(['stock_status' => 'normal', 'sort' => 'quantity', 'direction' => 'desc']));
        self::assertSame($normal, $normalResult->total());
        $active = (int) $this->pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
        self::assertSame($active, $low + $normal);
        foreach ($normalResult->items() as $product) {
            $sum = array_sum(array_map(static fn ($s): int => $s->quantity(), $repository->stocksForProduct($product->id())));
            self::assertGreaterThan($product->reorderPoint(), $sum);
        }
    }

    public function testProductWritesMapForeignKeyCheckAndRangeViolations(): void
    {
        $repository = new MySqlProductRepository($this->pdo);
        $cases = [
            'foreign key' => [new ProductInput('COV-FK', 'FK', 'pcs', 1, 1, 0, 999999), 'The selected related record is invalid or in use.'],
            'check' => [new ProductInput('COV-CHK', 'Check', 'pcs', -1, 1, 0, 1), 'A value violates a required data constraint.'],
            'range' => [new ProductInput('COV-RNG', 'Range', 'pcs', 1e15, 1, 0, 1), 'A value exceeds the allowed length or numeric range.'],
        ];
        foreach ($cases as $label => [$input, $message]) {
            try {
                $repository->update(1, $input);
                self::fail("Expected {$label} violation.");
            } catch (ValidationException $exception) {
                self::assertSame($message, $exception->getMessage(), $label);
            }
        }
        self::assertSame('BIS-0001', $repository->findById(1)?->sku());
    }

    public function testProductCountFailureIsReported(): void
    {
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('Unable to count products.');
        (new MySqlProductRepository($this->emptyFetchPdo()))->search(ProductSearchCriteria::fromArray([]));
    }

    /** @return array<string, array{0: callable(PDO): mixed, 1: string}> */
    public static function failedQueries(): array
    {
        return [
            'categories' => [static fn (PDO $pdo): array => (new MySqlCategoryRepository($pdo))->all(), 'Unable to query categories.'],
            'active categories' => [static fn (PDO $pdo): array => (new MySqlCategoryRepository($pdo))->active(), 'Unable to query active categories.'],
            'customers' => [static fn (PDO $pdo): array => (new MySqlCustomerRepository($pdo))->all(), 'Unable to query customers.'],
            'suppliers' => [static fn (PDO $pdo): array => (new MySqlSupplierRepository($pdo))->all(), 'Unable to query suppliers.'],
            'warehouses' => [static fn (PDO $pdo): array => (new MySqlWarehouseRepository($pdo))->all(), 'Unable to query warehouses.'],
            'active warehouses' => [static fn (PDO $pdo): array => (new MySqlWarehouseRepository($pdo))->active(), 'Unable to query active warehouses.'],
            'users' => [static fn (PDO $pdo): array => (new MySqlUserRepository($pdo))->all(), 'Unable to query users.'],
        ];
    }

    #[DataProvider('failedQueries')]
    public function testFailedQueryIsReportedAsPersistenceError(callable $call, string $message): void
    {
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage($message);
        $call($this->silentQueryFailurePdo());
    }

    /** A real connection in silent error mode whose session shadows each master table with an incompatible temporary table, so PDO::query() returns false. */
    private function silentQueryFailurePdo(): PDO
    {
        $pdo = TestDatabase::connect();
        foreach (['categories', 'customers', 'suppliers', 'warehouses', 'users'] as $table) {
            $pdo->exec("CREATE TEMPORARY TABLE `{$table}` (unrelated INT)");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        return $pdo;
    }

    /** A real connection whose statements return no row for an aggregate, simulating a driver that loses the COUNT result. */
    private function emptyFetchPdo(): PDO
    {
        $pdo = TestDatabase::connect();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [EmptyCountStatement::class, []]);

        return $pdo;
    }
}

final class EmptyCountStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return str_contains($this->queryString, 'COUNT(*) AS total') ? false : parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }
}
