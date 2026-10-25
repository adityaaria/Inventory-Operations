<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Base-unit quantity of one product in one stock call. Receipts may carry lot splits (D-17, ADR-023) that add up to the
 * quantity; without them StockService creates one lot ($defaultLotCode, else "<reference>-<id>", no expiry, cost
 * $defaultUnitCost or the current purchase price per base). Issues and dispatches ignore splits: lots are picked FEFO.
 */
final class StockMovement
{
    /** @param list<LotSplit> $lots */
    public function __construct(
        private readonly int $productId,
        private readonly int $quantity,
        private readonly array $lots = [],
        private readonly ?string $defaultLotCode = null,
        private readonly ?string $defaultUnitCost = null,
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

    /** @return list<LotSplit> */
    public function lots(): array
    {
        return $this->lots;
    }

    public function defaultLotCode(): ?string
    {
        return $this->defaultLotCode;
    }

    public function defaultUnitCost(): ?string
    {
        return $this->defaultUnitCost;
    }
}
