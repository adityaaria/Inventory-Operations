<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Customer;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Security\AuthContext;
use App\Service\SalesOrderService;
use App\Service\StockService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SalesOrderIssueIntegrationTest extends TestCase
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

    public function testSalesOrderIssuePreventsOversellAndKeepsLedgerCleanOnFailure(): void
    {
        $admin = new AuthContext($this->id('users', 'email', 'admin@example.test'), 'admin@example.test', User::ROLE_ADMIN);
        $customerId = $this->id('customers', 'name', 'Demo Customer One');
        $warehouseId = $this->id('warehouses', 'name', 'Main Warehouse');
        $productId = $this->id('products', 'sku', 'SKU-DEMO-001');
        $stock = new MySqlStockRepository($this->pdo);
        $this->setStock($productId, $warehouseId, 2);
        $service = $this->service($customerId, $warehouseId);

        $first = $service->createDraft($admin, 'SO-IT-' . uniqid(), $customerId, $warehouseId, [
            ['product_id' => $productId, 'quantity' => 2, 'selling_price' => 30000.0],
        ]);
        $second = $service->createDraft($admin, 'SO-IT-' . uniqid(), $customerId, $warehouseId, [
            ['product_id' => $productId, 'quantity' => 1, 'selling_price' => 30000.0],
        ]);

        $service->submit($admin, $first->id());
        $service->approve($admin, $first->id());
        $service->issue($admin, $first->id());
        self::assertSame(0, $stock->quantity($productId, $warehouseId));

        $service->submit($admin, $second->id());
        $service->approve($admin, $second->id());
        $beforeLedger = count((new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $second->id()));

        $this->expectException(InvalidArgumentException::class);

        try {
            $service->issue($admin, $second->id());
        } finally {
            self::assertSame(0, $stock->quantity($productId, $warehouseId));
            self::assertSame($beforeLedger, count((new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $second->id())));
        }
    }

    private function service(int $customerId, int $warehouseId): SalesOrderService
    {
        return new SalesOrderService(
            new MySqlSalesOrderRepository($this->pdo),
            new MySqlProductRepository($this->pdo),
            [$customerId => new Customer($customerId, 'Demo Customer One', 'customer1@example.test', '022-0001', 'Jl. Customer Raya 1, Jakarta', true)],
            [$warehouseId => new Warehouse($warehouseId, 'Main Warehouse', 'Jakarta', true)],
            new StockService(new MySqlStockRepository($this->pdo), new MySqlStockLedgerRepository($this->pdo)),
        );
    }

    private function setStock(int $productId, int $warehouseId, int $quantity): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO product_stocks (product_id, warehouse_id, quantity)
             VALUES (:product_id, :warehouse_id, :quantity)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity]);
    }

    private function id(string $table, string $field, string $value): int
    {
        $statement = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$field} = :value");
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();

        return is_array($row) ? (int) $row['id'] : throw new \RuntimeException("Missing {$table} row.");
    }
}
