<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\StockLedgerEntry;

interface StockLedgerRepositoryInterface
{
    /**
     * $lotId: the lot moved (D-17, ADR-023); StockService always passes it, null only for callers predating lots.
     * $unitCost: the lot's unit cost per base unit (D-20, ADR-026); the row stores it with cost_amount = signed quantity ×
     * unit cost (whole cents). A row with a lot always carries a cost: when $unitCost is null the repository reads the
     * lot's own cost. A row without a lot carries none.
     */
    public function appendReceipt(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy, ?int $lotId = null, ?string $unitCost = null): void;
    public function appendIssue(int $productId, int $warehouseId, int $quantity, string $referenceType, int $referenceId, int $performedBy, ?int $lotId = null, ?string $unitCost = null): void;

    /** @return list<StockLedgerEntry> */
    public function forReference(string $referenceType, int $referenceId): array;
}
