<?php

declare(strict_types=1);

namespace App\Support;

/** Validated outstanding-report filters; scope and owner always come from the authenticated actor. */
final class OutstandingCriteria
{
    public function __construct(
        public readonly string $scope,
        public readonly ?int $owner,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $document = '',
        public readonly string $bucket = '',
    ) {
    }
}
