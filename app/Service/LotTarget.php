<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Which lot a signed stock delta changes (D-17, ADR-023):
 * - lot(id): that existing lot (count of one lot, supplier/customer return to the original lot, expired write-off);
 * - create(split): find or create the lot with this code, expiry and cost (new lot found at a count);
 * - legacy(): the pair's LEGACY lot, created when missing (returns of pre-migration movements);
 * - fefo(): a decrease taken over all lots in FEFO order (pair-level count without a lot).
 * With $requireExpired the lot must still be expired after the lock (expired write-off).
 */
final class LotTarget
{
    private function __construct(
        public readonly string $mode,
        public readonly ?int $lotId = null,
        public readonly ?LotSplit $split = null,
        public readonly bool $requireExpired = false,
    ) {
    }

    public static function lot(int $lotId, bool $requireExpired = false): self { return new self('lot', $lotId, null, $requireExpired); }
    public static function create(LotSplit $split): self { return new self('create', null, $split); }
    public static function legacy(): self { return new self('legacy'); }
    public static function fefo(): self { return new self('fefo'); }
}
