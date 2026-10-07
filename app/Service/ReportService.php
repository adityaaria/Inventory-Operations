<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\OperationalQueryRepositoryInterface;
use InvalidArgumentException;
use App\Entity\User;
use App\Exception\HttpException;
use App\Security\AuthContext;
use App\Support\Pagination;
use App\Support\PaginatedResult;

final class ReportService
{
    public function __construct(private readonly OperationalQueryRepositoryInterface $queries)
    {
    }

    /** @return array{type: string, metrics: array<string, int>, charts: array<string, array<string, int>>, columns: list<string>, result: PaginatedResult<array<string, string|int|float|null>>} */
    public function preview(AuthContext $actor, string $type, ?string $from, ?string $to, Pagination $pagination): array
    {
        $this->assertDateRange($from, $to);
        if (!in_array($type, ['orders', 'stock-ledger'], true)) {
            throw new InvalidArgumentException('Invalid report type.');
        }
        if ($type === 'stock-ledger' && $actor->role() === User::ROLE_SALES) {
            throw new HttpException(403, 'Forbidden');
        }
        $owner = $actor->role() === User::ROLE_SALES ? $actor->userId() : null;
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
            : ['Stock Movements' => $total, 'Units Received' => $received, 'Units Issued' => $issued, 'Net Units Moved' => $received - $issued];
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

    /** @return \Closure(): void */
    public function csvStream(AuthContext $actor, string $type, ?string $from, ?string $to): \Closure
    {
        $this->assertDateRange($from, $to);
        if (!in_array($type, ['orders', 'stock-ledger'], true)) throw new InvalidArgumentException('Invalid report type.');
        if ($type === 'stock-ledger' && $actor->role() === User::ROLE_SALES) throw new HttpException(403, 'Forbidden');
        $owner = $actor->role() === User::ROLE_SALES ? $actor->userId() : null;
        return function () use ($type, $from, $to, $owner): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) throw new \RuntimeException('Unable to open CSV output.');
            try {
                $columns = $type === 'orders' ? ['Type', 'OrderNumber', 'Party', 'Status', 'Date'] : ['Date', 'Movement', 'SKU', 'Warehouse', 'Quantity', 'ReferenceType', 'ReferenceId'];
                fputcsv($handle, $columns, ',', '"', '\\');
                foreach ($this->queries->iterateReportRows($type, $from, $to, $owner) as $row) {
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
