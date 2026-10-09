<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Exception\EntityNotFoundException;
use App\Repository\Contract\PurchaseOrderRepositoryInterface;
use App\Support\OrderSearchCriteria;
use App\Support\PaginatedResult;
use InvalidArgumentException;

final class InMemoryPurchaseOrderRepository implements PurchaseOrderRepositoryInterface
{
    /** @var array<int, PurchaseOrder> */
    private array $orders = [];
    private int $nextOrderId = 1;
    private int $nextItemId = 1;

    public function all(): array
    {
        return array_values($this->orders);
    }

    public function search(OrderSearchCriteria $criteria): PaginatedResult
    {
        $items = array_values(array_filter($this->orders, static function (PurchaseOrder $order) use ($criteria): bool {
            if ($criteria->term() !== '' && stripos($order->orderNumber(), $criteria->term()) === false) {
                return false;
            }

            return $criteria->status() === null || $order->status() === $criteria->status();
        }));

        return new PaginatedResult(array_slice($items, $criteria->offset(), $criteria->perPage()), count($items), $criteria->page(), $criteria->perPage());
    }

    public function findById(int $id): ?PurchaseOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function lockById(int $id): ?PurchaseOrder
    {
        return $this->findById($id);
    }

    public function createDraft(string $orderNumber, int $supplierId, int $warehouseId, int $createdBy, array $items): PurchaseOrder
    {
        $this->assertUniqueProducts($items);
        $orderId = $this->nextOrderId++;
        $entityItems = [];
        foreach ($items as $item) {
            $entityItems[] = new PurchaseOrderItem(
                $this->nextItemId++,
                $orderId,
                $item['product_id'],
                $item['quantity'],
                0,
                $item['purchase_price'],
            );
        }

        $order = new PurchaseOrder($orderId, $orderNumber, $supplierId, $warehouseId, PurchaseOrder::STATUS_DRAFT, date('Y-m-d'), $createdBy, $entityItems);
        $this->orders[$orderId] = $order;

        return $order;
    }

    public function markOrdered(int $id): void
    {
        $this->orders[$id] = $this->findRequired($id)->withStatus(PurchaseOrder::STATUS_ORDERED);
    }

    public function recordReceipt(int $id, array $receivedQuantitiesByItemId, string $status): void
    {
        $order = $this->findRequired($id);
        $items = [];
        foreach ($order->items() as $item) {
            $received = $receivedQuantitiesByItemId[$item->id()] ?? 0;
            $items[] = $item->withReceivedQuantity($item->receivedQuantity() + $received);
        }

        $this->orders[$id] = new PurchaseOrder(
            $order->id(),
            $order->orderNumber(),
            $order->supplierId(),
            $order->destinationWarehouseId(),
            $status,
            $order->orderDate(),
            $order->createdBy(),
            $items,
        );
    }

    public function cancel(int $id): void
    {
        $this->orders[$id] = $this->findRequired($id)->withStatus(PurchaseOrder::STATUS_CANCELLED);
    }

    private function findRequired(int $id): PurchaseOrder
    {
        return $this->findById($id) ?? throw new EntityNotFoundException("Purchase order not found: {$id}");
    }

    /**
     * @param list<array{product_id: int, quantity: int, purchase_price: float}> $items
     */
    private function assertUniqueProducts(array $items): void
    {
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item['product_id']])) {
                throw new InvalidArgumentException('Duplicate product lines are not allowed.');
            }
            $seen[$item['product_id']] = true;
        }
    }
}
