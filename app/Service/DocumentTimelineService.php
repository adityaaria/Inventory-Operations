<?php
declare(strict_types=1);
namespace App\Service;
use App\Repository\Contract\DocumentTimelineRepositoryInterface;
use App\Security\AuthContext;
use App\Exception\HttpException;
final class DocumentTimelineService
{
    public function __construct(private readonly DocumentTimelineRepositoryInterface $history) {}
    /** @return array{events:list<array<string,mixed>>,limited:bool,links:list<array{kind:string,id:int}>} */
    public function forDocument(AuthContext $actor,string $kind,int $id): array
    {
        if(!in_array($kind,['PO','SO','Operation'],true)||$id<1) { throw new HttpException(404,'Document not found.'); }
        if(!in_array($actor->role(),['Admin','WarehouseStaff','Sales'],true)||($actor->role()==='Sales'&&$kind!=='SO')) { throw new HttpException(403,'Forbidden'); }
        $document=$this->history->document($kind,$id)??throw new HttpException(404,'Document not found.');
        if($actor->role()==='Sales'&&(int)$document['created_by']!==$actor->userId()) { throw new HttpException(403,'Forbidden'); }
        $rows=$this->history->events($kind,$id,$actor->role()!=='Sales',101);$limited=count($rows)>100;$rows=array_slice($rows,0,100);
        return ['events'=>array_map(fn(array $row): array => $this->presentEvent($row),$rows),'limited'=>$limited,'links'=>$this->history->links($kind,$id)];
    }
    /** Turns a stored event into its display form: readable title, reason/decision from metadata, link to the stock operation. @param array<string,mixed> $row @return array<string,mixed> */
    private function presentEvent(array $row): array
    {
        $metadata=json_decode((string)$row['metadata'],true);$reason=is_array($metadata)?($metadata['reason']??''):'';$decision=is_array($metadata)?($metadata['decision']??''):'';
        $row['title']=match($row['title']??'') {
            'purchase-orders.order'=>'PO ordered','purchase-orders.cancel'=>'PO cancelled','purchase-orders.close-remainder'=>'PO remainder closed',
            'sales-orders.submit'=>'SO submitted','sales-orders.cancel'=>'SO cancelled','sales-orders.reject'=>'SO rejected',
            'sales-orders.issue'=>'Goods issued (shipment)','sales-orders.close-remainder'=>'SO remaining fulfilment closed',
            'inventory-operations.dispatch'=>'Transfer dispatched (in transit)','inventory-operations.receive'=>'Transfer received',
            'inventory-operations.decide'=>match($decision){'Approved'=>'Stock proposal approved','Rejected'=>'Stock proposal rejected','Cancelled'=>'Stock proposal cancelled',default=>'Stock proposal reviewed'},
            default=>$row['title']??'Recorded action',
        };
        // Reservation trace (D-12, ADR-018): approval reserves, cancellation of an Approved order releases.
        $reserved=is_array($metadata)?(int)($metadata['reserved_units']??0):0;$released=is_array($metadata)?(int)($metadata['reservation_released_units']??0):0;
        if($reserved>0) { $row['detail']='Reserved '.$reserved.' unit'.($reserved===1?'':'s').' at the source warehouse'; }
        elseif($row['title']==='Goods issued (shipment)') { $row['detail']=$this->shipmentDetail(is_array($metadata)?$metadata:[]); }
        elseif($row['title']==='Transfer dispatched (in transit)') { $units=is_array($metadata)?(int)($metadata['dispatched_units']??0):0;$row['detail']=$units.' unit'.($units===1?'':'s').' left the source warehouse and are in transit'; }
        elseif($row['title']==='Transfer received') { $row['detail']=$this->transferReceiptDetail(is_array($metadata)?$metadata:[]); }
        elseif($released>0) { $row['detail']='Released reservation of '.$released.' unit'.($released===1?'':'s'); }
        $row['reason']=is_string($reason)?$reason:'';$row['decision']=is_string($decision)?$decision:'';
        $row['url']=(int)$row['operation_id']>0?'/inventory-operations/show?id='.(int)$row['operation_id']:'';
        unset($row['metadata']);
        return $row;
    }
    /** Receipt summary of an `inventory-operations.receive` audit row (D-15, ADR-021). @param array<mixed> $metadata */
    private function transferReceiptDetail(array $metadata): string
    {
        $good=(int)($metadata['received_units']??0);$damaged=(int)($metadata['damaged_units']??0);$short=(int)($metadata['short_units']??0);
        $detail='Received '.$good.' good unit'.($good===1?'':'s');
        if($damaged+$short>0) { $detail.='; written off '.$damaged.' damaged and '.$short.' short'; }
        return $detail;
    }
    /** Shipment summary of a `sales-orders.issue` audit row (D-14, ADR-020); older rows carry only the released units. @param array<mixed> $metadata */
    private function shipmentDetail(array $metadata): string
    {
        if(!isset($metadata['shipment_units'])&&!isset($metadata['reservation_released_units'])) { return 'Goods issued; quantities in the Issue ledger rows'; } // rows written before D-12 carry no units
        $units=(int)($metadata['shipment_units']??$metadata['reservation_released_units']);
        $detail='Shipped '.$units.' unit'.($units===1?'':'s');
        if(isset($metadata['remaining_units'])) {
            $remaining=(int)$metadata['remaining_units'];
            $detail.=$remaining===0?'; order complete':'; '.$remaining.' unit'.($remaining===1?'':'s').' still to ship';
        }
        return $detail;
    }
}
