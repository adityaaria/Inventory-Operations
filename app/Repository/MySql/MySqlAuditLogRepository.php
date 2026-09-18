<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Repository\Contract\AuditLogRepositoryInterface;
use JsonException;
use PDO;

final class MySqlAuditLogRepository implements AuditLogRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function append(?int $actorId, string $action, string $entityType, ?int $entityId, string $status, string $ipAddress, string $userAgent, array $metadata = []): void
    {
        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR);
        if (!is_string($metadataJson)) {
            throw new JsonException('Unable to encode audit metadata.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, status, ip_address, user_agent, metadata_json)
             VALUES (:actor_id, :action, :entity_type, :entity_id, :status, :ip_address, :user_agent, :metadata_json)'
        );
        $statement->execute([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => $status,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata_json' => $metadataJson,
        ]);
    }
}
