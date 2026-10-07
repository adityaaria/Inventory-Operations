<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlPurchaseOrderRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Support\OrderSearchCriteria;
use App\Support\ProductSearchCriteria;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Header sorting replaces the former "Order" filter; every sortable column must sort the full filtered result. */
final class ListSortingIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        \Tests\Support\TestDatabase::reset();
        $this->pdo = \Tests\Support\TestDatabase::connect();
        // Seed orders share one supplier, customer and warehouse; add extremes so each sort has something to order.
        $this->pdo->exec("INSERT INTO suppliers (name) VALUES ('Aardvark Supplies'), ('Zephyr Trading')");
        $this->pdo->exec("INSERT INTO customers (name) VALUES ('Abbot Retail'), ('Zenith Stores')");
        $warehouse = (int) $this->pdo->query('SELECT MAX(id) FROM warehouses')->fetchColumn();
        $po = $this->pdo->prepare("INSERT INTO purchase_orders (order_number, supplier_id, destination_warehouse_id, status, order_date, created_by) SELECT :number, id, :warehouse, 'Draft', CURRENT_DATE, 1 FROM suppliers WHERE name = :supplier");
        $so = $this->pdo->prepare("INSERT INTO sales_orders (order_number, customer_id, source_warehouse_id, status, order_date, created_by) SELECT :number, id, :warehouse, 'Draft', CURRENT_DATE, 1 FROM customers WHERE name = :customer");
        foreach (['Aardvark Supplies', 'Zephyr Trading'] as $index => $supplier) $po->execute(['number' => 'PO-SORT-' . $index, 'warehouse' => $warehouse, 'supplier' => $supplier]);
        foreach (['Abbot Retail', 'Zenith Stores'] as $index => $customer) $so->execute(['number' => 'SO-SORT-' . $index, 'warehouse' => $warehouse, 'customer' => $customer]);
    }

    protected function tearDown(): void
    {
        \Tests\Support\TestDatabase::reset();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function orderSorts(): array
    {
        return [
            'PO by supplier' => ['purchase', 'party', 'SELECT s.name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id'],
            'PO by warehouse' => ['purchase', 'warehouse', 'SELECT w.name FROM purchase_orders po JOIN warehouses w ON w.id = po.destination_warehouse_id'],
            'SO by customer' => ['sales', 'party', 'SELECT c.name FROM sales_orders so JOIN customers c ON c.id = so.customer_id'],
            'SO by warehouse' => ['sales', 'warehouse', 'SELECT w.name FROM sales_orders so JOIN warehouses w ON w.id = so.source_warehouse_id'],
        ];
    }

    #[DataProvider('orderSorts')]
    public function testOrderListsSortByPartyAndWarehouseInBothDirections(string $kind, string $sort, string $valuesSql): void
    {
        $values = $this->pdo->query($valuesSql)->fetchAll(PDO::FETCH_COLUMN);
        self::assertGreaterThan(1, count(array_unique($values)), 'Seed must contain several distinct values.');
        foreach (['asc', 'desc'] as $direction) {
            $criteria = OrderSearchCriteria::fromArray(['sort' => $sort, 'direction' => $direction, 'per_page' => '10']);
            self::assertSame($sort, $criteria->sortBy());
            $repository = $kind === 'purchase' ? new MySqlPurchaseOrderRepository($this->pdo) : new MySqlSalesOrderRepository($this->pdo);
            $names = array_map(fn ($order): string => $this->displayName($kind, $sort, $order), $repository->search($criteria)->items());
            $expected = $names;
            usort($expected, static fn (string $a, string $b): int => $direction === 'asc' ? strcasecmp($a, $b) : strcasecmp($b, $a));
            self::assertSame($expected, $names, "$kind $sort $direction");
            $sortedAll = $values;
            usort($sortedAll, static fn (string $a, string $b): int => $direction === 'asc' ? strcasecmp($a, $b) : strcasecmp($b, $a));
            self::assertSame(strtolower($sortedAll[0]), strtolower($names[0]), 'First page starts at the extreme of the whole result.');
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function productSorts(): array
    {
        return ['unit' => ['unit', 'unit'], 'purchase price' => ['purchase_price', 'purchase_price'], 'reorder point' => ['reorder', 'reorder_point']];
    }

    #[DataProvider('productSorts')]
    public function testProductListSortsByNewColumns(string $sort, string $column): void
    {
        foreach (['asc', 'desc'] as $direction) {
            $criteria = ProductSearchCriteria::fromArray(['sort' => $sort, 'direction' => $direction]);
            self::assertSame($sort, $criteria->sortBy());
            $values = array_map(static fn ($product): string|float|int => match ($column) {
                'unit' => strtolower($product->unit()),
                'purchase_price' => $product->purchasePrice(),
                default => $product->reorderPoint(),
            }, (new MySqlProductRepository($this->pdo))->search($criteria)->items());
            $expected = $values;
            $direction === 'asc' ? sort($expected) : rsort($expected);
            self::assertSame($expected, $values, "$sort $direction");
        }
    }

    public function testUnknownSortKeysFallBackToDefaults(): void
    {
        self::assertSame('order_date', OrderSearchCriteria::fromArray(['sort' => 's.name; DROP TABLE x'])->sortBy());
        self::assertSame('name', ProductSearchCriteria::fromArray(['sort' => 'p.unit'])->sortBy());
    }

    private function displayName(string $kind, string $sort, object $order): string
    {
        $sql = match ([$kind, $sort]) {
            ['purchase', 'party'] => 'SELECT name FROM suppliers WHERE id = :id',
            ['sales', 'party'] => 'SELECT name FROM customers WHERE id = :id',
            default => 'SELECT name FROM warehouses WHERE id = :id',
        };
        $id = match ([$kind, $sort]) {
            ['purchase', 'party'] => $order->supplierId(),
            ['sales', 'party'] => $order->customerId(),
            ['purchase', 'warehouse'] => $order->destinationWarehouseId(),
            default => $order->sourceWarehouseId(),
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);

        return (string) $statement->fetchColumn();
    }
}
