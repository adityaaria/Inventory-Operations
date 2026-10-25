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
        $this->pdo = \Tests\Support\TestDatabase::connect();
    }

    public function testSalesOrderIssuePreventsOversellAndKeepsLedgerCleanOnFailure(): void
    {
        $admin = new AuthContext($this->id('users', 'email', 'admin@example.test'), 'admin@example.test', User::ROLE_ADMIN);
        $customerId = $this->id('customers', 'name', 'Minimarket Lancar Jaya Bekasi');
        $warehouseId = $this->id('warehouses', 'name', 'Gudang Pusat Cikarang');
        // A fresh product with 2 units received through StockService: since D-17 (ADR-023) stock lives in lots, so the
        // fixture no longer writes product_stocks directly (a seed product also carries reservations now).
        $stock = new MySqlStockRepository($this->pdo);
        $stocks = new StockService($stock, new MySqlStockLedgerRepository($this->pdo), null, new \App\Repository\MySql\MySqlStockCatalogRepository($this->pdo), new \App\Repository\MySql\MySqlTransactionManager($this->pdo));
        $productId = (new MySqlProductRepository($this->pdo))->create(new \App\Support\ProductInput('ISSUE-' . bin2hex(random_bytes(4)), 'Oversell fixture', 'pcs', 1000.0, 30000.0, 1, 1), true)->id();
        $stocks->initializeProduct($productId);
        $stocks->receive($warehouseId, [new \App\Service\StockMovement($productId, 2)], $admin->userId(), 'TEST', $productId);
        $service = $this->service($customerId, $warehouseId);

        $first = $service->createDraft($admin, $customerId, $warehouseId, [
            ['product_id' => $productId, 'quantity' => 2, 'selling_price' => 30000.0],
        ]);
        $second = $service->createDraft($admin, $customerId, $warehouseId, [
            ['product_id' => $productId, 'quantity' => 1, 'selling_price' => 30000.0],
        ]);

        $service->submit($admin, $first->id());
        $service->approve($admin, $first->id());
        $service->issue($admin, $first->id());
        self::assertSame(0, $stock->quantity($productId, $warehouseId));

        // Since D-12 the oversell guard fires at approval: the second order cannot reserve, so nothing changes.
        $service->submit($admin, $second->id());
        $beforeLedger = count((new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $second->id()));
        $reservedBefore = $stock->reservedQuantity($productId, $warehouseId);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient available stock');

        try {
            $service->approve($admin, $second->id());
        } finally {
            self::assertSame(0, $stock->quantity($productId, $warehouseId));
            self::assertSame($reservedBefore, $stock->reservedQuantity($productId, $warehouseId));
            self::assertSame('PendingApproval', (new MySqlSalesOrderRepository($this->pdo))->findById($second->id())?->status());
            self::assertSame($beforeLedger, count((new MySqlStockLedgerRepository($this->pdo))->forReference('SO', $second->id())));
        }
    }

    private function service(int $customerId, int $warehouseId): SalesOrderService
    {
        return new SalesOrderService(
            new MySqlSalesOrderRepository($this->pdo), new \App\Repository\MySql\MySqlDocumentSequenceRepository($this->pdo),
            new MySqlProductRepository($this->pdo),
            [$customerId => new Customer($customerId, 'Minimarket Lancar Jaya Bekasi', 'customer1@example.test', '022-0001', 'Jl. Customer Raya 1, Jakarta', true)],
            [$warehouseId => new Warehouse($warehouseId, 'Gudang Pusat Cikarang', 'Cikarang', true)],
            new StockService(new MySqlStockRepository($this->pdo), new MySqlStockLedgerRepository($this->pdo)),
        );
    }

    private function id(string $table, string $field, string $value): int
    {
        $statement = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$field} = :value");
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();

        return is_array($row) ? (int) $row['id'] : throw new \RuntimeException("Missing {$table} row.");
    }
}
