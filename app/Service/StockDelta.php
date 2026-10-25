<?php
declare(strict_types=1);
namespace App\Service;
/**
 * Signed change of one pair (adjustment, return, write-off). $baseline is the counted pair quantity, or the counted lot
 * quantity when $lot targets one lot (D-17, ADR-023); null skips the count check. Without $lot a decrease is taken FEFO
 * over all lots and an increase becomes a new lot "<reference>-<id>".
 */
final class StockDelta
{
    public function __construct(public readonly int $product,public readonly int $warehouse,public readonly int $delta,public readonly ?int $baseline=null,public readonly ?LotTarget $lot=null) {}
}
