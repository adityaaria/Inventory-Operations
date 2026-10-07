<?php
declare(strict_types=1);
namespace App\Service;
use App\Repository\Contract\WorkQueueRepositoryInterface;
use App\Security\AuthContext;
use App\Support\{Pagination,PaginatedResult};
use App\Exception\{HttpException,ValidationException};
final class WorkQueueService
{
    public function __construct(private readonly WorkQueueRepositoryInterface $queues) {}
    /** @return array<string,string> */
    public function types(AuthContext $actor): array
    {
        return match($actor->role()) {
            'Admin'=>['SOApproval'=>'SO approval','OperationApproval'=>'Stock proposal approval','Receipt'=>'PO receipt','Issue'=>'SO issue','Posting'=>'Stock posting'],
            'WarehouseStaff'=>['Receipt'=>'PO receipt','Issue'=>'SO issue','Posting'=>'Stock posting'],
            'Sales'=>['SalesDraft'=>'My draft SO','SalesFollowUp'=>'My SO follow-up'],
            default=>throw new HttpException(403,'Forbidden'),
        };
    }
    /** @param array<string,mixed> $input @return array{type:string,q:string} */
    public function filters(AuthContext $actor,array $input): array
    {
        $types=$this->types($actor);$type=$input['type']??'';$q=$input['q']??'';
        if(!is_string($type)||!is_string($q)||strlen($q)>120||($type!==''&&!isset($types[$type]))) { throw new ValidationException('Invalid work queue filter.'); }
        return ['type'=>$type,'q'=>trim($q)];
    }
    /** @param array<string,mixed> $input @return array{result:PaginatedResult<array<string,mixed>>,counts:array<string,int>,filters:array{type:string,q:string},types:array<string,string>} */
    public function search(AuthContext $actor,array $input): array
    {
        $filters=$this->filters($actor,$input);$types=$this->types($actor);$counts=$this->queues->counts($actor->role(),$actor->userId(),$filters['type'],$filters['q']);$total=array_sum($counts);$pagination=Pagination::fromArray($input);
        $rows=$this->queues->page($actor->role(),$actor->userId(),$filters['type'],$filters['q'],10,$pagination->offsetForTotal($total));
        foreach($rows as &$row){$path=match($row['task_type']){'Receipt'=>'/purchase-orders/show','Posting','OperationApproval'=>'/inventory-operations/show',default=>'/sales-orders/show'};$row['url']=$path.'?id='.(int)$row['id'];}unset($row);
        return ['result'=>new PaginatedResult($rows,$total,$pagination->pageForTotal($total),10),'counts'=>$counts,'filters'=>$filters,'types'=>$types];
    }
}
