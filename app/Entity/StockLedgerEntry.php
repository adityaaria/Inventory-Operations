<?php

declare(strict_types=1);

namespace App\Entity;

final class StockLedgerEntry
{
    public function __construct(
        private readonly int $id,
        private readonly int $productId,
        private readonly int $warehouseId,
        private readonly string $movementType,
        private readonly int $quantity,
        private readonly string $referenceType,
        private readonly int $referenceId,
        private readonly int $performedBy,
        private readonly ?int $quantityDelta = null,
        private readonly ?string $createdAt = null,
        private readonly ?int $lotId = null,
        private readonly ?string $lotCode = null,
        private readonly ?string $lotExpiry = null,
        private readonly ?string $unitCost = null,
        private readonly ?string $costAmount = null,
    ) {
    }

    public function delta(): int { return match ($this->movementType) { 'Adjustment' => $this->quantityDelta ?? 0, 'Receipt' => $this->quantity, default => -$this->quantity }; }
    public function id(): int { return $this->id; }
    public function productId(): int { return $this->productId; }
    public function warehouseId(): int { return $this->warehouseId; }
    public function movementType(): string { return $this->movementType; }
    public function quantity(): int { return $this->quantity; }
    public function referenceType(): string { return $this->referenceType; }
    public function referenceId(): int { return $this->referenceId; }
    public function performedBy(): int { return $this->performedBy; }
    /** Movement time (shipment grouping on the SO detail, D-14/ADR-020); null when the source did not load it. */
    public function createdAt(): ?string { return $this->createdAt; }
    /** Lot moved by this row (D-17, ADR-023); null for rows written before the lot migration. */
    public function lotId(): ?int { return $this->lotId; }
    public function lotCode(): ?string { return $this->lotCode; }
    public function lotExpiry(): ?string { return $this->lotExpiry; }
    /** Lot unit cost per base unit (D-20, ADR-026); null for rows before the valuation start (no lot). */
    public function unitCost(): ?string { return $this->unitCost; }
    /** Signed quantity × unit cost in whole cents as a decimal string; null for rows before the valuation start. */
    public function costAmount(): ?string { return $this->costAmount; }
}
