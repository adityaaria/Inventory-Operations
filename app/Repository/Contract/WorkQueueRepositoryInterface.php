<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface WorkQueueRepositoryInterface
{
    /** @return array<string,int> */
    public function counts(string $role,int $actor,string $type,string $query): array;
    /** @return list<array<string,mixed>> */
    public function page(string $role,int $actor,string $type,string $query,int $limit,int $offset): array;
}
