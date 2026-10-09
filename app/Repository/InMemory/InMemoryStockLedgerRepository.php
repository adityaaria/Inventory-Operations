<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\StockLedgerEntry;
use App\Exception\PersistenceException;
use App\Repository\Contract\StockLedgerRepositoryInterface;

final class InMemoryStockLedgerRepository implements StockLedgerRepositoryInterface, \App\Repository\Contract\AdjustmentLedgerRepositoryInterface
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

    public function appendAdjustment(int $product,int $warehouse,int $delta,string $reference,int $id,int $actor): void
    {
        if($delta===0) { throw new \InvalidArgumentException('Adjustment delta cannot be zero.'); }
        $this->append($product,$warehouse,'Adjustment',$delta,$reference,$id,$actor);
    }
    /** Receipt and Issue pass a positive quantity; Adjustment passes the signed delta, stored as its absolute quantity plus the delta. */
    private function append(int $productId, int $warehouseId, string $movementType, int $signedQuantity, string $referenceType, int $referenceId, int $performedBy): void
    {
        $isAdjustment = $movementType === 'Adjustment';
        $quantity = $isAdjustment ? abs($signedQuantity) : $signedQuantity;
        $delta = $isAdjustment ? $signedQuantity : null;
        if ($this->failOnAppend) {
            throw new PersistenceException('Forced ledger failure.');
        }

        $this->entries[] = new StockLedgerEntry(count($this->entries) + 1, $productId, $warehouseId, $movementType, $quantity, $referenceType, $referenceId, $performedBy, $delta);
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
