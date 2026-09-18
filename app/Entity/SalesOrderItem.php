<?php

declare(strict_types=1);

namespace App\Entity;

final class SalesOrderItem
{
    public function __construct(
        private readonly int $id,
        private readonly int $salesOrderId,
        private readonly int $productId,
        private readonly int $quantity,
        private readonly float $sellingPrice,
    ) {
    }

    public function id(): int { return $this->id; }
    public function salesOrderId(): int { return $this->salesOrderId; }
    public function productId(): int { return $this->productId; }
    public function quantity(): int { return $this->quantity; }
    public function sellingPrice(): float { return $this->sellingPrice; }
}
