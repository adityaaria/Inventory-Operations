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
     * Line prices always come from the product master (purchase_price); any price sent by the client is ignored.
     *
     * @param list<array{product_id: int, quantity: int}> $items
     */
    public function createDraft(AuthContext $actor, string $orderNumber, int $supplierId, int $warehouseId, array $items): PurchaseOrder
    {
        $this->assertCanCreate($actor);
        $this->assertHeader($orderNumber, $supplierId, $warehouseId);
        $lines = $this->pricedItems($items);

        return $this->orders->createDraft(trim($orderNumber), $supplierId, $warehouseId, $actor->userId(), $lines);
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
            if ($this->isReplayedReceipt($actor, $id, $receivedQuantitiesByItemId, $requestKey)) { return; }
            if ($this->exceptions?->closure($id)!==null) { throw new ValidationException('This PO remainder is closed.'); }
            if (!in_array($order->status(), PurchaseOrder::RECEIVABLE_STATUSES, true)) {
                throw new ValidationException('Purchase order is not receivable.');
            }

            [$acceptedReceipts, $movements] = $this->acceptedReceipts($order, $receivedQuantitiesByItemId);
            $status = $this->statusAfterReceipt($order, $acceptedReceipts);
            $this->stockService->receive(
                $order->destinationWarehouseId(),
                $movements,
                $actor->userId(),
                'PO',
                $order->id(),
                fn (): null => $this->recordReceipt($order->id(), $acceptedReceipts, $status),
            );
            if ($requestKey !== null) { $this->idempotency?->complete($actor->userId(), $requestKey); }

        });
    }

    /**
     * A repeated request key with the same payload is a completed replay; a different payload is rejected by the repository.
     *
     * @param array<int, int> $receivedQuantitiesByItemId
     */
    private function isReplayedReceipt(AuthContext $actor, int $id, array $receivedQuantitiesByItemId, ?string $requestKey): bool
    {
        if ($requestKey === null) {
            return false;
        }
        if ($this->idempotency === null) { throw new \LogicException('Idempotency repository is required.'); }

        return $this->idempotency->replay($actor->userId(), $requestKey, 'receipt', $id, $receivedQuantitiesByItemId);
    }

    /**
     * @param array<int, int> $receivedQuantitiesByItemId
     * @return array{0: array<int, int>, 1: list<StockMovement>} accepted quantity per item id and the matching stock movements
     */
    private function acceptedReceipts(PurchaseOrder $order, array $receivedQuantitiesByItemId): array
    {
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

        return [$acceptedReceipts, $movements];
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
     * Validates the lines and prices each one from the active product's master purchase_price.
     *
     * @param list<array{product_id: int, quantity: int}> $items
     * @return list<array{product_id: int, quantity: int, purchase_price: float}>
     */
    private function pricedItems(array $items): array
    {
        if ($items === []) {
            throw new ValidationException('At least one item is required.');
        }

        $seenProducts = [];
        $lines = [];
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
            $seenProducts[$item['product_id']] = true;
            $lines[] = ['product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'purchase_price' => $product->purchasePrice()];
        }

        return $lines;
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
