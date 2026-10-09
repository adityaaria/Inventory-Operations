<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\Contract\StockLedgerRepositoryInterface;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlPurchaseOrderRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Security\AuthContext;
use App\Service\PurchaseOrderService;
use App\Service\StockService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PurchaseOrderReceiptIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = \Tests\Support\TestDatabase::connect();
    }

    public function testFullReceiptIncrementsStockAndWritesLedger(): void
    {
        $service = $this->service(new MySqlStockLedgerRepository($this->pdo));
        $actor = new AuthContext($this->id('users', 'email', 'admin@example.test'), 'admin@example.test', User::ROLE_ADMIN);
        $productId = $this->id('products', 'sku', 'BIS-0001');
        $warehouseId = $this->id('warehouses', 'name', 'Gudang Pusat Cikarang');
        $before = (new MySqlStockRepository($this->pdo))->quantity($productId, $warehouseId);

        $order = $service->createDraft($actor, 'PO-IT-' . uniqid(), $this->id('suppliers', 'name', 'PT Tirta Alam Sejahtera'), $warehouseId, [
            ['product_id' => $productId, 'quantity' => 4, 'purchase_price' => 12000.0],
        ]);
        $service->markOrdered($actor, $order->id());
        $service->receive($actor, $order->id(), [$order->items()[0]->id() => 4]);

        $repository = new MySqlPurchaseOrderRepository($this->pdo);
        self::assertSame($before + 4, (new MySqlStockRepository($this->pdo))->quantity($productId, $warehouseId));
        self::assertSame(PurchaseOrder::STATUS_RECEIVED, $repository->findById($order->id())?->status());
        self::assertCount(1, (new MySqlStockLedgerRepository($this->pdo))->forReference('PO', $order->id()));
    }

    public function testPartialReceiptUpdatesReceivedQuantityAndStatus(): void
    {
        $service = $this->service(new MySqlStockLedgerRepository($this->pdo));
        $actor = new AuthContext($this->id('users', 'email', 'admin@example.test'), 'admin@example.test', User::ROLE_ADMIN);
        $productId = $this->id('products', 'sku', 'MIN-0038');
        $warehouseId = $this->id('warehouses', 'name', 'Gudang Distribusi Surabaya');

        $order = $service->createDraft($actor, 'PO-IT-' . uniqid(), $this->id('suppliers', 'name', 'PT Tirta Alam Sejahtera'), $warehouseId, [
            ['product_id' => $productId, 'quantity' => 10, 'purchase_price' => 12000.0],
        ]);
        $service->markOrdered($actor, $order->id());
        $service->receive($actor, $order->id(), [$order->items()[0]->id() => 3]);

        $updated = (new MySqlPurchaseOrderRepository($this->pdo))->findById($order->id());
        self::assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $updated?->status());
        self::assertSame(7, $updated?->items()[0]->remainingQuantity());
    }

    public function testLedgerFailureRollsBackStockAndPurchaseOrderItemState(): void
    {
        $service = $this->service(new FailingStockLedgerRepository());
        $actor = new AuthContext($this->id('users', 'email', 'admin@example.test'), 'admin@example.test', User::ROLE_ADMIN);
        $productId = $this->id('products', 'sku', 'BIS-0001');
        $warehouseId = $this->id('warehouses', 'name', 'Gudang Distribusi Surabaya');
        $stock = new MySqlStockRepository($this->pdo);
        $before = $stock->quantity($productId, $warehouseId);

        $order = $service->createDraft($actor, 'PO-IT-' . uniqid(), $this->id('suppliers', 'name', 'PT Tirta Alam Sejahtera'), $warehouseId, [
            ['product_id' => $productId, 'quantity' => 5, 'purchase_price' => 12000.0],
        ]);
        $service->markOrdered($actor, $order->id());

        try {
            $service->receive($actor, $order->id(), [$order->items()[0]->id() => 2]);
            self::fail('Expected ledger failure.');
        } catch (RuntimeException) {
            $updated = (new MySqlPurchaseOrderRepository($this->pdo))->findById($order->id());
            self::assertSame($before, $stock->quantity($productId, $warehouseId));
            self::assertSame(0, $updated?->items()[0]->receivedQuantity());
            self::assertSame(PurchaseOrder::STATUS_ORDERED, $updated?->status());
        }
    }

    private function service(StockLedgerRepositoryInterface $ledger): PurchaseOrderService
    {
        $supplierId = $this->id('suppliers', 'name', 'PT Tirta Alam Sejahtera');
        $warehouseOne = $this->id('warehouses', 'name', 'Gudang Pusat Cikarang');
        $warehouseTwo = $this->id('warehouses', 'name', 'Gudang Distribusi Surabaya');

        return new PurchaseOrderService(
            new MySqlPurchaseOrderRepository($this->pdo),
            new MySqlProductRepository($this->pdo),
            [$supplierId => new Supplier($supplierId, 'PT Tirta Alam Sejahtera', 'order@tirtaalam.test', '021-8990-1120', 'Jl. Raya Narogong Km 12, Bekasi', true)],
            [
                $warehouseOne => new Warehouse($warehouseOne, 'Gudang Pusat Cikarang', 'Cikarang', true),
                $warehouseTwo => new Warehouse($warehouseTwo, 'Gudang Distribusi Surabaya', 'Surabaya', true),
            ],
            new StockService(new MySqlStockRepository($this->pdo), $ledger),
        );
    }

    private function id(string $table, string $field, string $value): int
    {
        $statement = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$field} = :value");
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();

        return is_array($row) ? (int) $row['id'] : throw new RuntimeException("Missing {$table} row.");
    }
}

final class FailingStockLedgerRepository implements StockLedgerRepositoryInterface
{
    public function appendReceipt(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        throw new RuntimeException('Forced ledger failure.');
    }

    public function appendIssue(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        throw new RuntimeException('Forced ledger failure.');
    }

    public function forReference(string $referenceType, int $referenceId): array
    {
        return [];
    }
}
