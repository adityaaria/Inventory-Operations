<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Exception\EntityNotFoundException;
use App\Repository\Contract\SalesOrderRepositoryInterface;
use App\Support\OrderSearchCriteria;
use App\Support\PaginatedResult;
use InvalidArgumentException;

final class InMemorySalesOrderRepository implements SalesOrderRepositoryInterface
{
    /** @var array<int, SalesOrder> */
    private array $orders = [];
    private int $nextOrderId = 1;
    private int $nextItemId = 1;

    public function all(): array
    {
        return array_values($this->orders);
    }

    public function search(OrderSearchCriteria $criteria, ?int $createdBy = null): PaginatedResult
    {
        $items = array_values(array_filter($this->orders, static function (SalesOrder $order) use ($criteria, $createdBy): bool {
            if ($createdBy !== null && $order->createdBy() !== $createdBy) {
                return false;
            }
            if ($criteria->term() !== '' && stripos($order->orderNumber(), $criteria->term()) === false) {
                return false;
            }

            return $criteria->status() === null || $order->status() === $criteria->status();
        }));

        return new PaginatedResult(array_slice($items, $criteria->offset(), $criteria->perPage()), count($items), $criteria->page(), $criteria->perPage());
    }

    public function forCreator(int $createdBy): array
    {
        return array_values(array_filter($this->orders, static fn (SalesOrder $order): bool => $order->createdBy() === $createdBy));
    }

    public function findById(int $id): ?SalesOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function lockById(int $id): ?SalesOrder
    {
        return $this->findById($id);
    }

    public function createDraft(string $orderNumber, int $customerId, int $warehouseId, int $createdBy, array $items, ?int $quotationId = null): SalesOrder
    {
        $this->assertUniqueProducts($items);
        foreach ($this->orders as $existing) {
            if ($quotationId !== null && $existing->quotationId() === $quotationId) {
                throw new InvalidArgumentException('A record with that unique value already exists.');
            }
        }
        $orderId = $this->nextOrderId++;
        $entityItems = [];
        foreach ($items as $item) {
            $entityItems[] = new SalesOrderItem($this->nextItemId++, $orderId, $item['product_id'], $item['quantity'], $item['selling_price'], $item['list_price'] ?? $item['selling_price'], $item['price_reason'] ?? null, 0, $item['unit_code'] ?? null, $item['unit_factor'] ?? 1, $item['price_source'] ?? SalesOrderItem::SOURCE_MASTER, $item['price_list_id'] ?? null, $item['quotation_item_id'] ?? null);
        }

        $order = new SalesOrder($orderId, $orderNumber, $customerId, $warehouseId, SalesOrder::STATUS_DRAFT, date('Y-m-d'), $createdBy, null, null, $entityItems, $quotationId);
        $this->orders[$orderId] = $order;

        return $order;
    }

    public function submit(int $id): void
    {
        $order = $this->findRequired($id);
        if ($order->status() !== SalesOrder::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only Draft sales orders can be submitted.');
        }
        $this->orders[$id] = $order->withStatus(SalesOrder::STATUS_PENDING_APPROVAL);
    }

    public function approve(int $id, int $approvedBy): void
    {
        $order = $this->findRequired($id);
        if ($order->status() !== SalesOrder::STATUS_PENDING_APPROVAL) {
            throw new InvalidArgumentException('Only PendingApproval sales orders can be approved.');
        }
        $this->orders[$id] = $order->withStatus(SalesOrder::STATUS_APPROVED, $approvedBy, date('Y-m-d H:i:s'));
    }

    public function cancel(int $id): void
    {
        $order = $this->findRequired($id);
        if ($order->status() === SalesOrder::STATUS_FULFILLED) {
            throw new InvalidArgumentException('Fulfilled sales orders cannot be cancelled.');
        }
        if ($order->status() === SalesOrder::STATUS_PARTIALLY_FULFILLED) {
            throw new InvalidArgumentException('Partially fulfilled sales orders cannot be cancelled.');
        }
        $this->orders[$id] = $order->withStatus(SalesOrder::STATUS_CANCELLED);
    }

    public function markFulfilled(int $id): void
    {
        $order = $this->findRequired($id);
        $items = array_map(static fn (SalesOrderItem $item): SalesOrderItem => $item->withIssuedQuantity($item->quantity()), $order->items());
        $this->orders[$id] = $order->withItems($items)->withStatus(SalesOrder::STATUS_FULFILLED);
    }

    public function recordIssue(int $id, array $issuedQuantitiesByItemId, string $status): void
    {
        $order = $this->findRequired($id);
        if (!in_array($order->status(), SalesOrder::ISSUABLE_STATUSES, true)) {
            throw new InvalidArgumentException('Only Approved or PartiallyFulfilled sales orders can record an issue.');
        }
        $known = [];
        $items = [];
        foreach ($order->items() as $item) {
            $quantity = $issuedQuantitiesByItemId[$item->id()] ?? 0;
            if ($item->issuedQuantity() + $quantity > $item->quantity()) {
                throw new InvalidArgumentException('Issued quantity cannot exceed the ordered quantity.');
            }
            $known[$item->id()] = true;
            $items[] = $item->withIssuedQuantity($item->issuedQuantity() + $quantity);
        }
        if (array_diff_key($issuedQuantitiesByItemId, $known) !== []) {
            throw new InvalidArgumentException('Sales order line could not record the issued quantity.');
        }
        $this->orders[$id] = $order->withItems($items)->withStatus($status);
    }

    private function findRequired(int $id): SalesOrder
    {
        return $this->findById($id) ?? throw new EntityNotFoundException("Sales order not found: {$id}");
    }

    /**
     * @param list<array{product_id: int, quantity: int, selling_price: float}> $items
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
