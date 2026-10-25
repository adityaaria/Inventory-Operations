<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Calendar date in the application time zone (D-17, ADR-023). MySQL and PHP run in UTC (ADR-017), whose date rolls over
 * at 07:00 WIB; the expiry rule needs the business date, so it never uses CURRENT_DATE. PHP code calls today(); SQL uses
 * App\Repository\MySql\ExpirySql::today(), which shifts UTC_TIMESTAMP() by offsetSeconds(). The zone comes from
 * APP_TIMEZONE (default Asia/Jakarta, UTC+7, no daylight saving time).
 */
final class BusinessDate
{
    public const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    private static ?string $timezone = null;

    /** Sets the zone (bootstrap, tests); an unknown zone fails at boot instead of silently using UTC. */
    public static function configure(string $timezone): void
    {
        self::assertValidZone($timezone);
        self::$timezone = $timezone;
    }

    public static function timezone(): DateTimeZone
    {
        $name = self::$timezone;
        if ($name === null) {
            $env = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE');
            $name = is_string($env) && $env !== '' ? $env : self::DEFAULT_TIMEZONE;
            self::assertValidZone($name);
        }

        return new DateTimeZone($name);
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', self::timezone()))->format('Y-m-d');
    }

    /** Current UTC offset of the zone in seconds (an integer, safe to place in SQL). */
    public static function offsetSeconds(): int
    {
        return self::timezone()->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** Strict YYYY-MM-DD that is a real calendar date. */
    public static function isValidDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $match) !== 1) {
            return false;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) && (int) $match[1] >= 2000 && (int) $match[1] <= 2999;
    }

    /** Whole days from $from to $to (negative when $to is earlier). */
    public static function daysBetween(string $from, string $to): int
    {
        $start = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));

        return (int) round(($end->getTimestamp() - $start->getTimestamp()) / 86400);
    }

    private static function assertValidZone(string $name): void
    {
        if (!in_array($name, DateTimeZone::listIdentifiers(), true) && $name !== 'UTC') {
            throw new \InvalidArgumentException('APP_TIMEZONE must be a valid time zone identifier.');
        }
    }
}
