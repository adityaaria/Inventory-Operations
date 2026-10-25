<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Expiry status of a lot (D-17, ADR-023): Expired when expiry < today (business date), Near when 0..window days are
 * left, OK otherwise, "No expiry" without a date. A lot is still sellable on its expiry date.
 */
final class LotStatus
{
    public const OK = 'OK';
    public const NEAR = 'Near expiry';
    public const EXPIRED = 'Expired';
    public const NONE = 'No expiry';

    public static function of(?string $expiryDate, string $today, int $nearDays): string
    {
        if ($expiryDate === null) {
            return self::NONE;
        }
        $days = self::daysLeft($expiryDate, $today);

        return $days < 0 ? self::EXPIRED : ($days <= $nearDays ? self::NEAR : self::OK);
    }

    public static function daysLeft(string $expiryDate, string $today): int
    {
        return BusinessDate::daysBetween($today, $expiryDate);
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            self::EXPIRED => 'status-danger',
            self::NEAR => 'status-warning',
            self::OK => 'status-success',
            default => 'status-normal',
        };
    }
}
