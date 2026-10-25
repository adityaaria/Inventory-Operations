<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\PricedOrderLine;

/** Read-only helpers for showing negotiated line prices (D-13, ADR-019) on PO/SO detail pages and the timeline. */
final class PriceOverrides
{
    /** Effective minus master price per unit; negative for a discount. */
    public static function unitDifference(PricedOrderLine $line): float
    {
        return self::cents($line->linePrice()) / 100 - self::cents($line->listPrice()) / 100;
    }

    public static function lineDifference(PricedOrderLine $line): float
    {
        return (self::cents($line->linePrice()) - self::cents($line->listPrice())) * $line->unitQuantity() / 100;
    }

    /** Difference in percent of the master price; null when the master price is zero. */
    public static function percent(PricedOrderLine $line): ?float
    {
        $list = self::cents($line->listPrice());

        return $list === 0 ? null : (self::cents($line->linePrice()) - $list) * 100 / $list;
    }

    /** "-5,00%" / "+2,50%" in the same Indonesian notation as Money::rupiah(); "n/a" without a master price. */
    public static function percentLabel(PricedOrderLine $line): string
    {
        $percent = self::percent($line);
        if ($percent === null) {
            return 'n/a';
        }

        return ($percent < 0 ? '-' : '+') . number_format(abs($percent), 2, ',', '.') . '%';
    }

    /** Signed Rupiah, e.g. "+Rp 1.000,00" or "-Rp 500,00". */
    public static function signedRupiah(float $amount): string
    {
        return ($amount > 0 ? '+' : '') . Money::rupiah($amount);
    }

    /**
     * @param list<PricedOrderLine> $lines
     * @return array{lines: int, total: float} number of overridden lines and Σ (price − list price) × unit quantity (prices are per entered unit, D-16)
     */
    public static function summary(array $lines): array
    {
        $count = 0;
        $totalCents = 0;
        foreach ($lines as $line) {
            if ($line->isPriceOverridden()) {
                $count++;
                $totalCents += (self::cents($line->linePrice()) - self::cents($line->listPrice())) * $line->unitQuantity();
            }
        }

        return ['lines' => $count, 'total' => $totalCents / 100];
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
