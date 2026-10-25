<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Quotation statuses (D-19, ADR-025; quotation statuses, not SO statuses). Stored: Draft, Sent, Accepted, Rejected,
 * Cancelled. Expired is computed at read time: a Draft or Sent quotation whose valid_until is before today
 * (APP_TIMEZONE) is shown and filtered as Expired. Converted is an Accepted quotation that already has its SO.
 */
final class QuotationStatus
{
    public const DRAFT = 'Draft';
    public const SENT = 'Sent';
    public const ACCEPTED = 'Accepted';
    public const REJECTED = 'Rejected';
    public const CANCELLED = 'Cancelled';
    public const EXPIRED = 'Expired';
    public const FILTERS = [self::DRAFT, self::SENT, self::ACCEPTED, 'Converted', self::REJECTED, self::EXPIRED, self::CANCELLED];

    /** @param array<string, mixed> $quotation needs status and valid_until */
    public static function effective(array $quotation, string $today): string
    {
        $status = (string) $quotation['status'];
        if (in_array($status, [self::DRAFT, self::SENT], true) && (string) $quotation['valid_until'] < $today) {
            return self::EXPIRED;
        }

        return $status;
    }

    /** @param array<string, mixed> $quotation */
    public static function isValidOn(array $quotation, string $today): bool
    {
        return (string) $quotation['valid_until'] >= $today;
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            self::ACCEPTED => 'status-success',
            self::SENT => 'status-warning',
            self::REJECTED, self::CANCELLED, self::EXPIRED => 'status-danger',
            default => 'status-normal',
        };
    }
}
