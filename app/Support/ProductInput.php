<?php

declare(strict_types=1);

namespace App\Support;

final class ProductInput
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly string $unit,
        public readonly float $purchasePrice,
        public readonly float $sellingPrice,
        public readonly int $reorderPoint,
        public readonly int $categoryId,
    ) {
    }

    public function trimmed(): self
    {
        return new self(
            trim($this->sku),
            trim($this->name),
            trim($this->unit),
            $this->purchasePrice,
            $this->sellingPrice,
            $this->reorderPoint,
            $this->categoryId,
        );
    }
}
