<?php
declare(strict_types=1);
namespace App\Repository\InMemory;
use App\Repository\Contract\BusinessOperationRepositoryInterface;
final class InMemoryBusinessOperationRepository implements BusinessOperationRepositoryInterface
{
    /** @var array<int,array<string,mixed>> */ private array $rows=[];
    /** @var array<int,int> */ private array $totals=[];
    /** @param array<string,array<string,mixed>> $pairs @param array<int,array<string,mixed>> $sources */
    public function __construct(private readonly array $pairs=[],private readonly array $sources=[]) {}
    public function create(array $header,array $items): int {$id=count($this->rows)+1;$this->rows[$id]=array_merge($header,['id'=>$id,'status'=>'PendingApproval','approved_by'=>null,'items'=>$items]);return $id;}
    public function find(int $id,bool $lock=false): ?array {return $this->rows[$id]??null;}
    public function decide(int $id,string $status,int $actor,string $reason): void {$this->rows[$id]['status']=$status;$this->rows[$id]['decision_reason']=$reason;if($status!=='Cancelled') { $this->rows[$id]['approved_by']=$actor; }}
    public function posted(int $id,int $actor): void {$this->rows[$id]['status']='Posted';$this->rows[$id]['posted_by']=$actor;}
    public function pair(int $product,int $warehouse): ?array {return $this->pairs[$product.':'.$warehouse]??null;}
    public function source(int $id,bool $lock=false): ?array {return $this->sources[$id]??null;}
    public function returned(int $source): int {return $this->totals[$source]??0;}
    public function recordReturn(int $source,int $quantity): void {$this->totals[$source]=($this->totals[$source]??0)+$quantity;}
    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    private function matching(array $filters): array {return array_values(array_filter($this->rows,static function(array $row) use($filters): bool {foreach(['kind','status'] as $key) { if(($filters[$key]??'')!==''&&$filters[$key]!==$row[$key]) { return false; } }return ($filters['q']??'')===''||str_contains((string)$row['reason'],$filters['q']);}));}
    public function count(array $filters): int {return count($this->matching($filters));}
    public function page(array $filters,int $limit,int $offset): array {return array_slice(array_reverse($this->matching($filters)),$offset,$limit);}
    public function recommendations(string $query,int $limit,int $offset,int $warehouseId=0): array {return [];}
    public function recommendationCount(string $query,int $warehouseId=0): int {return 0;}
}
