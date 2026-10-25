<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Server-assigned order numbers (D-11, ADR-017): PREFIX-YYMM-NNNN, e.g. PO-2610-0019.
 *
 * YYMM is the creation month on the database session clock (UTC in this deployment; the same clock stamps
 * order_date). NNNN is a per-prefix-per-month counter padded to at least four digits; from 10000 on it simply
 * grows (PO-2610-10000), so the width is "four or more digits", never truncated.
 */
final class OrderNumber
{
    public const PURCHASE = 'PO';
    public const SALES = 'SO';
    /** D-19 / ADR-025: quotation, delivery note (surat jalan) and invoice numbers use the same sequence and format. */
    public const QUOTATION = 'QT';
    public const DELIVERY = 'DO';
    public const INVOICE = 'INV';
    public const PREFIXES = [self::PURCHASE, self::SALES, self::QUOTATION, self::DELIVERY, self::INVOICE];

    /** Full-string pattern for numbers this format produces (case-sensitive, as generated). */
    public const PATTERN = '/^(PO|SO|QT|DO|INV)-(\d{4})-(\d{4,})$/D';

    public static function format(string $prefix, string $period, int $value): string
    {
        self::assertPrefix($prefix);
        self::assertPeriod($period);
        if ($value < 1) {
            throw new InvalidArgumentException('Order sequence value must be positive.');
        }

        return sprintf('%s-%s-%04d', $prefix, $period, $value);
    }

    /** YYMM of the given moment in its own time zone. */
    public static function period(DateTimeInterface $moment): string
    {
        return $moment->format('ym');
    }

    /**
     * @return array{prefix: string, period: string, value: int}|null null for numbers outside the generated format (legacy/manual)
     */
    public static function parse(string $orderNumber): ?array
    {
        if (preg_match(self::PATTERN, $orderNumber, $match) !== 1) {
            return null;
        }

        return ['prefix' => $match[1], 'period' => $match[2], 'value' => (int) $match[3]];
    }

    public static function assertPrefix(string $prefix): void
    {
        if (!in_array($prefix, self::PREFIXES, true)) {
            throw new InvalidArgumentException('Unknown order number prefix.');
        }
    }

    public static function assertPeriod(string $period): void
    {
        if (preg_match('/^\d{2}(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new InvalidArgumentException('Order number period must be YYMM.');
        }
    }
}
