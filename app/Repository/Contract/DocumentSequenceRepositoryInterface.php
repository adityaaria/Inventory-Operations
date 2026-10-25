<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Per-prefix-per-month counters for server-assigned order numbers (D-11, ADR-017).
 */
interface DocumentSequenceRepositoryInterface
{
    /** YYMM of "now" on the same clock that stamps order_date (database session clock for MySQL). */
    public function currentPeriod(): string;

    /**
     * Claims the next value (starting at 1) for the prefix/period. Must run inside the caller's transaction:
     * the claim stays locked until commit, and a rollback releases it so the value is reused (no gap).
     */
    public function next(string $prefix, string $period): int;
}
