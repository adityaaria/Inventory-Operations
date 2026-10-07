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
    /** @return array{total: int, counts: array<string, int>, distribution: array<string, int>, received: int, issued: int} */
    public function reportSummary(string $type, ?string $from, ?string $to, ?int $salesUserId): array;
    /** @return list<array<string, string|int|float|null>> */
    public function reportPage(string $type, ?string $from, ?string $to, ?int $salesUserId, int $limit, int $offset): array;
    /** @return iterable<array<string, string|int|float|null>> */
    public function iterateReportRows(string $type, ?string $from, ?string $to, ?int $salesUserId): iterable;
    /** @return array<string, mixed>|null */
    public function productAvailability(string $sku): ?array;
}
