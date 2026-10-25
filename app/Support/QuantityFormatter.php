<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one shared quantity display (D-16, ADR-022); public/assets/js/order-items.js `formatQuantity()` mirrors it.
 * Stored quantities are base units. With only a base unit: "101 pcs". With larger units the base total is broken down
 * greedily over the active units, largest first: "2 karton (96 pcs)", "2 karton + 5 pcs (101 pcs)", "5 pcs".
 * Negative quantities (signed adjustments) keep their sign: "-1 karton (-48 pcs)".
 */
final class QuantityFormatter
{
    public static function format(int $base, UnitSet $units): string
    {
        $baseCode = $units->baseCode();
        $total = self::number($base) . ' ' . $baseCode;
        $remaining = abs($base);
        $sign = $base < 0 ? '-' : '';
        $parts = [];
        foreach ($units->active() as $unit) {
            if ($unit->isBase() || $unit->factor() <= 1) {
                continue;
            }
            $count = intdiv($remaining, $unit->factor());
            if ($count > 0) {
                $parts[] = self::number($count) . ' ' . $unit->code();
                $remaining -= $count * $unit->factor();
            }
        }
        if ($parts === []) {
            return $total;
        }
        if ($remaining > 0) {
            $parts[] = self::number($remaining) . ' ' . $baseCode;
        }

        return $sign . implode(' + ', $parts) . ' (' . $total . ')';
    }

    private static function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
