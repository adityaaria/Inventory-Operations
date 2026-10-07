<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\OperationalQueryRepositoryInterface;
use InvalidArgumentException;
use App\Entity\User;
use App\Exception\HttpException;
use App\Security\AuthContext;
use App\Support\OutstandingCriteria;
use App\Support\Pagination;
use App\Support\PaginatedResult;

final class ReportService
{
    private const TYPES = ['orders', 'stock-ledger', 'outstanding'];
    private const OUTSTANDING_COLUMNS = ['Type', 'OrderNumber', 'Party', 'Status', 'Created', 'AgeDays', 'AgeBucket', 'DaysSinceApproval', 'OutstandingQty'];

    public function __construct(private readonly OperationalQueryRepositoryInterface $queries)
    {
    }

    /** @return array{type: string, metrics: array<string, int>, charts: array<string, array<string, int>>, columns: list<string>, result: PaginatedResult<array<string, string|int|float|null>>} */
    /** @param array<string, mixed> $filters document/age filters, used only by the outstanding report */
    public function preview(AuthContext $actor, string $type, ?string $from, ?string $to, Pagination $pagination, array $filters = []): array
    {
        $this->assertReportAccess($actor, $type, $from, $to);
        $owner = $actor->role() === User::ROLE_SALES ? $actor->userId() : null;
        if ($type === 'outstanding') {
            return $this->outstandingPreview($this->outstandingCriteria($actor, $from, $to, $filters), $pagination);
        }
        $summary = $this->queries->reportSummary($type, $from, $to, $owner);
        $counts = $summary['counts'];
        $distribution = $summary['distribution'];
        $total = $summary['total'];
        $purchase = $distribution['PO'] ?? 0;
        $sales = $distribution['SO'] ?? 0;
        $completed = ($counts['Received'] ?? 0) + ($counts['Fulfilled'] ?? 0);
        $received = $summary['received'];
        $issued = $summary['issued'];
        $rows = $this->queries->reportPage($type, $from, $to, $owner, Pagination::PER_PAGE, $pagination->offsetForTotal($total));
        $metrics = $type === 'orders'
            ? ['Total Orders' => $total, 'Purchase Orders' => $purchase, 'Sales Orders' => $sales, 'Completed Orders' => $completed]
            : ['Stock Movements' => $total, 'Units Received' => $received, 'Units Issued' => $issued, 'Net Units Moved' => $received - $issued + $summary['adjusted']];
        if ($type === 'orders' && $actor->role() === User::ROLE_SALES) {
            $cancelled = $counts['Cancelled'] ?? 0;
            $metrics = ['Your Orders' => $total, 'Open Orders' => $total - $completed - $cancelled, 'Completed Orders' => $completed, 'Cancelled Orders' => $cancelled];
        }
        return [
            'type' => $type,
            'metrics' => $metrics,
            'charts' => $type === 'orders'
                ? ['Orders by Status' => $counts, 'Orders by Type' => $distribution]
                : ['Movements by Type' => $counts, 'Movements by Warehouse' => $distribution],
            'columns' => $type === 'orders'
                ? ['Type', 'OrderNumber', 'Party', 'Status', 'Date']
                : ['Date', 'Movement', 'SKU', 'Warehouse', 'Quantity', 'ReferenceType', 'ReferenceId'],
            'result' => new PaginatedResult($rows, $total, $pagination->pageForTotal($total), Pagination::PER_PAGE),
        ];
    }

    /**
     * @param array<string, mixed> $filters document/age filters, used only by the outstanding report
     * @return \Closure(): void
     */
    public function csvStream(AuthContext $actor, string $type, ?string $from, ?string $to, array $filters = []): \Closure
    {
        $this->assertReportAccess($actor, $type, $from, $to);
        $owner = $actor->role() === User::ROLE_SALES ? $actor->userId() : null;
        $criteria = $type === 'outstanding' ? $this->outstandingCriteria($actor, $from, $to, $filters) : null;
        return function () use ($type, $from, $to, $owner, $criteria): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) { throw new \RuntimeException('Unable to open CSV output.'); }
            try {
                $columns = match ($type) {
                    'orders' => ['Type', 'OrderNumber', 'Party', 'Status', 'Date'],
                    'outstanding' => self::OUTSTANDING_COLUMNS,
                    default => ['Date', 'Movement', 'SKU', 'Warehouse', 'Quantity', 'ReferenceType', 'ReferenceId'],
                };
                fputcsv($handle, $columns, ',', '"', '\\');
                $rows = $criteria === null
                    ? $this->queries->iterateReportRows($type, $from, $to, $owner)
                    : $this->queries->iterateOutstandingRows($criteria);
                foreach ($rows as $row) {
                    fputcsv($handle, array_map(fn (string $column): string => $this->csvValue($row[$column] ?? ''), $columns), ',', '"', '\\');
                }
            } finally { fclose($handle); }
        };
    }

    public function stockLedgerCsv(?string $from, ?string $to): string
    {
        $this->assertDateRange($from, $to);

        return $this->csv($this->queries->stockLedgerRows($from, $to));
    }

    public function ordersCsv(?string $from, ?string $to, ?int $salesUserId): string
    {
        $this->assertDateRange($from, $to);

        return $this->csv($this->queries->orderRows($from, $to, $salesUserId));
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(array $rows): string
    {
        if ($rows === []) {
            return "No data\n";
        }

        $handle = fopen('php://temp', 'r+');
        // Unreachable in practice: opening an in-memory php://temp stream does not fail under
        // normal PHP operation. Kept as a defensive guard against a hypothetical stream failure.
        if ($handle === false) {
            throw new \RuntimeException('Unable to create CSV buffer.');
        }

        fputcsv($handle, array_keys($rows[0]), ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (mixed $value): string => $this->csvValue($value), $row), ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    private function csvValue(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[\x00-\x20]*[=+\-@]/', $text) === 1 ? "'" . $text : $text;
    }

    /** @return array{type: string, metrics: array<string, int>, charts: array<string, array<string, int>>, columns: list<string>, result: PaginatedResult<array<string, string|int|float|null>>} */
    private function outstandingPreview(OutstandingCriteria $criteria, Pagination $pagination): array
    {
        $summary = $this->queries->outstandingSummary($criteria);
        $total = $summary['total'];
        $aged = $summary['buckets']['8-30 days'] + $summary['buckets']['31+ days'];
        $proposals = array_sum(array_filter($summary['statuses'], static fn (string $status): bool => str_starts_with($status, 'OP '), ARRAY_FILTER_USE_KEY));
        $metrics = $criteria->scope === OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_SALES
            ? ['Your Open Orders' => $total, 'Open Over 7 Days' => $aged, 'Oldest Age (Days)' => $summary['oldest_days'], 'Units Awaiting Issue' => $summary['outbound_units']]
            : ['Outstanding Documents' => $total, 'Open Over 7 Days' => $aged, 'Oldest Age (Days)' => $summary['oldest_days'], 'PO Units Awaiting Receipt' => $summary['inbound_units'], 'SO Units Awaiting Issue' => $summary['outbound_units'], 'Stock Proposals Waiting' => $proposals];
        $rows = $this->queries->outstandingPage($criteria, Pagination::PER_PAGE, $pagination->offsetForTotal($total));

        return [
            'type' => 'outstanding',
            'metrics' => $metrics,
            'charts' => ['Outstanding by Age' => $summary['buckets'], 'Outstanding by Status' => $summary['statuses']],
            'columns' => self::OUTSTANDING_COLUMNS,
            'result' => new PaginatedResult($rows, $total, $pagination->pageForTotal($total), Pagination::PER_PAGE),
        ];
    }

    /** Document types the actor's outstanding scope may contain, keyed by code with display label. @return array<string, string> */
    public function outstandingDocuments(AuthContext $actor): array
    {
        return $this->outstandingScope($actor) === OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_SALES
            ? ['SO' => 'Sales orders']
            : ['PO' => 'Purchase orders', 'SO' => 'Sales orders', 'OP' => 'Stock proposals'];
    }

    /** @param array<string, mixed> $filters */
    private function outstandingCriteria(AuthContext $actor, ?string $from, ?string $to, array $filters): OutstandingCriteria
    {
        $document = $filters['document'] ?? '';
        $bucket = $filters['age'] ?? '';
        if (!is_string($document) || !is_string($bucket)
            || ($document !== '' && !isset($this->outstandingDocuments($actor)[$document]))
            || ($bucket !== '' && !in_array($bucket, OperationalQueryRepositoryInterface::AGE_BUCKETS, true))) {
            throw new InvalidArgumentException('Invalid outstanding filter.');
        }

        return new OutstandingCriteria($this->outstandingScope($actor), $actor->role() === User::ROLE_SALES ? $actor->userId() : null, $from, $to, $document, $bucket);
    }

    /** Role decides which open documents are relevant; Sales is always limited to own orders by the caller. */
    private function outstandingScope(AuthContext $actor): string
    {
        return match ($actor->role()) {
            User::ROLE_ADMIN => OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_ALL,
            User::ROLE_WAREHOUSE_STAFF => OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_FULFILMENT,
            User::ROLE_SALES => OperationalQueryRepositoryInterface::OUTSTANDING_SCOPE_SALES,
            default => throw new HttpException(403, 'Forbidden'),
        };
    }

    private function assertReportAccess(AuthContext $actor, string $type, ?string $from, ?string $to): void
    {
        $this->assertDateRange($from, $to);
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Invalid report type.');
        }
        if ($type === 'stock-ledger' && $actor->role() === User::ROLE_SALES) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertDateRange(?string $from, ?string $to): void
    {
        foreach ([$from, $to] as $date) {
            if ($date !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                throw new InvalidArgumentException('Invalid date range.');
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('Invalid date range.');
        }
    }
}
