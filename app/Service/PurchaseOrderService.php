<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\PurchaseOrderRepositoryInterface;
use App\Security\AuthContext;
use App\Exception\ValidationException;

final class PurchaseOrderService
{
    /**
     * @param array<int, Supplier> $suppliers
     * @param array<int, Warehouse> $warehouses
     */
    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $orders,
        private readonly ProductRepositoryInterface $products,
        private readonly array $suppliers,
        private readonly array $warehouses,
        private readonly StockService $stockService,
        private readonly ?OperationIdempotency $idempotency = null,
        private readonly ?\App\Repository\Contract\OrderExceptionRepositoryInterface $exceptions = null,
    ) {
    }

    /**
     * @param list<array{product_id: int, quantity: int, purchase_price: float}> $items
     */
    public function createDraft(AuthContext $actor, string $orderNumber, int $supplierId, int $warehouseId, array $items): PurchaseOrder
    {
        $this->assertCanCreate($actor);
        $this->assertHeader($orderNumber, $supplierId, $warehouseId);
        $this->assertItems($items);

        return $this->orders->createDraft(trim($orderNumber), $supplierId, $warehouseId, $actor->userId(), $items);
    }

    public function markOrdered(AuthContext $actor, int $id): void
    {
        $this->stockService->transaction(function () use ($actor, $id): void {
            $this->assertAdmin($actor);
            $order = $this->findOrder($id);
            if ($order->status() !== PurchaseOrder::STATUS_DRAFT) {
                throw new ValidationException('Only Draft purchase orders can be ordered.');
            }

            $this->orders->markOrdered($id);

        });
    }

    /**
     * @param array<int, int> $receivedQuantitiesByItemId
     */
    public function receive(AuthContext $actor, int $id, array $receivedQuantitiesByItemId, ?string $requestKey = null): void
    {
        $this->stockService->transaction(function () use ($actor, $id, $receivedQuantitiesByItemId, $requestKey): void {
            $this->assertCanReceive($actor);
            $order = $this->findOrder($id);
            if ($requestKey !== null) {
                if ($this->idempotency === null) { throw new \LogicException('Idempotency repository is required.'); }
                if ($this->idempotency->replay($actor->userId(), $requestKey, 'receipt', $id, $receivedQuantitiesByItemId)) { return; }
            }
            if ($this->exceptions?->closure($id)!==null) { throw new ValidationException('This PO remainder is closed.'); }
            if (!in_array($order->status(), PurchaseOrder::RECEIVABLE_STATUSES, true)) {
                throw new ValidationException('Purchase order is not receivable.');
            }

            $acceptedReceipts = [];
            $movements = [];
            foreach ($order->items() as $item) {
                $quantity = $receivedQuantitiesByItemId[$item->id()] ?? 0;
                if ($quantity === 0) {
                    continue;
                }
                if ($quantity < 0) {
                    throw new ValidationException('Receipt quantity must be positive.');
                }
                if ($quantity > $item->remainingQuantity()) {
                    throw new ValidationException('Receipt quantity cannot exceed remaining quantity.');
                }
                $acceptedReceipts[$item->id()] = $quantity;
                $movements[] = new StockMovement($item->productId(), $quantity);
            }

            if ($acceptedReceipts === []) {
                throw new ValidationException('At least one receipt quantity is required.');
            }

            $status = $this->statusAfterReceipt($order, $acceptedReceipts);
            $this->stockService->receive(
                $order->destinationWarehouseId(),
                $movements,
                $actor->userId(),
                'PO',
                $order->id(),
                fn (): null => $this->recordReceipt($order->id(), $acceptedReceipts, $status),
            );
            if ($requestKey !== null) { $this->idempotency->complete($actor->userId(), $requestKey); }

        });
    }

    public function cancel(AuthContext $actor, int $id): void
    {
        $this->stockService->transaction(function () use ($actor, $id): void {
            $this->assertAdmin($actor);
            $order = $this->findOrder($id);
            if ($order->status() !== PurchaseOrder::STATUS_DRAFT && $order->status() !== PurchaseOrder::STATUS_ORDERED) {
                throw new ValidationException('Only Draft or Ordered purchase orders can be cancelled.');
            }

            $this->orders->cancel($id);

        });
    }

    private function assertCanCreate(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN && $actor->role() !== User::ROLE_WAREHOUSE_STAFF) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertCanReceive(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN && $actor->role() !== User::ROLE_WAREHOUSE_STAFF) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertAdmin(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertHeader(string $orderNumber, int $supplierId, int $warehouseId): void
    {
        \App\Validation\InputValidator::requiredString('order_number', $orderNumber, 50);
        if (trim($orderNumber) === '') {
            throw new ValidationException('Order number is required.');
        }
        $supplier = $this->suppliers[$supplierId] ?? null;
        if (!$supplier instanceof Supplier || !$supplier->isActive()) {
            throw new ValidationException('Active supplier is required.');
        }
        $warehouse = $this->warehouses[$warehouseId] ?? null;
        if (!$warehouse instanceof Warehouse || !$warehouse->isActive()) {
            throw new ValidationException('Active warehouse is required.');
        }
    }

    /**
     * @param list<array{product_id: int, quantity: int, purchase_price: float}> $items
     */
    private function assertItems(array $items): void
    {
        if ($items === []) {
            throw new ValidationException('At least one item is required.');
        }

        $seenProducts = [];
        foreach ($items as $item) {
            $product = $this->products->findById($item['product_id']);
            if (!$product instanceof Product || !$product->isActive()) {
                throw new ValidationException('Active product is required.');
            }
            if (isset($seenProducts[$item['product_id']])) {
                throw new ValidationException('Duplicate product lines are not allowed.');
            }
            if ($item['quantity'] <= 0) {
                throw new ValidationException('Quantity must be positive.');
            }
            \App\Validation\InputValidator::nonNegativeMoney('purchase_price', $item['purchase_price']);
            if ($item['purchase_price'] < 0) {
                throw new ValidationException('Purchase price cannot be negative.');
            }
            $seenProducts[$item['product_id']] = true;
        }
    }

    private function findOrder(int $id): PurchaseOrder
    {
        return $this->orders->lockById($id) ?? throw new ValidationException('Purchase order not found.');
    }

    /**
     * @param array<int, int> $receivedQuantitiesByItemId
     */
    private function recordReceipt(int $orderId, array $receivedQuantitiesByItemId, string $status): null
    {
        $this->orders->recordReceipt($orderId, $receivedQuantitiesByItemId, $status);

        return null;
    }

    /**
     * @param array<int, int> $receivedQuantitiesByItemId
     */
    private function statusAfterReceipt(PurchaseOrder $order, array $receivedQuantitiesByItemId): string
    {
        foreach ($order->items() as $item) {
            $received = $receivedQuantitiesByItemId[$item->id()] ?? 0;
            if ($item->remainingQuantity() - $received > 0) {
                return PurchaseOrder::STATUS_PARTIALLY_RECEIVED;
            }
        }

        return PurchaseOrder::STATUS_RECEIVED;
    }
}
