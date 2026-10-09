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
            'inventory-operations.decide'=>match($decision){'Approved'=>'Stock proposal approved','Rejected'=>'Stock proposal rejected','Cancelled'=>'Stock proposal cancelled',default=>'Stock proposal reviewed'},
            default=>$row['title']??'Recorded action',
        };
        $row['reason']=is_string($reason)?$reason:'';$row['decision']=is_string($decision)?$decision:'';
        $row['url']=(int)$row['operation_id']>0?'/inventory-operations/show?id='.(int)$row['operation_id']:'';
        unset($row['metadata']);
        return $row;
    }
}
