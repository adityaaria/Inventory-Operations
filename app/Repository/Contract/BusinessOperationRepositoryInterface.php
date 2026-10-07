<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface BusinessOperationRepositoryInterface
{
    /** @param array<string,mixed> $header @param list<array<string,mixed>> $items */
    public function create(array $header,array $items): int;
    /** @return array<string,mixed>|null */
    public function find(int $id,bool $lock=false): ?array;
    public function decide(int $id,string $status,int $actor,string $reason): void;
    public function posted(int $id,int $actor): void;
    /** @return array<string,mixed>|null */
    public function pair(int $product,int $warehouse): ?array;
    /** @return array<string,mixed>|null */
    public function source(int $id,bool $lock=false): ?array;
    public function returned(int $source): int;
    public function recordReturn(int $source,int $quantity): void;
    /** @param array<string,string> $filters */
    public function count(array $filters): int;
    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    public function page(array $filters,int $limit,int $offset): array;
    /** @return list<array<string,mixed>> */
    public function recommendations(string $query,int $limit,int $offset,int $warehouseId=0): array;
    public function recommendationCount(string $query,int $warehouseId=0): int;
}
