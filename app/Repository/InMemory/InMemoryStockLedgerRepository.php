<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\StockLedgerEntry;
use App\Repository\Contract\StockLedgerRepositoryInterface;
use RuntimeException;

final class InMemoryStockLedgerRepository implements StockLedgerRepositoryInterface
{
    /** @var list<StockLedgerEntry> */
    private array $entries = [];

    public function __construct(private readonly bool $failOnAppend = false)
    {
    }

    public function appendReceipt(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        $this->append($productId, $warehouseId, 'Receipt', $quantity, $referenceType, $referenceId, $performedBy);
    }

    public function appendIssue(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        $this->append($productId, $warehouseId, 'Issue', $quantity, $referenceType, $referenceId, $performedBy);
    }

    private function append(int $productId, int $warehouseId, string $movementType, int $quantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        if ($this->failOnAppend) {
            throw new RuntimeException('Forced ledger failure.');
        }

        $this->entries[] = new StockLedgerEntry(count($this->entries) + 1, $productId, $warehouseId, $movementType, $quantity, $referenceType, $referenceId, $performedBy);
    }

    public function forReference(string $referenceType, int $referenceId): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (StockLedgerEntry $entry): bool => $entry->referenceType() === $referenceType && $entry->referenceId() === $referenceId,
        ));
    }

    /** @return list<StockLedgerEntry> */
    public function entries(): array
    {
        return $this->entries;
    }
}
