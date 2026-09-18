<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Service\PurchaseOrderService;
use App\Service\StockService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderServiceTest extends TestCase
{
    public function testPartialReceiptUpdatesReceivedQuantityAndStatus(): void
    {
        [$service, $orders] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 'PO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);
        $service->markOrdered($actor, $purchaseOrder->id());

        $service->receive($actor, $purchaseOrder->id(), [$purchaseOrder->items()[0]->id() => 2]);

        $updated = $orders->findById($purchaseOrder->id());
        self::assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $updated?->status());
        self::assertSame(3, $updated?->items()[0]->remainingQuantity());
    }

    public function testFullReceiptMarksOrderReceived(): void
    {
        [$service, $orders] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 'PO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);
        $service->markOrdered($actor, $purchaseOrder->id());

        $service->receive($actor, $purchaseOrder->id(), [$purchaseOrder->items()[0]->id() => 5]);

        self::assertSame(PurchaseOrder::STATUS_RECEIVED, $orders->findById($purchaseOrder->id())?->status());
    }

    public function testRejectsReceiptAboveRemaining(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 'PO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);
        $service->markOrdered($actor, $purchaseOrder->id());

        $this->expectException(InvalidArgumentException::class);

        $service->receive($actor, $purchaseOrder->id(), [$purchaseOrder->items()[0]->id() => 6]);
    }

    public function testRejectsReceiptFromDraft(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 'PO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $service->receive($actor, $purchaseOrder->id(), [$purchaseOrder->items()[0]->id() => 1]);
    }

    public function testSalesCannotReceivePurchaseOrder(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 'PO-001', 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);
        $service->markOrdered($actor, $purchaseOrder->id());

        $this->expectException(HttpException::class);

        $service->receive(
            new AuthContext(2, 'sales@example.test', User::ROLE_SALES),
            $purchaseOrder->id(),
            [$purchaseOrder->items()[0]->id() => 1],
        );
    }

    /**
     * @return array{0: PurchaseOrderService, 1: InMemoryPurchaseOrderRepository}
     */
    private function service(): array
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $products = new InMemoryProductRepository([
            new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
        ]);

        return [
            new PurchaseOrderService(
                $orders,
                $products,
                [1 => new Supplier(1, 'Demo Supplier', '', '', '', true)],
                [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)],
                new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository()),
            ),
            $orders,
        ];
    }
}
