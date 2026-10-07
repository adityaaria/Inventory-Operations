<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface OperationRequestRepositoryInterface
{
    /** Must run inside the stock transaction. Returns true only for a completed replay. */
    public function replay(int $actorId, string $key, string $hash): bool;
    public function complete(int $actorId, string $key): void;
}
