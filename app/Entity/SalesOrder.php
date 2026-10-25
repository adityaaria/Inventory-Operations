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
     * Some goods were issued, the rest is still open (or closed with a reason). User decision D-14 / ADR-020: a status
     * outside the official brief's SO status set, added deliberately so partial shipments stay visible.
     */
    public const STATUS_PARTIALLY_FULFILLED = 'PartiallyFulfilled';

    /** Statuses in which goods may be issued (while the remaining fulfilment is not closed). */
    public const ISSUABLE_STATUSES = [self::STATUS_APPROVED, self::STATUS_PARTIALLY_FULFILLED];

    /** Statuses whose unissued, unclosed lines hold a reservation (D-12/ADR-018, extended by D-14/ADR-020). */
    public const RESERVING_STATUSES = [self::STATUS_APPROVED, self::STATUS_PARTIALLY_FULFILLED];

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
        private readonly ?int $quotationId = null,
    ) {
    }

    /** The accepted quotation this order was converted from (D-19, ADR-025); null for orders entered directly. */
    public function quotationId(): ?int { return $this->quotationId; }

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

    public function issuedUnits(): int
    {
        return array_sum(array_map(static fn (SalesOrderItem $item): int => $item->issuedQuantity(), $this->items));
    }

    public function remainingUnits(): int
    {
        return array_sum(array_map(static fn (SalesOrderItem $item): int => $item->remainingQuantity(), $this->items));
    }

    public function isFullyIssued(): bool
    {
        return $this->items !== [] && $this->remainingUnits() === 0;
    }

    /** @param list<SalesOrderItem> $items */
    public function withItems(array $items): self
    {
        return new self($this->id, $this->orderNumber, $this->customerId, $this->sourceWarehouseId, $this->status, $this->orderDate, $this->createdBy, $this->approvedBy, $this->approvedAt, $items, $this->quotationId);
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
            $this->quotationId,
        );
    }
}
