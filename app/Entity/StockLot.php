<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One batch of a product in one warehouse (D-17, ADR-023). Quantity is in base units (ADR-022); unit cost is per base unit
 * in whole cents. `expired` is evaluated by the repository against the business date (expiry < today) at read time.
 */
final class StockLot
{
    public const LEGACY = 'LEGACY';

    public function __construct(
        private readonly int $id,
        private readonly int $productId,
        private readonly int $warehouseId,
        private readonly int $sequence,
        private readonly string $code,
        private readonly ?string $expiryDate,
        private readonly string $receivedAt,
        private readonly string $unitCost,
        private readonly int $quantity,
        private readonly bool $expired = false,
        private readonly int $openingQuantity = 0,
        private readonly string $sourceType = 'RECEIPT',
        private readonly ?int $sourceId = null,
    ) {
    }

    public function id(): int { return $this->id; }
    public function productId(): int { return $this->productId; }
    public function warehouseId(): int { return $this->warehouseId; }
    public function sequence(): int { return $this->sequence; }
    public function code(): string { return $this->code; }
    public function expiryDate(): ?string { return $this->expiryDate; }
    public function receivedAt(): string { return $this->receivedAt; }
    /** Per base unit, decimal string with two places. */
    public function unitCost(): string { return $this->unitCost; }
    public function quantity(): int { return $this->quantity; }
    public function isExpired(): bool { return $this->expired; }
    public function openingQuantity(): int { return $this->openingQuantity; }
    public function sourceType(): string { return $this->sourceType; }
    public function sourceId(): ?int { return $this->sourceId; }
    public function isLegacy(): bool { return $this->code === self::LEGACY && $this->expiryDate === null; }

    public function withQuantity(int $quantity): self
    {
        return new self($this->id, $this->productId, $this->warehouseId, $this->sequence, $this->code, $this->expiryDate, $this->receivedAt, $this->unitCost, $quantity, $this->expired, $this->openingQuantity, $this->sourceType, $this->sourceId);
    }

    /** Identity inside a pair: code, expiry and cost (UNIQUE uq_stock_lots_identity). */
    public function matches(string $code, ?string $expiryDate, string $unitCost): bool
    {
        return strcasecmp($this->code, $code) === 0 && $this->expiryDate === $expiryDate && self::cents($this->unitCost) === self::cents($unitCost);
    }

    /**
     * FEFO order: expiry date ascending with "no expiry" last, then the first receipt, then the id.
     *
     * @param list<self> $lots
     * @return list<self>
     */
    public static function fefo(array $lots): array
    {
        usort($lots, static fn (self $a, self $b): int => [$a->expiryDate ?? '9999-12-31', $a->receivedAt, $a->id] <=> [$b->expiryDate ?? '9999-12-31', $b->receivedAt, $b->id]);

        return $lots;
    }

    public static function cents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
