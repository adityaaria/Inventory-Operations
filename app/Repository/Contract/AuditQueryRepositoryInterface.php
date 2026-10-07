<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface AuditQueryRepositoryInterface
{
    /** @param array<string,string> $filters */
    public function count(array $filters): int;
    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    public function page(array $filters, int $limit, int $offset): array;
}
