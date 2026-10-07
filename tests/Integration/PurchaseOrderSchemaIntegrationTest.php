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
        $this->pdo = \Tests\Support\TestDatabase::connect();
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
