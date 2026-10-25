<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\Product;
use App\Entity\ProductUnit;
use App\Exception\ValidationException;

/**
 * The units of one product (D-16, ADR-022): exactly one base unit (factor 1) plus optional larger units. Conversion
 * happens only at input (unit quantity → base) and display (base → breakdown); everything stored is base.
 */
final class UnitSet
{
    public const MAX_QUANTITY = 4294967295;

    /** @var list<ProductUnit> sorted by factor descending, base last */
    private readonly array $units;

    /** @param list<ProductUnit> $units */
    public function __construct(array $units, private readonly string $fallbackBaseCode = 'unit')
    {
        usort($units, static fn (ProductUnit $a, ProductUnit $b): int => [$b->factor(), $a->code()] <=> [$a->factor(), $b->code()]);
        $this->units = $units;
    }

    /** Stored units of a product; a missing base row is synthesized from products.unit so a product always has one. */
    public static function forProduct(Product $product, array $stored = []): self
    {
        $hasBase = false;
        foreach ($stored as $unit) {
            $hasBase = $hasBase || $unit->isBase();
        }

        return new self($hasBase ? $stored : [...$stored, ProductUnit::baseFor($product)], $product->unit());
    }

    public static function baseOnly(string $code): self
    {
        return new self([new ProductUnit(0, 0, $code, 1, null, null, null, true)], $code);
    }

    /** @return list<ProductUnit> */
    public function all(): array
    {
        return $this->units;
    }

    /** @return list<ProductUnit> active units, largest first, base last */
    public function active(): array
    {
        return array_values(array_filter($this->units, static fn (ProductUnit $unit): bool => $unit->isActive()));
    }

    public function base(): ProductUnit
    {
        foreach ($this->units as $unit) {
            if ($unit->isBase()) {
                return $unit;
            }
        }

        return new ProductUnit(0, 0, $this->fallbackBaseCode, 1, null, null, null, true);
    }

    public function baseCode(): string
    {
        return $this->base()->code();
    }

    public function find(string $code): ?ProductUnit
    {
        foreach ($this->units as $unit) {
            if (strcasecmp($unit->code(), $code) === 0) {
                return $unit;
            }
        }

        return null;
    }

    /** Unit for a new purchase line (purchase default, else base). */
    public function purchaseDefault(): ProductUnit
    {
        foreach ($this->active() as $unit) {
            if ($unit->isPurchaseDefault()) {
                return $unit;
            }
        }

        return $this->base();
    }

    /** Unit for a new sales line (sales default, else base). */
    public function salesDefault(): ProductUnit
    {
        foreach ($this->active() as $unit) {
            if ($unit->isSalesDefault()) {
                return $unit;
            }
        }

        return $this->base();
    }

    /**
     * Active unit chosen on an order or stock line; empty/null means the base unit (legacy callers and forms without a
     * unit field post base quantities). Unknown or inactive units are refused with 422.
     */
    public function resolve(?string $code, string $sku): ProductUnit
    {
        $code = $code === null ? '' : trim($code);
        if ($code === '') {
            return $this->base();
        }
        $unit = $this->find($code);
        if ($unit === null || !$unit->isActive()) {
            throw new ValidationException('Unit "' . $code . '" is not an active unit of ' . $sku . '.');
        }

        return $unit;
    }

    /** Base quantity = unit quantity × factor; refuses results beyond the INT UNSIGNED column range. */
    public static function toBase(int $unitQuantity, int $factor): int
    {
        if ($unitQuantity < 0 || $factor < 1) {
            throw new ValidationException('Invalid quantity.');
        }
        if ($unitQuantity > intdiv(self::MAX_QUANTITY, $factor)) {
            throw new ValidationException('Quantity is too large for the selected unit.');
        }

        return $unitQuantity * $factor;
    }

    /** Smallest whole number of units covering $base (replenishment rounding up). */
    public static function unitsCovering(int $base, int $factor): int
    {
        return $base <= 0 ? 0 : intdiv($base + $factor - 1, max(1, $factor));
    }

    /**
     * Friendly display of a base quantity: "101 pcs", "2 karton (96 pcs)", "2 karton + 5 pcs (101 pcs)".
     * Greedy over active units, largest first; the base total is always shown.
     */
    public function format(int $base): string
    {
        return QuantityFormatter::format($base, $this);
    }

    /** JSON for data attributes: active units with factor and master prices per unit. @return list<array<string, mixed>> */
    public function describe(Product $product): array
    {
        return array_map(static fn (ProductUnit $unit): array => [
            'code' => $unit->code(),
            'factor' => $unit->factor(),
            'base' => $unit->isBase(),
            'purchase_price' => number_format($unit->unitPurchasePrice($product), 2, '.', ''),
            'selling_price' => number_format($unit->unitSellingPrice($product), 2, '.', ''),
        ], $this->active());
    }
}
