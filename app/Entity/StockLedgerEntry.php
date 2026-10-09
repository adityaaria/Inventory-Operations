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
}
