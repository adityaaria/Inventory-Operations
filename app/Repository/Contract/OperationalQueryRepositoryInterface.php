<?php

declare(strict_types=1);

namespace App\Repository\Contract;

interface OperationalQueryRepositoryInterface
{
    /** @return array<string, mixed> */
    public function adminDashboard(): array;
    /** @return array<string, mixed> */
    public function salesDashboard(int $salesUserId): array;
    /** @return array<string, mixed> */
    public function warehouseDashboard(): array;
    /** @return list<array<string, mixed>> */
    public function lowStockRows(): array;
    /** @return list<array<string, string|int|float|null>> */
    public function stockLedgerRows(?string $from, ?string $to): array;
    /** @return list<array<string, string|int|float|null>> */
    public function orderRows(?string $from, ?string $to, ?int $salesUserId): array;
    /** @return array<string, mixed>|null */
    public function productAvailability(string $sku): ?array;
}
