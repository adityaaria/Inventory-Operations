<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Recount tolerance of stock count sessions (D-18, ADR-024). A line is within tolerance when
 * |variance| ≤ max(units, ⌊expected × percent / 100⌋); otherwise the reviewer may request a recount. Configured with
 * COUNT_RECOUNT_TOLERANCE_PERCENT (default 2, 0–100) and COUNT_RECOUNT_TOLERANCE_UNITS (default 0, base units).
 */
final class CountTolerance
{
    public const DEFAULT_PERCENT = 2;
    public const DEFAULT_UNITS = 0;

    public function __construct(public readonly int $percent = self::DEFAULT_PERCENT, public readonly int $units = self::DEFAULT_UNITS)
    {
        if ($percent < 0 || $percent > 100 || $units < 0) {
            throw new \InvalidArgumentException('Count tolerance must be 0–100 percent and a non-negative number of units.');
        }
    }

    /** Out-of-range configuration falls back to the defaults (as the draft TTL does), so a typo never disables recounts. */
    public static function fromConfig(int $percent, int $units): self
    {
        return new self($percent >= 0 && $percent <= 100 ? $percent : self::DEFAULT_PERCENT, $units >= 0 ? $units : self::DEFAULT_UNITS);
    }

    /** Largest variance (absolute, base units) that is still accepted without a recount. */
    public function allowance(int $expected): int
    {
        return max($this->units, intdiv(max(0, $expected) * $this->percent, 100));
    }

    public function exceeds(int $variance, int $expected): bool
    {
        return abs($variance) > $this->allowance($expected);
    }
}
