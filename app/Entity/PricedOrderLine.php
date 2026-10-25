<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * An order line with an effective price and the master price snapshot it was created from (D-13, ADR-019).
 * The line is "overridden" when the two differ; a reason is then always present.
 */
interface PricedOrderLine
{
    public function productId(): int;

    /** Base quantity (D-16, ADR-022). */
    public function quantity(): int;

    /** Quantity as entered in the line unit; prices are per this unit, so totals use it. */
    public function unitQuantity(): int;

    public function unitCode(): ?string;

    public function unitFactor(): int;

    /** Effective line price per entered unit (purchase_price / selling_price), used by reports and totals. */
    public function linePrice(): float;

    /** Master price snapshot taken by the server when the draft was created. */
    public function listPrice(): float;

    public function priceReason(): ?string;

    public function isPriceOverridden(): bool;
}
