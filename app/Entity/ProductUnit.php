<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One unit of measure of a product (D-16, ADR-022). Every stored stock quantity is an integer in the product's base unit
 * (the smallest unit, factor 1); a unit converts with base quantity = unit quantity × factorToBase.
 * Prices are master prices per this unit; null means "base price × factor" (see unitPurchasePrice()/unitSellingPrice()).
 * The base unit never has own prices: its prices are products.purchase_price / selling_price.
 */
final class ProductUnit
{
    public function __construct(
        private readonly int $id,
        private readonly int $productId,
        private readonly string $code,
        private readonly int $factorToBase,
        private readonly ?string $barcode = null,
        private readonly ?float $purchasePrice = null,
        private readonly ?float $sellingPrice = null,
        private readonly bool $isBase = false,
        private readonly bool $isPurchaseDefault = false,
        private readonly bool $isSalesDefault = false,
        private readonly bool $isActive = true,
    ) {
    }

    /** Synthetic base unit for a product without stored unit rows (id 0); same meaning as the stored base row. */
    public static function baseFor(Product $product): self
    {
        return new self(0, $product->id(), $product->unit(), 1, null, null, null, true);
    }

    public function id(): int { return $this->id; }
    public function productId(): int { return $this->productId; }
    public function code(): string { return $this->code; }
    public function factor(): int { return $this->factorToBase; }
    public function barcode(): ?string { return $this->barcode; }
    /** Own master purchase price per this unit, or null when it follows base price × factor. */
    public function purchasePrice(): ?float { return $this->purchasePrice; }
    /** Own master selling price per this unit, or null when it follows base price × factor. */
    public function sellingPrice(): ?float { return $this->sellingPrice; }
    public function isBase(): bool { return $this->isBase; }
    public function isPurchaseDefault(): bool { return $this->isPurchaseDefault; }
    public function isSalesDefault(): bool { return $this->isSalesDefault; }
    public function isActive(): bool { return $this->isActive; }

    /** Master purchase price per this unit: own price, else the product's base purchase price × factor (rounded to cents). */
    public function unitPurchasePrice(Product $product): float
    {
        return $this->purchasePrice ?? self::times($product->purchasePrice(), $this->factorToBase);
    }

    /** Master selling price per this unit: own price, else the product's base selling price × factor (rounded to cents). */
    public function unitSellingPrice(Product $product): float
    {
        return $this->sellingPrice ?? self::times($product->sellingPrice(), $this->factorToBase);
    }

    /** Cost floor per this unit for the below-cost rule: base purchase price × factor, in cents (ADR-022). */
    public function costCents(Product $product): int
    {
        return (int) round($product->purchasePrice() * 100) * $this->factorToBase;
    }

    private static function times(float $basePrice, int $factor): float
    {
        return ((int) round($basePrice * 100) * $factor) / 100;
    }
}
