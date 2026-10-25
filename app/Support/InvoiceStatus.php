<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Invoice payment state (D-19, ADR-025). Computed from the stored amounts, never stored: Void when voided; Paid when
 * paid = total; PartiallyPaid when 0 < paid < total; Unpaid otherwise. Overdue = not void, something outstanding and the
 * due date is before today (APP_TIMEZONE).
 */
final class InvoiceStatus
{
    public const UNPAID = 'Unpaid';
    public const PARTIALLY_PAID = 'PartiallyPaid';
    public const PAID = 'Paid';
    public const VOID = 'Void';
    public const FILTERS = [self::UNPAID, self::PARTIALLY_PAID, self::PAID, self::VOID, 'Overdue'];

    /**
     * @param array<string, mixed> $invoice needs total_amount, paid_amount, voided_at, due_date
     * @return array{status: string, overdue: bool, outstanding_cents: int, days_overdue: int}
     */
    public static function of(array $invoice, string $today): array
    {
        $total = TaxSettings::cents((string) $invoice['total_amount']);
        $paid = TaxSettings::cents((string) $invoice['paid_amount']);
        if (($invoice['voided_at'] ?? null) !== null) {
            return ['status' => self::VOID, 'overdue' => false, 'outstanding_cents' => 0, 'days_overdue' => 0];
        }
        $status = match (true) {
            $paid >= $total => self::PAID,
            $paid > 0 => self::PARTIALLY_PAID,
            default => self::UNPAID,
        };
        $outstanding = max(0, $total - $paid);
        $overdue = $outstanding > 0 && (string) $invoice['due_date'] < $today;

        return ['status' => $status, 'overdue' => $overdue, 'outstanding_cents' => $outstanding, 'days_overdue' => $overdue ? BusinessDate::daysBetween((string) $invoice['due_date'], $today) : 0];
    }

    public static function tone(string $status, bool $overdue = false): string
    {
        return match (true) {
            $status === self::VOID => 'status-danger',
            $overdue => 'status-danger',
            $status === self::PAID => 'status-success',
            $status === self::PARTIALLY_PAID => 'status-warning',
            default => 'status-normal',
        };
    }
}
