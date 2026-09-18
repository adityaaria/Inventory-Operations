<?php

declare(strict_types=1);

namespace App\Repository\Contract;

interface AuditLogRepositoryInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function append(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, string $ipAddress, string $userAgent, array $metadata = []): void;
}
