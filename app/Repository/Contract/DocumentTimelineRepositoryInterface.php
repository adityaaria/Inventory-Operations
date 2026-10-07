<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface DocumentTimelineRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function document(string $kind,int $id): ?array;
    /** @return list<array{kind:string,id:int}> */
    public function links(string $kind,int $id): array;
    /** @return list<array<string,mixed>> */
    public function events(string $kind,int $id,bool $related,int $limit): array;
}
