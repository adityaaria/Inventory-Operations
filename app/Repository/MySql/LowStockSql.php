<?php

declare(strict_types=1);

namespace App\Repository\MySql;

/**
 * SQL form of App\Support\ReorderThreshold for queries aliasing product_stocks as `ps` and products as `p`.
 * Callers still restrict to active products and active warehouses.
 *
 * Low stock compares the available quantity: quantity − expired − reserved_quantity. Reserved stock is already committed
 * to Approved sales orders (D-12/ADR-018) and expired lots are not sellable (D-17/ADR-023). This is the only place the
 * SQL rule lives; the dashboard, Products filter, low-stock command and replenishment all use it.
 */
final class LowStockSql
{
    public const EFFECTIVE = 'COALESCE(ps.reorder_point_override, p.reorder_point)';

    /** Signed arithmetic: UNSIGNED − UNSIGNED would raise an out-of-range error instead of going negative. */
    public static function available(): string
    {
        return '(CAST(ps.quantity AS SIGNED) - CAST(ps.reserved_quantity AS SIGNED) - ' . ExpirySql::expiredQuantity('ps') . ')';
    }

    public static function isLow(): string
    {
        return self::available() . ' < ' . self::EFFECTIVE;
    }
}
