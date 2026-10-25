<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cost amount of a stock movement (D-20, ADR-026): signed quantity (base units) × lot unit cost (per base unit).
 *
 * Precision: stored as DECIMAL(24,2) — whole cents. Rounding: per ledger row, to whole cents, half up on the absolute
 * value (half away from zero), so a movement and its exact reversal always cancel. Lot unit costs are DECIMAL(14,2)
 * (ADR-023), so for every stored cost the product is already whole cents and no rounding happens; the rule only decides a
 * cost given with more than two decimals. Integer arithmetic only: binary floats never decide an amount.
 */
final class CostAmount
{
    private const COST_PATTERN = '/^(-?)(\d{1,12})(?:\.(\d{1,6}))?$/D';

    /** Cents of $signedQuantity × $unitCost (half away from zero). */
    public static function cents(int $signedQuantity, string $unitCost): int
    {
        if (preg_match(self::COST_PATTERN, trim($unitCost), $match) !== 1 || $match[1] === '-') {
            throw new \InvalidArgumentException('Unit cost must be a non-negative decimal with at most 6 decimals.');
        }
        $fraction = $match[3] ?? '';
        $units = abs($signedQuantity);
        $sign = $signedQuantity < 0 ? -1 : 1;
        if (strlen($fraction) <= 2) {
            $costCents = (int) $match[2] * 100 + (int) str_pad($fraction, 2, '0');

            return $sign * self::multiply($units, $costCents);
        }
        $micro = (int) $match[2] * 1000000 + (int) str_pad($fraction, 6, '0');
        $product = self::multiply($units, $micro);

        return $sign * intdiv($product + 5000, 10000);
    }

    /** Same amount as a plain decimal string ("-1234.50"), the form stored in stock_ledger.cost_amount. */
    public static function decimal(int $signedQuantity, string $unitCost): string
    {
        return TaxSettings::decimal(self::cents($signedQuantity, $unitCost));
    }

    /** Value of $quantity units at $unitCost, in cents (non-negative quantity). */
    public static function valueCents(int $quantity, string $unitCost): int
    {
        return self::cents($quantity, $unitCost);
    }

    /** Gross margin percent of revenue with one decimal (null when there is no revenue). */
    public static function marginPercent(int $revenueCents, int $costCents): ?float
    {
        if ($revenueCents === 0) {
            return null;
        }

        return round(($revenueCents - $costCents) * 100 / $revenueCents, 1);
    }

    private static function multiply(int $a, int $b): int
    {
        if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) {
            throw new \OverflowException('Cost amount is out of range.');
        }

        return $a * $b;
    }
}
