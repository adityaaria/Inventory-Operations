<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Units of one lot within a movement (D-17, ADR-023): code, expiry (Y-m-d or null), base quantity and the unit cost per
 * base unit (decimal string, two places). A null cost means "the product's current purchase price per base".
 */
final class LotSplit
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $expiryDate,
        public readonly int $quantity,
        public readonly ?string $unitCost = null,
    ) {
    }

    public function withQuantity(int $quantity): self
    {
        return new self($this->code, $this->expiryDate, $quantity, $this->unitCost);
    }
}
