<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class DemoSeedIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = \Tests\Support\TestDatabase::connect();
    }

    public function testDemoSeedHasRequiredVolumeAndStatuses(): void
    {
        self::assertGreaterThanOrEqual(30, $this->rowCount('products'));
        self::assertGreaterThanOrEqual(25, $this->rowCount('purchase_orders') + $this->rowCount('sales_orders'));
        self::assertGreaterThanOrEqual(1, $this->countWhere('sales_orders', 'status', 'PendingApproval'));
        self::assertGreaterThanOrEqual(1, $this->countWhere('sales_orders', 'status', 'Cancelled'));
    }

    public function testSeedHasAStockRowForEveryProductWarehousePair(): void
    {
        self::assertSame($this->rowCount('products') * $this->rowCount('warehouses'), $this->rowCount('product_stocks'));
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) AS total FROM {$table}")->fetch()['total'];
    }

    private function countWhere(string $table, string $column, string $value): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) AS total FROM {$table} WHERE {$column} = :value");
        $statement->execute(['value' => $value]);

        return (int) $statement->fetch()['total'];
    }
}
