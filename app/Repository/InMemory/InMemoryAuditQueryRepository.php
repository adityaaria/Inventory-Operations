<?php
declare(strict_types=1);
namespace App\Repository\InMemory;
use App\Repository\Contract\AuditQueryRepositoryInterface;
final class InMemoryAuditQueryRepository implements AuditQueryRepositoryInterface
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(private readonly array $rows=[]) {}
    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    private function matching(array $filters): array
    {
        $rows=array_values(array_filter($this->rows,static function(array $row) use ($filters): bool {
            foreach (['action','status','actor_id'] as $key) { if (($filters[$key]??'')!=='' && (string)($row[$key]??'')!==$filters[$key]) { return false; } }
            $date=substr((string)$row['created_at'],0,10);
            return (($filters['from']??'')==='' || $date>=$filters['from']) && (($filters['to']??'')==='' || $date<=$filters['to']);
        }));
        usort($rows,static fn(array $a,array $b): int => [$b['created_at'],$b['id']] <=> [$a['created_at'],$a['id']]); return $rows;
    }
    public function count(array $filters): int { return count($this->matching($filters)); }
    public function page(array $filters,int $limit,int $offset): array { return array_slice($this->matching($filters),$offset,$limit); }
}
