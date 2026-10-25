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
    /** Same row shape as the MySQL repository (nullable review columns, item SKU and product name). */
    public function create(array $header,array $items): int {$id=count($this->rows)+1;$items=array_map(static fn(array $item): array => $item+['sku'=>'SKU-'.$item['product_id'],'product_name'=>'Product '.$item['product_id']],$items);$items=array_map(static fn(array $item): array => $item+['unit_code'=>null,'unit_factor'=>1,'unit_quantity'=>$item['quantity'],'dispatched_quantity'=>null,'received_quantity'=>null,'damaged_quantity'=>null,'short_quantity'=>null,'lot_id'=>null,'lot_code'=>null,'expiry_date'=>null,'lots'=>[]],$items);$this->rows[$id]=array_merge($header+['purpose'=>'Standard'],['id'=>$id,'status'=>'PendingApproval','approved_by'=>null,'posted_by'=>null,'decision_reason'=>null,'dispatched_by'=>null,'dispatched_at'=>null,'received_by'=>null,'received_at'=>null,'receipt_reason'=>null,'created_at'=>date('Y-m-d H:i:s'),'items'=>$items]);return $id;}
    public function find(int $id,bool $lock=false): ?array {return $this->rows[$id]??null;}
    public function decide(int $id,string $status,int $actor,string $reason): void {$this->rows[$id]['status']=$status;$this->rows[$id]['decision_reason']=$reason;if($status!=='Cancelled') { $this->rows[$id]['approved_by']=$actor; }}
    public function posted(int $id,int $actor): void {$this->rows[$id]['status']='Posted';$this->rows[$id]['posted_by']=$actor;}
    public function dispatched(int $id,int $actor): void
    {
        if(($this->rows[$id]['status']??null)!=='Approved'||$this->rows[$id]['kind']!=='Transfer') { throw new \LogicException('Transfer is not Approved; nothing was dispatched.'); }
        $this->rows[$id]['status']='Dispatched';$this->rows[$id]['dispatched_by']=$actor;$this->rows[$id]['dispatched_at']=date('Y-m-d H:i:s');
        foreach($this->rows[$id]['items'] as &$item) { $item['dispatched_quantity']=$item['quantity']; }unset($item);
    }
    public function received(int $id,int $actor,array $lines,?string $reason): void
    {
        if(($this->rows[$id]['status']??null)!=='Dispatched') { throw new \LogicException('Transfer is not Dispatched; nothing was received.'); }
        foreach($this->rows[$id]['items'] as &$item) {
            $line=$lines[(int)$item['product_id']]??throw new \LogicException('Transfer line is missing.');
            if($line['good']+$line['damaged']+$line['short']!==(int)$item['dispatched_quantity']) { throw new \LogicException('Receipt does not add up to the dispatched quantity.'); }
            $item['received_quantity']=$line['good'];$item['damaged_quantity']=$line['damaged'];$item['short_quantity']=$line['short'];
        }unset($item);
        $this->rows[$id]['status']='Received';$this->rows[$id]['received_by']=$actor;$this->rows[$id]['received_at']=date('Y-m-d H:i:s');$this->rows[$id]['receipt_reason']=$reason;
    }
    public function inTransit(int $limit,int $offset): array
    {
        $open=array_values(array_filter($this->rows,static fn(array $row): bool => $row['kind']==='Transfer'&&$row['status']==='Dispatched'));
        $open=array_map(static fn(array $row): array => $row+['warehouse_name'=>'Warehouse '.$row['warehouse_id'],'destination_name'=>'Warehouse '.$row['destination_id'],'dispatcher_name'=>'User '.$row['dispatched_by'],'line_count'=>count($row['items']),'units'=>array_sum(array_map(static fn(array $item): int => (int)$item['dispatched_quantity'],$row['items'])),'age_days'=>0],$open);
        return array_slice($open,$offset,$limit);
    }
    public function inTransitCount(): int {return count($this->inTransit(PHP_INT_MAX,0));}
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
    /** @var array<int,array<string,mixed>> lots by id (D-17), seeded by tests */
    private array $lots=[];
    /** @param array<string,mixed> $lot */
    public function addLot(array $lot): void {$this->lots[(int)$lot['id']]=$lot;}
    public function lots(int $product,int $warehouse): array {return array_values(array_filter($this->lots,static fn(array $lot): bool => (int)$lot['product_id']===$product&&(int)$lot['warehouse_id']===$warehouse));}
    public function lot(int $id): ?array {return $this->lots[$id]??null;}
    public function expiredLots(int $warehouse,?int $product,int $limit): array {return array_slice(array_values(array_filter($this->lots,static fn(array $lot): bool => (int)$lot['warehouse_id']===$warehouse&&($product===null||(int)$lot['product_id']===$product)&&(int)$lot['quantity']>0&&($lot['expired']??false)===true)),0,$limit);}
    public function recordTransferLots(int $id,array $picked): void
    {
        $next=1;foreach($this->rows as $row) { foreach($row['items'] as $item) { $next+=count($item['lots']??[]); } }
        foreach($this->rows[$id]['items'] as &$item) { foreach($picked as $lot) { if((int)$lot['product_id']===(int)$item['product_id']) { $item['lots'][]=['id'=>$next++,'operation_id'=>$id,'product_id'=>(int)$item['product_id'],'source_lot_id'=>$lot['lot_id'],'lot_code'=>$lot['lot_code'],'expiry_date'=>$lot['expiry_date'],'unit_cost'=>$lot['unit_cost'],'dispatched_quantity'=>$lot['quantity'],'received_quantity'=>null,'damaged_quantity'=>null,'short_quantity'=>null,'destination_lot_id'=>null]; } } }unset($item);
    }
    public function receiveTransferLots(int $id,array $rows): void
    {
        foreach($this->rows[$id]['items'] as &$item) { foreach($item['lots'] as &$lot) { foreach($rows as $row) { if($row['id']===$lot['id']) { $lot['received_quantity']=$row['received'];$lot['damaged_quantity']=$row['damaged'];$lot['short_quantity']=$row['short'];$lot['destination_lot_id']=$row['destination_lot_id']; } } }unset($lot); }unset($item);
    }
}
