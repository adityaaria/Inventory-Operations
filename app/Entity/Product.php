<?php

declare(strict_types=1);

namespace App\Entity;

final class Product
{
    public function __construct(
        private readonly int $id,
        private readonly string $sku,
        private readonly string $name,
        private readonly string $unit,
        private readonly float $purchasePrice,
        private readonly float $sellingPrice,
        private readonly int $reorderPoint,
        private readonly int $categoryId,
        private readonly bool $isActive,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    public function price(): float
    {
        return $this->sellingPrice;
    }

    public function purchasePrice(): float
    {
        return $this->purchasePrice;
    }

    public function sellingPrice(): float
    {
        return $this->sellingPrice;
    }

    public function reorderPoint(): int
    {
        return $this->reorderPoint;
    }

    public function categoryId(): int
    {
        return $this->categoryId;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
