<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\StockLedgerEntry;

interface StockLedgerRepositoryInterface
{
    public function appendReceipt(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy): void;
    public function appendIssue(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy): void;

    /** @return list<StockLedgerEntry> */
    public function forReference(string $referenceType, int $referenceId): array;
}
