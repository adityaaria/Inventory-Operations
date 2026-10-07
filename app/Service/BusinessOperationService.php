<?php
declare(strict_types=1);
namespace App\Service;
use App\Repository\Contract\{BusinessOperationRepositoryInterface,AuditLogRepositoryInterface};
use App\Security\AuthContext;
use App\Exception\{HttpException,ValidationException};
use App\Support\{Pagination,PaginatedResult};
final class BusinessOperationService
{
    public const KINDS=['Adjustment','Transfer','SupplierReturn','CustomerReturn'];
    public const STATUSES=['PendingApproval','Approved','Rejected','Posted','Cancelled'];
    public function __construct(private readonly BusinessOperationRepositoryInterface $operations,private readonly StockService $stock,private readonly AuditLogRepositoryInterface $audit) {}
    public function authorize(AuthContext $actor): void { if(!in_array($actor->role(),['Admin','WarehouseStaff'],true)) { throw new HttpException(403,'Forbidden'); } }
    /** @param list<array<string,mixed>> $items */
    public function propose(AuthContext $actor,string $kind,int $warehouse,?int $destination,string $reason,array $items): int
    {
        $this->authorize($actor);$reason=BusinessOperationInput::reason($reason);
        if(!in_array($kind,self::KINDS,true) || $items===[] || count($items)>100) { throw new ValidationException('Invalid operation or items.'); }
        if($kind==='Transfer' && ($destination===null || $destination===$warehouse)) { throw new ValidationException('Choose different source and destination warehouses.'); }
        if($kind!=='Transfer') { $destination=null; }
        return $this->stock->transaction(function() use($actor,$kind,$warehouse,$destination,$reason,$items): int {
            $lines=[];$seen=[];$returnWarehouse=null;
            foreach($items as $item) {
                $sourceId=null;$baseline=null;
                if($kind==='CustomerReturn' && ($item['fit_for_stock']??false)!==true) { throw new ValidationException('Confirm that all returned goods are fit for stock.'); }
                $product=in_array($kind,['SupplierReturn','CustomerReturn'],true)?0:BusinessOperationInput::quantity($item['product_id']??0);
                $quantity=BusinessOperationInput::quantity($item['quantity']??null,$kind==='Adjustment');
                if(in_array($kind,['SupplierReturn','CustomerReturn'],true)) {
                    $sourceId=BusinessOperationInput::quantity($item['source_ledger_id']??null);
                    $source=$this->operations->source($sourceId)??throw new ValidationException('Original movement not found.');
                    $expected=$kind==='SupplierReturn'?'Receipt':'Issue';$reference=$kind==='SupplierReturn'?'PO':'SO';
                    if($source['movement_type']!==$expected || $source['reference_type']!==$reference) { throw new ValidationException('Return must reference the original PO receipt or SO issue.'); }
                    $product=(int)$source['product_id'];$sourceWarehouse=(int)$source['warehouse_id'];
                    if($returnWarehouse!==null && $returnWarehouse!==$sourceWarehouse) { throw new ValidationException('All return items must use the original warehouse.'); }
                    $returnWarehouse=$sourceWarehouse;$warehouse=$sourceWarehouse;
                    if($quantity>(int)$source['quantity']) { throw new ValidationException('Return exceeds original movement.'); }
                }
                $pair=$this->operations->pair($product,$warehouse)??throw new ValidationException('Choose an active product and warehouse.');
                if(isset($seen[$product])) { throw new ValidationException('Duplicate product items are not allowed.'); }$seen[$product]=true;
                if($destination!==null && $this->operations->pair($product,$destination)===null) { throw new ValidationException('Choose an active destination warehouse.'); }
                if($kind==='Adjustment') {
                    $baseline=BusinessOperationInput::quantity($item['baseline']??null,true);
                    if($baseline!==(int)$pair['quantity']) { throw new HttpException(409,'Stock changed since the count. Recount before proposing.'); }
                    if($quantity===$baseline) { throw new ValidationException('Count must differ from current stock.'); }
                }
                $lines[]=['product_id'=>$product,'quantity'=>$quantity,'baseline'=>$baseline,'source_ledger_id'=>$sourceId];
            }
            $id=$this->operations->create(['kind'=>$kind,'warehouse_id'=>$warehouse,'destination_id'=>$destination,'reason'=>$reason,'created_by'=>$actor->userId(),'condition_confirmed'=>$kind==='CustomerReturn'?1:0],$lines);
            $this->audit->append($actor->userId(),'inventory-operations.propose',$kind,$id,'success','','',[]);return $id;
        });
    }
    public function decide(AuthContext $actor,int $id,string $decision,string $reason): void
    {
        $this->authorize($actor);$reason=BusinessOperationInput::reason($reason);
        $this->stock->transaction(function() use($actor,$id,$decision,$reason): void {
            $operation=$this->operations->find($id,true)??throw new HttpException(404,'Operation not found.');
            if(!in_array($decision,['Approved','Rejected','Cancelled'],true)) { throw new ValidationException('Invalid decision.'); }
            if($decision==='Cancelled') {
                if($actor->role()!=='Admin' && (int)$operation['created_by']!==$actor->userId()) { throw new HttpException(403,'Forbidden'); }
                if(!in_array($operation['status'],['PendingApproval','Approved'],true)) { throw new ValidationException('This operation cannot be cancelled.'); }
            } else {
                if($actor->role()!=='Admin') { throw new HttpException(403,'Forbidden'); }
                if((int)$operation['created_by']===$actor->userId()) { throw new HttpException(403,'Another Admin must approve or reject this proposal.'); }
                if($operation['status']!=='PendingApproval') { throw new ValidationException('Only pending operations can be reviewed.'); }
            }
            $this->operations->decide($id,$decision,$actor->userId(),$reason);
            $this->audit->append($actor->userId(),'inventory-operations.decide',$operation['kind'],$id,'success','','',['decision'=>$decision,'reason'=>$reason]);
        });
    }
    public function post(AuthContext $actor,int $id): void
    {
        $this->authorize($actor);
        $this->stock->transaction(function() use($actor,$id): void {
            // Return source locks precede operation locks; no competing return holds its operation while waiting for the same allowance.
            $preview=$this->operations->find($id)??throw new HttpException(404,'Operation not found.');
            $sources=[];
            foreach($preview['items'] as $item) { if($item['source_ledger_id']!==null) { $sources[]=(int)$item['source_ledger_id']; } }
            sort($sources,SORT_NUMERIC);foreach(array_unique($sources) as $source) { $this->operations->source($source,true); }
            $operation=$this->operations->find($id,true)??throw new HttpException(404,'Operation not found.');
            if($operation['status']==='Posted') { return; }
            if($operation['status']!=='Approved' || $operation['approved_by']===null || (int)$operation['approved_by']===(int)$operation['created_by']) { throw new ValidationException('Independent approval is required before posting.'); }
            $deltas=[];$returns=[];$warehouse=(int)$operation['warehouse_id'];
            foreach($operation['items'] as $item) {
                $product=(int)$item['product_id'];$quantity=(int)$item['quantity'];
                if($this->operations->pair($product,$warehouse)===null) { throw new ValidationException('Product or warehouse is inactive.'); }
                if($operation['kind']==='Adjustment') { $deltas[]=new StockDelta($product,$warehouse,$quantity-(int)$item['baseline'],(int)$item['baseline']); }
                elseif($operation['kind']==='Transfer') {
                    $destination=(int)$operation['destination_id'];
                    if($this->operations->pair($product,$destination)===null) { throw new ValidationException('Destination is inactive.'); }
                    $deltas[]=new StockDelta($product,$warehouse,-$quantity);$deltas[]=new StockDelta($product,$destination,$quantity);
                } else {
                    $sourceId=(int)$item['source_ledger_id'];$source=$this->operations->source($sourceId,true)??throw new ValidationException('Original movement not found.');
                    if($this->operations->returned($sourceId)+$quantity>(int)$source['quantity']) { throw new ValidationException('Return exceeds remaining original quantity.'); }
                    $returns[$sourceId]=$quantity;$sign=$operation['kind']==='CustomerReturn'?1:-1;$deltas[]=new StockDelta($product,$warehouse,$sign*$quantity);
                }
            }
            $reference=match($operation['kind']) { 'Adjustment'=>'ADJUSTMENT','Transfer'=>'TRANSFER','SupplierReturn'=>'SUPPLIER_RETURN',default=>'CUSTOMER_RETURN'};
            $this->stock->adjust($deltas,$actor->userId(),$reference,$id,function() use($id,$actor,$returns): void {foreach($returns as $source=>$quantity) { $this->operations->recordReturn($source,$quantity); }$this->operations->posted($id,$actor->userId());});
        });
    }
    /** @return array<string,mixed> */
    public function show(AuthContext $actor,int $id): array {$this->authorize($actor);return $this->operations->find($id)??throw new HttpException(404,'Operation not found.');}
    /** @return array<string,mixed> */
    public function balance(AuthContext $actor,int $product,int $warehouse): array {$this->authorize($actor);return $this->operations->pair($product,$warehouse)??throw new HttpException(404,'Active stock pair not found.');}
    /** @return array<string,mixed> */
    public function source(AuthContext $actor,int $id): array {$this->authorize($actor);$source=$this->operations->source($id)??throw new HttpException(404,'Original movement not found.');if(!in_array($source['reference_type'],['PO','SO'],true)) { throw new ValidationException('Choose an original order movement.'); }return $source;}
    /** @param array<string,mixed> $input @return array<string,string> */
    public function filters(array $input): array
    {
        $result=[];foreach(['q','kind','status'] as $key){$value=$input[$key]??'';if(!is_string($value)||strlen($value)>120) { throw new ValidationException('Invalid filter.'); }$result[$key]=trim($value);}
        if($result['kind']!==''&&!in_array($result['kind'],self::KINDS,true)) { throw new ValidationException('Invalid kind.'); }
        if($result['status']!==''&&!in_array($result['status'],self::STATUSES,true)) { throw new ValidationException('Invalid status.'); }
        $warehouse=$input['warehouse_id']??'';if(!is_string($warehouse)||($warehouse!==''&&preg_match('/^[1-9][0-9]{0,9}$/',$warehouse)!==1)) { throw new ValidationException('Invalid warehouse.'); }$result['warehouse_id']=$warehouse;return $result;
    }
    /** @param array<string,mixed> $input @return PaginatedResult<array<string,mixed>> */
    public function search(AuthContext $actor,array $input): PaginatedResult
    {
        $this->authorize($actor);$filters=$this->filters($input);$pagination=Pagination::fromArray($input);$total=$this->operations->count($filters);return new PaginatedResult($this->operations->page($filters,10,$pagination->offsetForTotal($total)),$total,$pagination->pageForTotal($total),10);
    }
    /** @param array<string,mixed> $input @return PaginatedResult<array<string,mixed>> */
    public function recommendations(AuthContext $actor,array $input): PaginatedResult
    {
        $this->authorize($actor);$filters=$this->filters($input);$pagination=Pagination::fromArray($input);$warehouse=(int)$filters['warehouse_id'];$total=$this->operations->recommendationCount($filters['q'],$warehouse);return new PaginatedResult($this->operations->recommendations($filters['q'],10,$pagination->offsetForTotal($total),$warehouse),$total,$pagination->pageForTotal($total),10);
    }
}
