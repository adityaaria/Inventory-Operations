<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\Contract\TransactionManagerInterface;
use InvalidArgumentException;

final class CsvImportService
{
    public function __construct(private readonly TransactionManagerInterface $transactions) {}

    /** @param list<array<string, string>> $rows @param callable(array<string, string>): mixed $create */
    public function import(array $rows, callable $create): void
    {
        $this->transactions->run(function () use ($rows, $create): void {
            foreach ($rows as $index => $row) {
                try {
                    $create($row);
                } catch (InvalidArgumentException $exception) {
                    throw new InvalidArgumentException('CSV row ' . ($index + 2) . ': ' . $exception->getMessage() . ' No rows were imported.', 0, $exception);
                }
            }
        });
    }
}
