<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface AdjustmentLedgerRepositoryInterface
{
    public function appendAdjustment(int $product,int $warehouse,int $delta,string $reference,int $id,int $actor): void;
}
