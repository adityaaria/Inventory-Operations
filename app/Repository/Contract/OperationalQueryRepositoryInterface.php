<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Support\OutstandingCriteria;

interface OperationalQueryRepositoryInterface
{
    public const OUTSTANDING_SCOPE_ALL = 'all';
    public const OUTSTANDING_SCOPE_FULFILMENT = 'fulfilment';
    public const OUTSTANDING_SCOPE_SALES = 'sales';
    /** Document codes: purchase order, sales order, stock operation proposal. */
    public const OUTSTANDING_DOCUMENTS = ['PO', 'SO', 'OP'];
    /** Whole days since document creation, in operational (day-scale) buckets; not a due date or SLA (D-07). */
    public const AGE_BUCKETS = ['0-2 days', '3-7 days', '8-30 days', '31+ days'];

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
    /** @return array{total: int, counts: array<string, int>, distribution: array<string, int>, received: int, issued: int, adjusted: int} */
    public function reportSummary(string $type, ?string $from, ?string $to, ?int $salesUserId): array;
    /** @return list<array<string, string|int|float|null>> */
    public function reportPage(string $type, ?string $from, ?string $to, ?int $salesUserId, int $limit, int $offset): array;
    /** @return iterable<array<string, string|int|float|null>> */
    public function iterateReportRows(string $type, ?string $from, ?string $to, ?int $salesUserId): iterable;
    /** @return array{total: int, buckets: array<string, int>, statuses: array<string, int>, inbound_units: int, outbound_units: int, oldest_days: int} */
    public function outstandingSummary(OutstandingCriteria $criteria): array;
    /** @return list<array<string, string|int|float|null>> */
    public function outstandingPage(OutstandingCriteria $criteria, int $limit, int $offset): array;
    /** @return iterable<array<string, string|int|float|null>> */
    public function iterateOutstandingRows(OutstandingCriteria $criteria): iterable;
    /** @return array<string, mixed>|null */
    public function productAvailability(string $sku): ?array;
}
