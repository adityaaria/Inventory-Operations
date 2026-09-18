<?php

declare(strict_types=1);

namespace App\Entity;

final class SalesOrder
{
    public const STATUS_DRAFT = 'Draft';
    public const STATUS_PENDING_APPROVAL = 'PendingApproval';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_FULFILLED = 'Fulfilled';
    public const STATUS_CANCELLED = 'Cancelled';

    /**
     * @param list<SalesOrderItem> $items
     */
    public function __construct(
        private readonly int $id,
        private readonly string $orderNumber,
        private readonly int $customerId,
        private readonly int $sourceWarehouseId,
        private readonly string $status,
        private readonly string $orderDate,
        private readonly int $createdBy,
        private readonly ?int $approvedBy,
        private readonly ?string $approvedAt,
        private readonly array $items,
    ) {
    }

    public function id(): int { return $this->id; }
    public function orderNumber(): string { return $this->orderNumber; }
    public function customerId(): int { return $this->customerId; }
    public function sourceWarehouseId(): int { return $this->sourceWarehouseId; }
    public function status(): string { return $this->status; }
    public function orderDate(): string { return $this->orderDate; }
    public function createdBy(): int { return $this->createdBy; }
    public function approvedBy(): ?int { return $this->approvedBy; }
    public function approvedAt(): ?string { return $this->approvedAt; }

    /** @return list<SalesOrderItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function withStatus(string $status, ?int $approvedBy = null, ?string $approvedAt = null): self
    {
        return new self(
            $this->id,
            $this->orderNumber,
            $this->customerId,
            $this->sourceWarehouseId,
            $status,
            $this->orderDate,
            $this->createdBy,
            $approvedBy ?? $this->approvedBy,
            $approvedAt ?? $this->approvedAt,
            $this->items,
        );
    }
}
