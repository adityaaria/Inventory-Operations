<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One approved stock count line to post (D-18, ADR-024): the signed delta (= approved variance, base units) applied to
 * the *current* quantity of an existing lot ($lotId), or the units of a found lot that does not exist yet ($newLot: code
 * and expiry; created at posting with the product's current purchase price per base, ADR-023 cost rule). $label names
 * the line in refusal messages.
 */
final class CountPosting
{
    public function __construct(
        public readonly int $lineId,
        public readonly int $productId,
        public readonly ?int $lotId,
        public readonly ?LotSplit $newLot,
        public readonly int $delta,
        public readonly string $label,
    ) {
        if (($lotId === null) === ($newLot === null)) {
            throw new \InvalidArgumentException('A count posting names exactly one existing lot or one new lot.');
        }
        if ($newLot !== null && $delta < 0) {
            throw new \InvalidArgumentException('A found lot can only add units.');
        }
    }
}
