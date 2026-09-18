<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contract\AuditLogRepositoryInterface;

final class InMemoryAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var list<array{actor_id: ?int, action: string, entity_type: string, entity_id: ?int, status: string, ip_address: string, user_agent: string, metadata: array<string, mixed>}> */
    private array $entries = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function append(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, string $ipAddress, string $userAgent, array $metadata = []): void
    {
        $this->entries[] = [
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => $status,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return list<array{actor_id: ?int, action: string, entity_type: string, entity_id: ?int, status: string, ip_address: string, user_agent: string, metadata: array<string, mixed>}>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
