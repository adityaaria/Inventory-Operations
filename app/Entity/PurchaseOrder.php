<?php

declare(strict_types=1);

namespace App\Entity;

final class PurchaseOrder
{
    public const STATUS_DRAFT = 'Draft';
    public const STATUS_ORDERED = 'Ordered';
    public const STATUS_PARTIALLY_RECEIVED = 'PartiallyReceived';
    public const STATUS_RECEIVED = 'Received';
    public const STATUS_CANCELLED = 'Cancelled';

    public const RECEIVABLE_STATUSES = [
        self::STATUS_ORDERED,
        self::STATUS_PARTIALLY_RECEIVED,
    ];

    /**
     * @param list<PurchaseOrderItem> $items
     */
    public function __construct(
        private readonly int $id,
        private readonly string $orderNumber,
        private readonly int $supplierId,
        private readonly int $destinationWarehouseId,
        private readonly string $status,
        private readonly string $orderDate,
        private readonly int $createdBy,
        private readonly array $items,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function supplierId(): int
    {
        return $this->supplierId;
    }

    public function destinationWarehouseId(): int
    {
        return $this->destinationWarehouseId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function orderDate(): string
    {
        return $this->orderDate;
    }

    public function createdBy(): int
    {
        return $this->createdBy;
    }

    /** @return list<PurchaseOrderItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function isFullyReceived(): bool
    {
        foreach ($this->items as $item) {
            if ($item->remainingQuantity() > 0) {
                return false;
            }
        }

        return $this->items !== [];
    }

    public function withStatus(string $status): self
    {
        return new self($this->id, $this->orderNumber, $this->supplierId, $this->destinationWarehouseId, $status, $this->orderDate, $this->createdBy, $this->items);
    }
}
