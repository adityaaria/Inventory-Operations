<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contract\DocumentSequenceRepositoryInterface;
use App\Support\OrderNumber;
use DateTimeImmutable;
use DateTimeZone;

final class InMemoryDocumentSequenceRepository implements DocumentSequenceRepositoryInterface
{
    /** @var array<string, int> "PREFIX:YYMM" => last claimed value */
    private array $values;

    private DateTimeImmutable $now;

    /**
     * @param array<string, int> $initial "PREFIX:YYMM" => last value already used
     */
    public function __construct(?DateTimeImmutable $now = null, array $initial = [])
    {
        $this->now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->values = $initial;
    }

    public function currentPeriod(): string
    {
        return OrderNumber::period($this->now);
    }

    public function next(string $prefix, string $period): int
    {
        OrderNumber::assertPrefix($prefix);
        OrderNumber::assertPeriod($period);
        $key = $prefix . ':' . $period;
        $this->values[$key] = ($this->values[$key] ?? 0) + 1;

        return $this->values[$key];
    }

    public function lastValue(string $prefix, string $period): int
    {
        return $this->values[$prefix . ':' . $period] ?? 0;
    }
}
