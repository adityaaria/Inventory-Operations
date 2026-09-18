<?php

declare(strict_types=1);

namespace App\Support;

/**
 * @template T
 */
final class PaginatedResult
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $total,
        private readonly int $page,
        private readonly int $perPage,
    ) {
    }

    /**
     * @return list<T>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int { return $this->total; }
    public function page(): int { return $this->page; }
    public function perPage(): int { return $this->perPage; }
    public function pages(): int { return max(1, (int) ceil($this->total / $this->perPage)); }
}
