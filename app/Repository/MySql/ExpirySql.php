<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Support\BusinessDate;

/**
 * SQL form of the expiry rule (D-17, ADR-023). "Today" is the business date in APP_TIMEZONE, computed from
 * UTC_TIMESTAMP() (which follows the session clock, so the seed generator's SET TIMESTAMP gives historical dates) plus the
 * zone offset; CURRENT_DATE would be the UTC date (ADR-017). The offset is an integer from PHP, never user input.
 * A lot is expired when expiry_date < today.
 */
final class ExpirySql
{
    public static function today(): string
    {
        return 'DATE(UTC_TIMESTAMP() + INTERVAL ' . BusinessDate::offsetSeconds() . ' SECOND)';
    }

    /** Expired units of the pair aliased `$alias` (product_stocks). */
    public static function expiredQuantity(string $alias = 'ps'): string
    {
        return '(SELECT COALESCE(SUM(xl.quantity), 0) FROM stock_lots xl WHERE xl.product_id = ' . $alias . '.product_id AND xl.warehouse_id = '
            . $alias . '.warehouse_id AND xl.expiry_date IS NOT NULL AND xl.expiry_date < ' . self::today() . ')';
    }

    /** Earliest expiry date of a non-expired lot with stock of the pair (NULL when none). */
    public static function nearestExpiry(string $alias = 'ps'): string
    {
        return '(SELECT MIN(nl.expiry_date) FROM stock_lots nl WHERE nl.product_id = ' . $alias . '.product_id AND nl.warehouse_id = '
            . $alias . '.warehouse_id AND nl.quantity > 0 AND nl.expiry_date IS NOT NULL AND nl.expiry_date >= ' . self::today() . ')';
    }
}
