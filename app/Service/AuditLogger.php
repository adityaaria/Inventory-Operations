<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Support\RequestOrigin;
use Throwable;

final class AuditLogger
{
    public function __construct(private readonly AuditLogRepositoryInterface $repository)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, RequestOrigin $origin, array $metadata = []): void
    {
        try {
            $this->repository->append($actorId, $action, $entityType, $entityId, $status, $origin, $metadata);
        } catch (Throwable) {
            // Audit write failure must not hide the original business outcome in this assessment app.
        }
    }
}
