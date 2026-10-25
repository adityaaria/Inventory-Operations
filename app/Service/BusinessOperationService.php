<?php
declare(strict_types=1);
namespace App\Service;
use App\Exception\{HttpException,ValidationException};
use App\Repository\Contract\{BusinessOperationRepositoryInterface,AuditLogRepositoryInterface};
use App\Security\AuthContext;
use App\Support\RequestOrigin;
use App\Support\{Pagination,PaginatedResult};
final class BusinessOperationService
{
    public const KINDS=['Adjustment','Transfer','SupplierReturn','CustomerReturn'];
    private const NOT_FOUND='Operation not found.';
    private const SOURCE_NOT_FOUND='Original movement not found.';
    /** Dispatched/Received apply to transfers only (D-15, ADR-021); every other kind ends Posted. */
    public const STATUSES=['PendingApproval','Approved','Rejected','Posted','Cancelled','Dispatched','Received'];
    /** $units (D-16, ADR-022) converts each line's entered unit to base; without it every line is in the base unit. */
    public function __construct(private readonly BusinessOperationRepositoryInterface $operations,private readonly StockService $stock,private readonly AuditLogRepositoryInterface $audit,private readonly ?ProductUnitCatalog $units=null,private readonly ?\App\Repository\Contract\ProductRepositoryInterface $products=null) {}
    public function authorize(AuthContext $actor): void { if(!in_array($actor->role(),['Admin','WarehouseStaff'],true)) { throw new HttpException(403,'Forbidden'); } }
    /** @param list<array<string,mixed>> $items */
    public function propose(AuthContext $actor,string $kind,int $warehouse,?int $destination,string $reason,array $items): int
    {
        $this->authorize($actor);$reason=BusinessOperationInput::reason($reason);
        if(!in_array($kind,self::KINDS,true) || $items===[] || count($items)>100) { throw new ValidationException('Invalid operation or items.'); }
        if($kind==='Transfer' && ($destination===null || $destination===$warehouse)) { throw new ValidationException('Choose different source and destination warehouses.'); }
        if($kind!=='Transfer') { $destination=null; }
        return $this->stock->transaction(fn(): int => $this->createProposal($actor,$kind,$warehouse,$destination,$reason,$items));
    }
    public function decide(AuthContext $actor,int $id,string $decision,string $reason): void
    {
        $this->authorize($actor);$reason=BusinessOperationInput::reason($reason);
        $this->stock->transaction(function() use($actor,$id,$decision,$reason): void {
            $operation=$this->operations->find($id,true)??throw new HttpException(404,self::NOT_FOUND);
            if(!in_array($decision,['Approved','Rejected','Cancelled'],true)) { throw new ValidationException('Invalid decision.'); }
            $this->assertCanDecide($actor,$operation,$decision);
            $this->operations->decide($id,$decision,$actor->userId(),$reason);
            $this->audit->append($actor->userId(),'inventory-operations.decide',$operation['kind'],$id,'success',RequestOrigin::none(),['decision'=>$decision,'reason'=>$reason]);
        });
    }
    public function post(AuthContext $actor,int $id): void
    {
        $this->authorize($actor);
        // A transfer is no longer applied in one step (D-15, ADR-021): posting it means dispatching it.
        if((($this->operations->find($id)??throw new HttpException(404,self::NOT_FOUND))['kind'])==='Transfer') { $this->dispatch($actor,$id);return; }
        $this->stock->transaction(function() use($actor,$id): void {
            $this->lockReturnSources($id);
            $operation=$this->operations->find($id,true)??throw new HttpException(404,self::NOT_FOUND);
            if($operation['status']==='Posted') { return; }
            $this->assertIndependentlyApproved($operation);
            [$deltas,$returns]=$this->postingMovements($operation);
            $reference=match($operation['kind']) { 'Adjustment'=>'ADJUSTMENT','SupplierReturn'=>'SUPPLIER_RETURN',default=>'CUSTOMER_RETURN'};
            $this->stock->adjust($deltas,$actor->userId(),$reference,$id,function() use($id,$actor,$returns): void {foreach($returns as $source=>$quantity) { $this->operations->recordReturn($source,$quantity); }$this->operations->posted($id,$actor->userId());});
        });
    }
    /**
     * Transfer Approved → Dispatched (D-15, ADR-021). Admin/WarehouseStaff (403 before any lock). One transaction: lock the
     * transfer, a repeated dispatch of a Dispatched/Received transfer is a no-op, independent approval is required, then
     * StockService locks the source rows in product order, checks available (quantity − reserved) after the lock, lowers
     * quantity, appends the negative TRANSFER Adjustment rows, marks the lines dispatched and writes the strict audit row.
     */
    public function dispatch(AuthContext $actor,int $id): void
    {
        $this->authorize($actor);
        $this->stock->transaction(function() use($actor,$id): void {
            $operation=$this->operations->find($id,true)??throw new HttpException(404,self::NOT_FOUND);
            if($operation['kind']!=='Transfer') { throw new ValidationException('Only transfers are dispatched.'); }
            if(in_array($operation['status'],['Dispatched','Received'],true)) { return; }
            $this->assertIndependentlyApproved($operation);
            $source=(int)$operation['warehouse_id'];$destination=(int)$operation['destination_id'];$movements=[];
            foreach($operation['items'] as $item) {
                $product=(int)$item['product_id'];
                if($this->operations->pair($product,$source)===null) { throw new ValidationException('Product or warehouse is inactive.'); }
                if($this->operations->pair($product,$destination)===null) { throw new ValidationException('Destination is inactive.'); }
                $movements[]=new StockMovement($product,(int)$item['quantity']);
            }
            // Lots are picked FEFO over non-expired lots (D-17, ADR-023); the transfer remembers them per line.
            $this->stock->dispatchTransfer($source,$movements,$actor->userId(),$id,function(array $picked=[]) use($id,$actor): void {$this->operations->dispatched($id,$actor->userId());$this->operations->recordTransferLots($id,$picked);},['destination_id'=>$destination]);
        });
    }
    /**
     * Transfer Dispatched → Received (D-15, ADR-021). Single, complete receipt: every line gets good and damaged units
     * (good + damaged ≤ dispatched, the rest is short). Without $lines every dispatched unit arrives in good condition
     * (trusted callers). Any damaged or short unit needs a reason. Good units enter the destination through StockService;
     * damaged and short units are written off on the document (no stock, no ledger row). A repeated receipt of a Received
     * transfer is a no-op; cancellation after dispatch is impossible, so a lost shipment is received with 0 good units.
     *
     * @param array<int|string,mixed>|null $lines product id => ['good'=>int,'damaged'=>int]
     */
    public function receive(AuthContext $actor,int $id,?array $lines=null,?string $reason=null): void
    {
        $this->authorize($actor);
        $parsed=$lines===null?null:$this->parseReceiptLines($lines);
        $this->stock->transaction(function() use($actor,$id,$parsed,$reason): void {
            $operation=$this->operations->find($id,true)??throw new HttpException(404,self::NOT_FOUND);
            if($operation['kind']!=='Transfer') { throw new ValidationException('Only transfers are received.'); }
            if($operation['status']==='Received') { return; }
            if($operation['status']!=='Dispatched') { throw new ValidationException('Only a dispatched transfer can be received.'); }
            [$receipt,$good,$damaged,$short]=$this->receiptLines($operation['items'],$parsed);
            $discrepancy=$damaged+$short>0;
            $note=null;
            if($discrepancy) {
                try { $note=BusinessOperationInput::reason($reason); }
                catch(ValidationException) { throw new ValidationException('Enter a reason for the damaged or missing units (at most 500 characters).'); }
            } elseif(is_string($reason) && trim($reason)!=='') { $note=BusinessOperationInput::reason($reason); }
            [$movements,$lotRows]=$this->transferReceiptLots($operation['items'],$receipt);
            $metadata=['source_warehouse_id'=>(int)$operation['warehouse_id'],'received_units'=>$good,'damaged_units'=>$damaged,'short_units'=>$short,'written_off_units'=>$damaged+$short,
                'receipt'=>array_map(static fn(int $product,array $line): array => ['product_id'=>$product]+$line,array_keys($receipt),array_values($receipt))];
            if($note!==null) { $metadata['reason']=$note; }
            $this->stock->receiveTransfer((int)$operation['destination_id'],$movements,$actor->userId(),$id,function(array $destinationLots=[]) use($id,$actor,$receipt,$note,$lotRows): void {
                $this->operations->received($id,$actor->userId(),$receipt,$note);
                foreach($lotRows as $product=>$rows) {
                    $next=0;
                    foreach($rows as $index=>$row) { if($row['received']>0) { $rows[$index]['destination_lot_id']=$destinationLots[$product][$next++]??null; } }
                    $this->operations->receiveTransferLots($id,$rows);
                }
            },$metadata);
        });
    }
    /**
     * Per-lot receipt of a transfer (D-17, ADR-023): each line's good units fill its dispatched lots in dispatch order,
     * then the damaged units, then the short units. Good units enter the destination lot with the same code, expiry and
     * unit cost. A transfer dispatched before the lot migration has no lot rows: its good units go to the LEGACY lot.
     *
     * @param list<array<string,mixed>> $items
     * @param array<int,array{good:int,damaged:int,short:int}> $receipt
     * @return array{0:list<StockMovement>,1:array<int,list<array{id:int,received:int,damaged:int,short:int,destination_lot_id:int|null}>>}
     */
    private function transferReceiptLots(array $items,array $receipt): array
    {
        $movements=[];$lotRows=[];
        foreach($items as $item) {
            $product=(int)$item['product_id'];$line=$receipt[$product];
            $lots=$item['lots']??[];
            if($lots===[]) { if($line['good']>0) { $movements[]=new StockMovement($product,$line['good'],[new LotSplit(\App\Entity\StockLot::LEGACY,null,$line['good'],null)]); } continue; }
            $good=$line['good'];$damaged=$line['damaged'];$splits=[];
            foreach($lots as $lot) {
                $left=(int)$lot['dispatched_quantity'];
                $g=min($good,$left);$good-=$g;$left-=$g;
                $d=min($damaged,$left);$damaged-=$d;$left-=$d;
                $lotRows[$product][]=['id'=>(int)$lot['id'],'received'=>$g,'damaged'=>$d,'short'=>$left,'destination_lot_id'=>null];
                if($g>0) { $splits[]=new LotSplit((string)$lot['lot_code'],$lot['expiry_date']===null?null:(string)$lot['expiry_date'],$g,(string)$lot['unit_cost']); }
            }
            if($splits!==[]) { $movements[]=new StockMovement($product,$line['good'],$splits); }
        }
        return [$movements,$lotRows];
    }
    /** @param array<int|string,mixed> $lines @return array<int,array{good:int,damaged:int}> */
    private function parseReceiptLines(array $lines): array
    {
        if($lines===[] || count($lines)>100) { throw new ValidationException('Enter the received quantities for every line.'); }
        $parsed=[];
        foreach($lines as $product=>$line) {
            if(!is_array($line)) { throw new ValidationException('Invalid receipt line.'); }
            $productId=BusinessOperationInput::quantity((string)$product);
            try { $parsed[$productId]=['good'=>BusinessOperationInput::quantity($line['good']??null,true),'damaged'=>BusinessOperationInput::quantity(($line['damaged']??'')===''?'0':$line['damaged'],true)]; }
            catch(ValidationException) { throw new ValidationException('Received and damaged quantities must be whole numbers of zero or more.'); }
        }
        return $parsed;
    }
    /**
     * @param list<array<string,mixed>> $items
     * @param array<int,array{good:int,damaged:int}>|null $parsed
     * @return array{0:array<int,array{good:int,damaged:int,short:int}>,1:int,2:int,3:int}
     */
    private function receiptLines(array $items,?array $parsed): array
    {
        $receipt=[];$good=$damaged=$short=0;
        foreach($items as $item) {
            $product=(int)$item['product_id'];$dispatched=(int)$item['dispatched_quantity'];
            $line=$parsed===null?['good'=>$dispatched,'damaged'=>0]:($parsed[$product]??throw new ValidationException('Enter the received quantities for every line.'));
            if($line['good']+$line['damaged']>$dispatched) { throw new ValidationException('Received plus damaged units cannot exceed the '.$dispatched.' dispatched unit(s) of '.$item['sku'].'.'); }
            $receipt[$product]=['good'=>$line['good'],'damaged'=>$line['damaged'],'short'=>$dispatched-$line['good']-$line['damaged']];
            $good+=$line['good'];$damaged+=$line['damaged'];$short+=$receipt[$product]['short'];
        }
        if($parsed!==null && array_diff_key($parsed,$receipt)!==[]) { throw new ValidationException('A receipt line does not belong to this transfer.'); }
        return [$receipt,$good,$damaged,$short];
    }
    /** @param array<string,mixed> $operation */
    private function assertIndependentlyApproved(array $operation): void
    {
        if($operation['status']!=='Approved' || $operation['approved_by']===null || (int)$operation['approved_by']===(int)$operation['created_by']) { throw new ValidationException('Independent approval is required before posting.'); }
    }
    /** Open dispatched transfers (Admin/WarehouseStaff). @param array<string,mixed> $input @return PaginatedResult<array<string,mixed>> */
    public function inTransit(AuthContext $actor,array $input): PaginatedResult
    {
        $this->authorize($actor);$pagination=Pagination::fromArray($input);$total=$this->operations->inTransitCount();
        return new PaginatedResult($this->operations->inTransit(10,$pagination->offsetForTotal($total)),$total,$pagination->pageForTotal($total),10);
    }
    /** Runs inside the proposal transaction. @param list<array<string,mixed>> $items */
    private function createProposal(AuthContext $actor,string $kind,int $warehouse,?int $destination,string $reason,array $items): int
    {
        $lines=[];$seen=[];$seenLots=[];$returnWarehouse=null;$isReturn=in_array($kind,['SupplierReturn','CustomerReturn'],true);
        foreach($items as $item) {
            $sourceId=null;
            if($kind==='CustomerReturn' && ($item['fit_for_stock']??false)!==true) { throw new ValidationException('Confirm that all returned goods are fit for stock.'); }
            $product=$isReturn?0:BusinessOperationInput::quantity($item['product_id']??0);
            $unitQuantity=BusinessOperationInput::quantity($item['quantity']??null,$kind==='Adjustment');
            if($isReturn) { $product=(int)(($this->operations->source(BusinessOperationInput::quantity($item['source_ledger_id']??null))??throw new ValidationException(self::SOURCE_NOT_FOUND))['product_id']); }
            // The line quantity is entered in a unit of the product (D-16, ADR-022) and stored in base units.
            [$unitCode,$factor]=$this->lineUnit($product,$item['unit_code']??null);
            $quantity=\App\Support\UnitSet::toBase($unitQuantity,$factor);
            if($isReturn) {
                [$sourceId,$product,$warehouse]=$this->returnSource($kind,$item,$quantity,$returnWarehouse);
                $returnWarehouse=$warehouse;
            }
            $lot=$kind==='Adjustment'?$this->adjustmentLot($product,$warehouse,$item):['mode'=>'pair'];
            $lotKey=$product.':'.match($lot['mode']){'lot'=>(string)$lot['lot_id'],'new'=>'new',default=>'pair'};
            if(isset($seenLots[$lotKey]) || ($lot['mode']==='pair'?isset($seen[$product]):isset($seenLots[$product.':pair']))) { throw new ValidationException('Duplicate product items are not allowed (one line per product and lot).'); }
            $pair=$this->activeLinePair($product,$warehouse,$destination,[]);$seen[$product]=true;$seenLots[$lotKey]=true;
            $baseline=$kind==='Adjustment'?$this->adjustmentBaseline($item,$lot['mode']==='pair'?$pair:['quantity'=>$lot['quantity']],$quantity):null;
            $lines[]=['product_id'=>$product,'quantity'=>$quantity,'baseline'=>$baseline,'source_ledger_id'=>$sourceId,'unit_code'=>$unitCode,'unit_factor'=>$factor,'unit_quantity'=>$unitQuantity,
                'lot_id'=>$lot['mode']==='lot'?$lot['lot_id']:null,'lot_code'=>$lot['mode']==='new'?$lot['lot_code']:null,'expiry_date'=>$lot['mode']==='new'?$lot['expiry_date']:null];
        }
        if($this->units!==null) { $this->units->confirmSnapshots(array_values(array_filter($lines,static fn(array $line): bool => $line['unit_code']!==null))); }
        $id=$this->operations->create(['kind'=>$kind,'warehouse_id'=>$warehouse,'destination_id'=>$destination,'reason'=>$reason,'created_by'=>$actor->userId(),'condition_confirmed'=>$kind==='CustomerReturn'?1:0],$lines);
        $this->audit->append($actor->userId(),'inventory-operations.propose',$kind,$id,'success',RequestOrigin::none(),[]);return $id;
    }
    /**
     * Lot of an adjustment line (D-17, ADR-023): an existing lot (`lot_id`, baseline = its quantity), a new lot (`lot_code`
     * and, for products that track expiry, `expiry_date`; baseline 0) or the whole pair (neither; former behaviour).
     * @param array<string,mixed> $item
     * @return array{mode:string,lot_id?:int,quantity?:int,lot_code?:string,expiry_date?:string|null}
     */
    private function adjustmentLot(int $product,int $warehouse,array $item): array
    {
        $lotId=$item['lot_id']??null;
        if($lotId!==null && $lotId!=='' && $lotId!==0) {
            $lot=$this->operations->lot(BusinessOperationInput::quantity($lotId))??throw new ValidationException('Lot not found.');
            if((int)$lot['product_id']!==$product || (int)$lot['warehouse_id']!==$warehouse) { throw new ValidationException('The lot does not belong to this product and warehouse.'); }
            return ['mode'=>'lot','lot_id'=>(int)$lot['id'],'quantity'=>(int)$lot['quantity']];
        }
        $code=$item['lot_code']??null;
        if(is_string($code) && trim($code)!=='') {
            $code=\App\Validation\LotInput::code($code);$expiry=\App\Validation\LotInput::expiry($item['expiry_date']??null);
            if($expiry===null && $this->products?->findById($product)?->tracksExpiry()===true) { throw new ValidationException('This product tracks expiry: enter the expiry date of the new lot.'); }
            foreach($this->operations->lots($product,$warehouse) as $existing) {
                if(strcasecmp((string)$existing['lot_code'],$code)===0 && ($existing['expiry_date']??null)===$expiry) { throw new ValidationException('Lot '.$code.' already exists in this warehouse; choose it from the lot list to count it.'); }
            }
            return ['mode'=>'new','lot_code'=>$code,'expiry_date'=>$expiry,'quantity'=>0];
        }
        return ['mode'=>'pair'];
    }
    /**
     * Admin-approved write-off of expired lots (D-17, ADR-023), reusing the Adjustment flow: one line per expired lot of the
     * warehouse with stock (count 0, baseline = lot quantity), at most 100 lots, earliest expiry first. Another Admin
     * approves; posting re-checks after the lock that every lot is still expired and unchanged.
     */
    public function proposeExpiredWriteOff(AuthContext $actor,int $warehouse,string $reason,?int $productId=null): int
    {
        $this->authorize($actor);$reason=BusinessOperationInput::reason($reason);
        return $this->stock->transaction(function() use($actor,$warehouse,$reason,$productId): int {
            $lots=$this->operations->expiredLots($warehouse,$productId,100);
            if($lots===[]) { throw new ValidationException('There are no expired lots with stock in this warehouse.'); }
            $lines=[];
            foreach($lots as $lot) {
                $product=(int)$lot['product_id'];
                if($this->operations->pair($product,$warehouse)===null) { continue; }
                $lines[]=['product_id'=>$product,'quantity'=>0,'baseline'=>(int)$lot['quantity'],'source_ledger_id'=>null,'unit_code'=>null,'unit_factor'=>1,'unit_quantity'=>0,'lot_id'=>(int)$lot['id'],'lot_code'=>null,'expiry_date'=>null];
            }
            if($lines===[]) { throw new ValidationException('There are no expired lots of active products in this warehouse.'); }
            $id=$this->operations->create(['kind'=>'Adjustment','warehouse_id'=>$warehouse,'destination_id'=>null,'reason'=>$reason,'created_by'=>$actor->userId(),'condition_confirmed'=>0,'purpose'=>'ExpiredWriteOff'],$lines);
            $this->audit->append($actor->userId(),'inventory-operations.propose','Adjustment',$id,'success',RequestOrigin::none(),['purpose'=>'ExpiredWriteOff','lots'=>count($lines)]);
            return $id;
        });
    }
    /**
     * Unit of a stock-operation line: [code, factor]. Empty means the base unit; without a unit catalog the code is left
     * null so the repository snapshots products.unit with factor 1.
     * @return array{0:string|null,1:int}
     */
    private function lineUnit(int $product,mixed $code): array
    {
        $code=is_string($code)?trim($code):'';
        $entity=$product>0?$this->products?->findById($product):null;
        if($this->units===null || $entity===null) {
            if($code!=='' && ($entity===null || strcasecmp($code,$entity->unit())!==0)) { throw new ValidationException('Unit "'.$code.'" is not available for this product.'); }
            return [$entity?->unit(),1];
        }
        $unit=$this->units->forProduct($entity)->resolve($code,$entity->sku());
        return [$unit->code(),$unit->factor()];
    }
    /**
     * The product must be active in the source (and destination) warehouse and not already on the proposal.
     * @param array<int,bool> $seen
     * @return array<string,mixed>
     */
    private function activeLinePair(int $product,int $warehouse,?int $destination,array $seen): array
    {
        $pair=$this->operations->pair($product,$warehouse)??throw new ValidationException('Choose an active product and warehouse.');
        if(isset($seen[$product])) { throw new ValidationException('Duplicate product items are not allowed.'); }
        if($destination!==null && $this->operations->pair($product,$destination)===null) { throw new ValidationException('Choose an active destination warehouse.'); }
        return $pair;
    }
    /**
     * Validates a return line against its original PO receipt or SO issue; every line must share that movement's warehouse.
     * @param array<string,mixed> $item
     * @return array{0:int,1:int,2:int} source ledger id, product id, warehouse id
     */
    private function returnSource(string $kind,array $item,int $quantity,?int $returnWarehouse): array
    {
        $sourceId=BusinessOperationInput::quantity($item['source_ledger_id']??null);
        $source=$this->operations->source($sourceId)??throw new ValidationException(self::SOURCE_NOT_FOUND);
        $expected=$kind==='SupplierReturn'?'Receipt':'Issue';$reference=$kind==='SupplierReturn'?'PO':'SO';
        if($source['movement_type']!==$expected || $source['reference_type']!==$reference) { throw new ValidationException('Return must reference the original PO receipt or SO issue.'); }
        $sourceWarehouse=(int)$source['warehouse_id'];
        if($returnWarehouse!==null && $returnWarehouse!==$sourceWarehouse) { throw new ValidationException('All return items must use the original warehouse.'); }
        if($quantity>(int)$source['quantity']) { throw new ValidationException('Return exceeds original movement.'); }
        return [$sourceId,(int)$source['product_id'],$sourceWarehouse];
    }
    /** @param array<string,mixed> $item @param array<string,mixed> $pair */
    private function adjustmentBaseline(array $item,array $pair,int $quantity): int
    {
        $baseline=BusinessOperationInput::quantity($item['baseline']??null,true);
        if($baseline!==(int)$pair['quantity']) { throw new HttpException(409,'Stock changed since the count. Recount before proposing.'); }
        if($quantity===$baseline) { throw new ValidationException('Count must differ from current stock.'); }
        return $baseline;
    }
    /** @param array<string,mixed> $operation */
    private function assertCanDecide(AuthContext $actor,array $operation,string $decision): void
    {
        if($decision==='Cancelled') {
            if($actor->role()!=='Admin' && (int)$operation['created_by']!==$actor->userId()) { throw new HttpException(403,'Forbidden'); }
            if(!in_array($operation['status'],['PendingApproval','Approved'],true)) { throw new ValidationException('This operation cannot be cancelled.'); }
            return;
        }
        if($actor->role()!=='Admin') { throw new HttpException(403,'Forbidden'); }
        if((int)$operation['created_by']===$actor->userId()) { throw new HttpException(403,'Another Admin must approve or reject this proposal.'); }
        if($operation['status']!=='PendingApproval') { throw new ValidationException('Only pending operations can be reviewed.'); }
    }
    /** Return source locks precede operation locks; no competing return holds its operation while waiting for the same allowance. */
    private function lockReturnSources(int $id): void
    {
        $preview=$this->operations->find($id)??throw new HttpException(404,self::NOT_FOUND);
        $sources=[];
        foreach($preview['items'] as $item) { if($item['source_ledger_id']!==null) { $sources[]=(int)$item['source_ledger_id']; } }
        sort($sources,SORT_NUMERIC);foreach(array_unique($sources) as $source) { $this->operations->source($source,true); }
    }
    /**
     * @param array<string,mixed> $operation
     * @return array{0:list<StockDelta>,1:array<int,int>} stock deltas and returned quantity per source ledger id
     */
    private function postingMovements(array $operation): array
    {
        $deltas=[];$returns=[];$warehouse=(int)$operation['warehouse_id'];
        foreach($operation['items'] as $item) {
            $product=(int)$item['product_id'];$quantity=(int)$item['quantity'];
            if($this->operations->pair($product,$warehouse)===null) { throw new ValidationException('Product or warehouse is inactive.'); }
            if($operation['kind']==='Adjustment') {
                // Per-lot counts, new lots and the expired write-off (D-17, ADR-023); a line without a lot counts the pair.
                $baseline=(int)$item['baseline'];
                $target=match(true) {
                    ($item['lot_id']??null)!==null=>LotTarget::lot((int)$item['lot_id'],($operation['purpose']??'Standard')==='ExpiredWriteOff'),
                    ($item['lot_code']??null)!==null=>LotTarget::create(new LotSplit((string)$item['lot_code'],$item['expiry_date']===null?null:(string)$item['expiry_date'],max(1,$quantity-$baseline),null)),
                    default=>null,
                };
                $deltas[]=new StockDelta($product,$warehouse,$quantity-$baseline,($item['lot_code']??null)!==null?null:$baseline,$target);
            }
            else {
                $sourceId=(int)$item['source_ledger_id'];
                $source=$this->assertReturnAllowance($sourceId,$quantity);
                // Returns use the original movement's lot; pre-migration movements (no lot) use the pair's LEGACY lot.
                $target=($source['lot_id']??null)!==null?LotTarget::lot((int)$source['lot_id']):LotTarget::legacy();
                $returns[$sourceId]=$quantity;$sign=$operation['kind']==='CustomerReturn'?1:-1;$deltas[]=new StockDelta($product,$warehouse,$sign*$quantity,null,$target);
            }
        }
        return [$deltas,$returns];
    }
    /** @return array<string,mixed> the locked source ledger row */
    private function assertReturnAllowance(int $sourceId,int $quantity): array
    {
        $source=$this->operations->source($sourceId,true)??throw new ValidationException(self::SOURCE_NOT_FOUND);
        if($this->operations->returned($sourceId)+$quantity>(int)$source['quantity']) { throw new ValidationException('Return exceeds remaining original quantity.'); }
        return $source;
    }
    /** @return array<string,mixed> */
    public function show(AuthContext $actor,int $id): array {$this->authorize($actor);return $this->operations->find($id)??throw new HttpException(404,self::NOT_FOUND);}
    /** @return array<string,mixed> */
    public function balance(AuthContext $actor,int $product,int $warehouse): array {$this->authorize($actor);$pair=$this->operations->pair($product,$warehouse)??throw new HttpException(404,'Active stock pair not found.');$pair['lots']=$this->operations->lots($product,$warehouse);return $pair;}
    /** @return array<string,mixed> */
    public function source(AuthContext $actor,int $id): array {$this->authorize($actor);$source=$this->operations->source($id)??throw new HttpException(404,self::SOURCE_NOT_FOUND);if(!in_array($source['reference_type'],['PO','SO'],true)) { throw new ValidationException('Choose an original order movement.'); }return $source;}
    /** @param array<string,mixed> $input @return array<string,string> */
    public function filters(array $input): array
    {
        $result=[];foreach(['q','kind','status'] as $key){$value=$input[$key]??'';if(!is_string($value)||strlen($value)>120) { throw new ValidationException('Invalid filter.'); }$result[$key]=trim($value);}
        if($result['kind']!==''&&!in_array($result['kind'],self::KINDS,true)) { throw new ValidationException('Invalid kind.'); }
        if($result['status']!==''&&!in_array($result['status'],self::STATUSES,true)) { throw new ValidationException('Invalid status.'); }
        $warehouse=$input['warehouse_id']??'';if(!is_string($warehouse)||($warehouse!==''&&preg_match('/^[1-9]\d{0,9}$/',$warehouse)!==1)) { throw new ValidationException('Invalid warehouse.'); }$result['warehouse_id']=$warehouse;return $result;
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
