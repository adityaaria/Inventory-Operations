<?php

declare(strict_types=1);

namespace App\Entity;

final class PurchaseOrderItem
{
    public function __construct(
        private readonly int $id,
        private readonly int $purchaseOrderId,
        private readonly int $productId,
        private readonly int $quantity,
        private readonly int $receivedQuantity,
        private readonly float $purchasePrice,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function purchaseOrderId(): int
    {
        return $this->purchaseOrderId;
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function receivedQuantity(): int
    {
        return $this->receivedQuantity;
    }

    public function purchasePrice(): float
    {
        return $this->purchasePrice;
    }

    public function remainingQuantity(): int
    {
        return $this->quantity - $this->receivedQuantity;
    }

    public function withReceivedQuantity(int $receivedQuantity): self
    {
        return new self($this->id, $this->purchaseOrderId, $this->productId, $this->quantity, $receivedQuantity, $this->purchasePrice);
    }
}
