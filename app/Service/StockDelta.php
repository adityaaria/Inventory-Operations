<?php
declare(strict_types=1);
namespace App\Service;
final class StockDelta
{
    public function __construct(public readonly int $product,public readonly int $warehouse,public readonly int $delta,public readonly ?int $baseline=null) {}
}
