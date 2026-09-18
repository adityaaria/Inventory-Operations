<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\OperationalQueryRepositoryInterface;

final class LowStockService
{
    public function __construct(private readonly OperationalQueryRepositoryInterface $queries)
    {
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->queries->lowStockRows();
    }
}
