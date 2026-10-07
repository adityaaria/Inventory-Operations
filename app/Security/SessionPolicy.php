<?php

declare(strict_types=1);

namespace App\Security;

/** Application limits are independent of PHP's probabilistic garbage collection. */
final class SessionPolicy
{
    public function __construct(
        public readonly int $idleSeconds = 1800,
        public readonly int $absoluteSeconds = 28800,
        public readonly int $rotationSeconds = 900,
    ) {
        if ($idleSeconds < 1 || $absoluteSeconds < $idleSeconds || $rotationSeconds < 1 || $rotationSeconds > $absoluteSeconds) {
            throw new \InvalidArgumentException('Invalid session time limits.');
        }
    }

    /** @param array<string, mixed> $metadata */
    public function isExpired(array $metadata, int $now): bool
    {
        $created = $metadata['created_at'] ?? null;
        $seen = $metadata['last_seen_at'] ?? null;
        $rotated = $metadata['rotated_at'] ?? null;
        if (!is_int($created) || !is_int($seen) || !is_int($rotated)
            || $created < 0 || $created > $seen || $created > $rotated || $rotated > $seen || $seen > $now) {
            return true;
        }
        return $now - $seen >= $this->idleSeconds || $now - $created >= $this->absoluteSeconds;
    }

    /** @param array<string, mixed> $metadata */
    public function shouldRotate(array $metadata, int $now): bool
    {
        return !$this->isExpired($metadata, $now) && $now - $metadata['rotated_at'] >= $this->rotationSeconds;
    }
}
