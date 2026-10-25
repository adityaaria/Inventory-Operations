<?php

declare(strict_types=1);

namespace App\Entity;

final class SalesOrderItem implements PricedOrderLine
{
    /**
     * @param float|null $listPrice master selling price snapshot (D-13, ADR-019); null means equal to the line price
     */
    public function __construct(
        private readonly int $id,
        private readonly int $salesOrderId,
        private readonly int $productId,
        private readonly int $quantity,
        private readonly float $sellingPrice,
        private readonly ?float $listPrice = null,
        private readonly ?string $priceReason = null,
        private readonly int $issuedQuantity = 0,
        private readonly ?string $unitCode = null,
        private readonly int $unitFactor = 1,
        private readonly string $priceSource = self::SOURCE_MASTER,
        private readonly ?int $priceListId = null,
        private readonly ?int $quotationItemId = null,
    ) {
    }

    /** Where list_price came from (D-19, ADR-025): the master unit price or a customer price list tier. */
    public const SOURCE_MASTER = 'MASTER';
    public const SOURCE_PRICE_LIST = 'PRICE_LIST';

    public function priceSource(): string { return $this->priceSource; }
    public function priceListId(): ?int { return $this->priceListId; }
    /** Quotation line this SO line was converted from; null for lines entered directly. */
    public function quotationItemId(): ?int { return $this->quotationItemId; }

    /** Unit snapshot (D-16, ADR-022); null on legacy fixtures (= the base unit). quantity() is base = unitQuantity() × factor. */
    public function unitCode(): ?string { return $this->unitCode; }
    public function unitFactor(): int { return $this->unitFactor; }
    public function unitQuantity(): int { return intdiv($this->quantity, max(1, $this->unitFactor)); }
    /** Remaining quantity in whole line units (shipments are entered in the line unit). */
    public function remainingUnitQuantity(): int { return intdiv($this->remainingQuantity(), max(1, $this->unitFactor)); }
    /** Line total in Rupiah = unit quantity × price per unit. */
    public function lineTotal(): float { return (int) round($this->sellingPrice * 100) * $this->unitQuantity() / 100; }

    public function id(): int { return $this->id; }
    public function salesOrderId(): int { return $this->salesOrderId; }
    public function productId(): int { return $this->productId; }
    public function quantity(): int { return $this->quantity; }
    public function sellingPrice(): float { return $this->sellingPrice; }
    public function linePrice(): float { return $this->sellingPrice; }
    public function listPrice(): float { return $this->listPrice ?? $this->sellingPrice; }
    public function priceReason(): ?string { return $this->priceReason; }

    /** Units already issued in earlier shipments (D-14, ADR-020); never above quantity (DB CHECK). */
    public function issuedQuantity(): int { return $this->issuedQuantity; }
    public function remainingQuantity(): int { return $this->quantity - $this->issuedQuantity; }

    public function withIssuedQuantity(int $issuedQuantity): self
    {
        return new self($this->id, $this->salesOrderId, $this->productId, $this->quantity, $this->sellingPrice, $this->listPrice, $this->priceReason, $issuedQuantity, $this->unitCode, $this->unitFactor, $this->priceSource, $this->priceListId, $this->quotationItemId);
    }

    public function isPriceOverridden(): bool
    {
        return (int) round($this->sellingPrice * 100) !== (int) round($this->listPrice() * 100);
    }
}
