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
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE') ?: 'inventory_order_management'),
            getenv('DB_USERNAME') ?: 'inventory_app',
            getenv('DB_PASSWORD') ?: 'change_me_for_local_only',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    public function testDemoSeedHasRequiredVolumeAndStatuses(): void
    {
        self::assertGreaterThanOrEqual(30, $this->rowCount('products'));
        self::assertGreaterThanOrEqual(25, $this->rowCount('purchase_orders') + $this->rowCount('sales_orders'));
        self::assertGreaterThanOrEqual(1, $this->countWhere('sales_orders', 'status', 'PendingApproval'));
        self::assertGreaterThanOrEqual(1, $this->countWhere('sales_orders', 'status', 'Cancelled'));
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
