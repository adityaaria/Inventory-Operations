<?php

declare(strict_types=1);

namespace App\Service;

final class StockMovement
{
    public function __construct(
        private readonly int $productId,
        private readonly int $quantity,
    ) {
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }
}
