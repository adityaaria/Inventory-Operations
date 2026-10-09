<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Support\RequestOrigin;

interface AuditLogRepositoryInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function append(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, RequestOrigin $origin, array $metadata = []): void;
}
