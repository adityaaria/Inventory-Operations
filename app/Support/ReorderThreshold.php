<?php

declare(strict_types=1);

namespace App\Support;

use App\Exception\ValidationException;

/**
 * Single low-stock rule (D-10, ADR-016): a product/warehouse pair is low when its quantity is strictly below the
 * effective reorder point. Since D-12/ADR-018 the quantity compared is the *available* quantity (physical minus
 * reserved for Approved sales orders); callers pass it in. The effective reorder point is the per-warehouse override when set, else the product default.
 * The MySQL repositories use the equivalent SQL in App\Repository\MySql\LowStockSql.
 */
final class ReorderThreshold
{
    /** Upper bound of the INT UNSIGNED reorder point columns. */
    public const MAX = 4294967295;

    public static function effective(int $productReorderPoint, ?int $override): int
    {
        return $override ?? $productReorderPoint;
    }

    /** @param int $availableQuantity quantity − reserved_quantity (D-12) */
    public static function isLow(int $availableQuantity, int $productReorderPoint, ?int $override): bool
    {
        return $availableQuantity < self::effective($productReorderPoint, $override);
    }

    private const INVALID = 'Warehouse reorder point must be a whole number from 0 to ' . self::MAX . ', or empty to use the product default.';

    /** Validates an override value; null clears the override. */
    public static function assertValidOverride(?int $override): void
    {
        if ($override !== null && ($override < 0 || $override > self::MAX)) {
            throw new ValidationException(self::INVALID);
        }
    }

    /** Parses form input: empty clears the override (null); otherwise digits only, within the column range. */
    public static function parseOverride(mixed $raw): ?int
    {
        if (!is_string($raw) && !is_int($raw)) {
            throw new ValidationException(self::INVALID);
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d{1,10}$/D', $text) !== 1) {
            throw new ValidationException(self::INVALID);
        }
        $value = (int) $text;
        self::assertValidOverride($value);

        return $value;
    }
}
