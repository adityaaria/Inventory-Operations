<?php

declare(strict_types=1);

namespace App\Entity;

final class PurchaseOrderItem implements PricedOrderLine
{
    /**
     * @param float|null $listPrice master purchase price snapshot (D-13, ADR-019); null means equal to the line price
     */
    public function __construct(
        private readonly int $id,
        private readonly int $purchaseOrderId,
        private readonly int $productId,
        private readonly int $quantity,
        private readonly int $receivedQuantity,
        private readonly float $purchasePrice,
        private readonly ?float $listPrice = null,
        private readonly ?string $priceReason = null,
        private readonly ?string $unitCode = null,
        private readonly int $unitFactor = 1,
    ) {
    }

    /** Unit snapshot of the line (D-16, ADR-022): code as entered; null on legacy fixtures (= the base unit). */
    public function unitCode(): ?string
    {
        return $this->unitCode;
    }

    /** Base units per entered unit, frozen when the draft was saved. quantity() is always base = unitQuantity() × factor. */
    public function unitFactor(): int
    {
        return $this->unitFactor;
    }

    /** Quantity as entered, in the line unit; the line price is per this unit. */
    public function unitQuantity(): int
    {
        return intdiv($this->quantity, max(1, $this->unitFactor));
    }

    /** Remaining quantity in whole line units (receipts are entered in the line unit). */
    public function remainingUnitQuantity(): int
    {
        return intdiv($this->remainingQuantity(), max(1, $this->unitFactor));
    }

    /** Line total in Rupiah = unit quantity × price per unit. */
    public function lineTotal(): float
    {
        return (int) round($this->purchasePrice * 100) * $this->unitQuantity() / 100;
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

    public function linePrice(): float
    {
        return $this->purchasePrice;
    }

    public function listPrice(): float
    {
        return $this->listPrice ?? $this->purchasePrice;
    }

    public function priceReason(): ?string
    {
        return $this->priceReason;
    }

    public function isPriceOverridden(): bool
    {
        return (int) round($this->purchasePrice * 100) !== (int) round($this->listPrice() * 100);
    }

    public function remainingQuantity(): int
    {
        return $this->quantity - $this->receivedQuantity;
    }

    public function withReceivedQuantity(int $receivedQuantity): self
    {
        return new self($this->id, $this->purchaseOrderId, $this->productId, $this->quantity, $receivedQuantity, $this->purchasePrice, $this->listPrice, $this->priceReason, $this->unitCode, $this->unitFactor);
    }
}
