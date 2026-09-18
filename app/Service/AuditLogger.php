<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\AuditLogRepositoryInterface;
use Throwable;

final class AuditLogger
{
    public function __construct(private readonly AuditLogRepositoryInterface $repository)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, string $ipAddress, string $userAgent, array $metadata = []): void
    {
        try {
            $this->repository->append($actorId, $action, $entityType, $entityId, $status, $ipAddress, $userAgent, $metadata);
        } catch (Throwable) {
            // Audit write failure must not hide the original business outcome in this assessment app.
        }
    }
}
