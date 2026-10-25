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
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
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
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
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
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
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
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $service->receive($actor, $purchaseOrder->id(), [$purchaseOrder->items()[0]->id() => 1]);
    }

    public function testSalesCannotReceivePurchaseOrder(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
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

    public function testMarkOrderedRejectsNonDraftOrder(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);
        $service->markOrdered($actor, $purchaseOrder->id());

        $this->expectException(InvalidArgumentException::class);

        $service->markOrdered($actor, $purchaseOrder->id());
    }

    public function testReceiveRejectsNegativeQuantity(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);
        $service->markOrdered($actor, $purchaseOrder->id());

        $this->expectException(InvalidArgumentException::class);

        $service->receive($actor, $purchaseOrder->id(), [$purchaseOrder->items()[0]->id() => -1]);
    }

    public function testReceiveRejectsWhenAllQuantitiesAreZero(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);
        $service->markOrdered($actor, $purchaseOrder->id());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one receipt quantity is required.');

        $service->receive($actor, $purchaseOrder->id(), [$purchaseOrder->items()[0]->id() => 0]);
    }

    public function testMarkOrderedTransitionsDraftToOrdered(): void
    {
        [$service, $orders] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);

        $service->markOrdered($actor, $purchaseOrder->id());

        self::assertSame(PurchaseOrder::STATUS_ORDERED, $orders->findById($purchaseOrder->id())?->status());
    }

    public function testNonAdminCannotMarkOrdered(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);

        $this->expectException(HttpException::class);

        $service->markOrdered(new AuthContext(2, 'warehouse@example.test', User::ROLE_WAREHOUSE_STAFF), $purchaseOrder->id());
    }

    public function testCancelDraftOrder(): void
    {
        [$service, $orders] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);

        $service->cancel($actor, $purchaseOrder->id());

        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $orders->findById($purchaseOrder->id())?->status());
    }

    public function testCancelRejectsReceivedOrder(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $purchaseOrder = $service->createDraft($actor, 1, 1, [
            ['product_id' => 10, 'quantity' => 5, 'purchase_price' => 1000.0],
        ]);
        $service->markOrdered($actor, $purchaseOrder->id());
        $service->receive($actor, $purchaseOrder->id(), [$purchaseOrder->items()[0]->id() => 5]);

        $this->expectException(InvalidArgumentException::class);

        $service->cancel($actor, $purchaseOrder->id());
    }

    public function testWarehouseStaffCanCreateDraft(): void
    {
        [$service] = $this->service();

        $order = $service->createDraft(new AuthContext(3, 'warehouse@example.test', User::ROLE_WAREHOUSE_STAFF), 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'purchase_price' => 1000.0],
        ]);

        self::assertMatchesRegularExpression('/^PO-\d{4}-0001$/', $order->orderNumber());
    }

    public function testSalesCannotCreateDraft(): void
    {
        [$service] = $this->service();

        $this->expectException(HttpException::class);

        $service->createDraft(new AuthContext(4, 'sales@example.test', User::ROLE_SALES), 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'purchase_price' => 1000.0],
        ]);
    }

    public function testOrderNumberIsAssignedByServerAndNotConsumedByRejectedInput(): void
    {
        [$service] = $this->service();
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        try {
            $service->createDraft($admin, 999, 1, [['product_id' => 10, 'quantity' => 1]]);
            self::fail('Unknown supplier must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $first = $service->createDraft($admin, 1, 1, [['product_id' => 10, 'quantity' => 1]]);
        $second = $service->createDraft($admin, 1, 1, [['product_id' => 10, 'quantity' => 2]]);

        self::assertMatchesRegularExpression('/^PO-\d{4}-0001$/', $first->orderNumber());
        self::assertSame(substr($first->orderNumber(), 0, 8) . '0002', $second->orderNumber());
    }

    public function testRejectsUnknownSupplier(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 999, 1, [
            ['product_id' => 10, 'quantity' => 1, 'purchase_price' => 1000.0],
        ]);
    }

    public function testRejectsUnknownWarehouse(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 1, 999, [
            ['product_id' => 10, 'quantity' => 1, 'purchase_price' => 1000.0],
        ]);
    }

    public function testRejectsEmptyItemList(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 1, 1, []);
    }

    public function testRejectsUnknownProduct(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 1, 1, [
            ['product_id' => 999, 'quantity' => 1, 'purchase_price' => 1000.0],
        ]);
    }

    public function testRejectsDuplicateProductLines(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'purchase_price' => 1000.0],
            ['product_id' => 10, 'quantity' => 2, 'purchase_price' => 1000.0],
        ]);
    }

    public function testRejectsNonPositiveItemQuantity(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 1, 1, [
            ['product_id' => 10, 'quantity' => 0, 'purchase_price' => 1000.0],
        ]);
    }

    public function testWithoutSubmittedPriceTheMasterPriceIsUsedAndTamperedKeysAreIgnored(): void
    {
        [$service] = $this->service();

        // D-13 / ADR-019 (was ADR-015 "master only"): the service reads only `price`/`price_reason`; legacy
        // `purchase_price` and a client `list_price` are ignored, so the master price is stored and snapshotted.
        $order = $service->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 1, 1, [
            ['product_id' => 10, 'quantity' => 1, 'purchase_price' => -1.0, 'list_price' => -1.0],
        ]);

        self::assertSame([1000.0, 1000.0, null], [$order->items()[0]->purchasePrice(), $order->items()[0]->listPrice(), $order->items()[0]->priceReason()]);
    }

    /**
     * @return array{0: PurchaseOrderService, 1: InMemoryPurchaseOrderRepository}
     */
    public function testRepeatedOrderTransitionIsAnHttp422ValidationFailure(): void
    {
        [$service] = $this->service();
        $actor = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $order = $service->createDraft($actor, 1, 1, [['product_id'=>10,'quantity'=>1,'purchase_price'=>10.0]]);
        $service->markOrdered($actor, $order->id());
        try { $service->markOrdered($actor, $order->id()); self::fail('Transition must fail.'); }
        catch (\App\Exception\ValidationException $exception) {
            self::assertSame(422, $exception->statusCode());
            self::assertSame(422, \App\Http\ErrorResponder::browser($exception, false)->statusCode());
        }
    }

    private function service(): array
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $products = new InMemoryProductRepository([
            new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true),
        ]);

        return [
            new PurchaseOrderService(
                $orders, new \App\Repository\InMemory\InMemoryDocumentSequenceRepository(),
                $products,
                [1 => new Supplier(1, 'Demo Supplier', '', '', '', true)],
                [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)],
                new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository()),
            ),
            $orders,
        ];
    }
}
