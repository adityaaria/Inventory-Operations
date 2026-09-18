<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\OperationalQueryRepositoryInterface;
use InvalidArgumentException;

final class ReportService
{
    public function __construct(private readonly OperationalQueryRepositoryInterface $queries)
    {
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

        return preg_match('/^[=+\-@]/', $text) === 1 ? "'" . $text : $text;
    }

    private function assertDateRange(?string $from, ?string $to): void
    {
        foreach ([$from, $to] as $date) {
            if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException('Invalid date range.');
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('Invalid date range.');
        }
    }
}
