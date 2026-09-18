<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderSchemaIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_DATABASE') ?: 'inventory_order_management',
            ),
            getenv('DB_USERNAME') ?: 'inventory_app',
            getenv('DB_PASSWORD') ?: 'change_me_for_local_only',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    public function testPurchaseOrderAndLedgerTablesExistWithConstraints(): void
    {
        foreach (['purchase_orders', 'purchase_order_items', 'stock_ledger'] as $table) {
            $statement = $this->pdo->query("SHOW TABLES LIKE '{$table}'");
            self::assertNotFalse($statement);
            self::assertNotSame([], $statement->fetchAll(), "{$table} table is missing.");
        }

        $orderIndex = $this->pdo->query("SHOW INDEX FROM purchase_orders WHERE Key_name = 'uq_purchase_orders_order_number'");
        self::assertNotFalse($orderIndex);
        self::assertNotSame([], $orderIndex->fetchAll());

        $ledgerIndex = $this->pdo->query("SHOW INDEX FROM stock_ledger WHERE Key_name = 'idx_stock_ledger_reference'");
        self::assertNotFalse($ledgerIndex);
        self::assertNotSame([], $ledgerIndex->fetchAll());
    }
}
