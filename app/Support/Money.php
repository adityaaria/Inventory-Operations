<?php

declare(strict_types=1);

namespace App\Support;

final class Money
{
    /** Indonesian Rupiah for display, e.g. "Rp 20.000,00". Stored values and CSV exports stay plain decimals. */
    public static function rupiah(float $amount): string
    {
        return ($amount < 0 ? '-' : '') . 'Rp ' . number_format(abs($amount), 2, ',', '.');
    }
}
