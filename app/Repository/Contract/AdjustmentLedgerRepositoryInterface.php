<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface AdjustmentLedgerRepositoryInterface
{
    /** $unitCost: the lot's unit cost (D-20, ADR-026), see StockLedgerRepositoryInterface. */
    public function appendAdjustment(int $product,int $warehouse,int $delta,string $reference,int $id,int $actor,?int $lot=null,?string $unitCost=null): void;
}
