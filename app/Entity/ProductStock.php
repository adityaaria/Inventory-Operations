<?php

declare(strict_types=1);

namespace App\Entity;

final class ProductStock
{
    public function __construct(
        private readonly int $productId,
        private readonly int $warehouseId,
        private readonly string $sku,
        private readonly string $productName,
        private readonly string $warehouseName,
        private readonly int $quantity,
        private readonly int $reorderPoint,
    ) {
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function warehouseId(): int
    {
        return $this->warehouseId;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function productName(): string
    {
        return $this->productName;
    }

    public function warehouseName(): string
    {
        return $this->warehouseName;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function reorderPoint(): int
    {
        return $this->reorderPoint;
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->reorderPoint;
    }
}
