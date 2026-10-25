<?php

declare(strict_types=1);

namespace App\Entity;

use App\Support\ReorderThreshold;

final class ProductStock
{
    /**
     * @param int $reorderPoint product default reorder point (products.reorder_point)
     * @param int|null $reorderPointOverride per-warehouse override (product_stocks.reorder_point_override)
     * @param int $reservedQuantity part of quantity reserved for Approved sales orders (D-12, ADR-018)
     * @param int $inTransitInbound units of dispatched, not yet received transfers to this warehouse (D-15, ADR-021);
     *            informational only, never part of quantity or available
     * @param int $expiredQuantity units of expired lots (D-17, ADR-023): part of quantity, never sellable
     * @param string|null $nearestExpiry earliest expiry of a non-expired lot with stock
     */
    public function __construct(
        private readonly int $productId,
        private readonly int $warehouseId,
        private readonly string $sku,
        private readonly string $productName,
        private readonly string $warehouseName,
        private readonly int $quantity,
        private readonly int $reorderPoint,
        private readonly ?int $reorderPointOverride = null,
        private readonly bool $warehouseActive = true,
        private readonly bool $productActive = true,
        private readonly int $reservedQuantity = 0,
        private readonly int $inTransitInbound = 0,
        private readonly int $expiredQuantity = 0,
        private readonly ?string $nearestExpiry = null,
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

    /** Physical quantity on hand (= Σ ledger). */
    public function quantity(): int
    {
        return $this->quantity;
    }

    /** Quantity reserved for Approved sales orders from this warehouse. */
    public function reservedQuantity(): int
    {
        return $this->reservedQuantity;
    }

    /**
     * Available quantity: non-expired stock minus reserved (D-12, D-17). Never negative here; a reservation that outgrew
     * the sellable stock after lots expired shows as 0 available and in the Expiry report.
     */
    public function availableQuantity(): int
    {
        return max(0, $this->sellableQuantity() - $this->reservedQuantity);
    }

    /** Physical minus reserved, expired lots included (the API's original `available_quantity`). */
    public function availableIncludingExpired(): int
    {
        return max(0, $this->quantity - $this->reservedQuantity);
    }

    /** Units of non-expired lots (D-17, ADR-023). */
    public function sellableQuantity(): int
    {
        return max(0, $this->quantity - $this->expiredQuantity);
    }

    public function expiredQuantity(): int
    {
        return $this->expiredQuantity;
    }

    public function nearestExpiry(): ?string
    {
        return $this->nearestExpiry;
    }

    /** Reservation larger than the non-expired stock (lots expired after approval). */
    public function isOverReserved(): bool
    {
        return $this->reservedQuantity > $this->sellableQuantity();
    }

    /** Goods on their way to this warehouse in dispatched transfers (not on hand, not available). */
    public function inTransitInbound(): int
    {
        return $this->inTransitInbound;
    }

    /** Product default reorder point. */
    public function reorderPoint(): int
    {
        return $this->reorderPoint;
    }

    public function reorderPointOverride(): ?int
    {
        return $this->reorderPointOverride;
    }

    public function hasReorderPointOverride(): bool
    {
        return $this->reorderPointOverride !== null;
    }

    public function effectiveReorderPoint(): int
    {
        return ReorderThreshold::effective($this->reorderPoint, $this->reorderPointOverride);
    }

    public function isWarehouseActive(): bool
    {
        return $this->warehouseActive;
    }

    public function isProductActive(): bool
    {
        return $this->productActive;
    }

    /**
     * Low stock only counts for active products in active warehouses (same rule as dashboard and command). It compares
     * the available quantity, because reserved stock is already committed to approved orders (D-12, ADR-018).
     */
    public function isLowStock(): bool
    {
        return $this->warehouseActive && $this->productActive
            && ReorderThreshold::isLow($this->availableQuantity(), $this->reorderPoint, $this->reorderPointOverride);
    }
}
