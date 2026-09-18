<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\OperationalQueryRepositoryInterface;

final class ProductAvailabilityService
{
    public function __construct(private readonly OperationalQueryRepositoryInterface $queries)
    {
    }

    /** @return array<string, mixed>|null */
    public function forSku(string $sku): ?array
    {
        return $this->queries->productAvailability(trim($sku));
    }
}
