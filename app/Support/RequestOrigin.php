<?php

declare(strict_types=1);

namespace App\Support;

/** Where an audited action came from; empty for actions recorded outside an HTTP request context. */
final class RequestOrigin
{
    public function __construct(
        public readonly string $ipAddress = '',
        public readonly string $userAgent = '',
    ) {
    }

    public static function none(): self
    {
        return new self();
    }
}
