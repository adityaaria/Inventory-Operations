<?php
declare(strict_types=1);
namespace App\Repository\MySql;
use App\Repository\Contract\WorkQueueRepositoryInterface;
use PDO;
final class MySqlWorkQueueRepository implements WorkQueueRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}
    /** @return array{0:string,1:array<string,int|string>} */
    private function dataset(string $role,int $actor,string $type,string $query): array
    {
        $parts=[];$params=[];
        if($role==='Admin') {
            $parts[]="SELECT 'SOApproval' AS task_type,id,order_number AS label,status,created_at,'Approve or reject' AS action FROM sales_orders WHERE status='PendingApproval'";
            $parts[]="SELECT 'OperationApproval' AS task_type,id,CONCAT(kind,' #',id,' — ',reason) AS label,status,created_at,'Review proposal' AS action FROM inventory_operations WHERE status='PendingApproval' AND created_by<>:review_actor";
            $params['review_actor']=$actor;
        }
        if(in_array($role,['Admin','WarehouseStaff'],true)) {
            $parts[]="SELECT 'Receipt' AS task_type,po.id,po.order_number AS label,po.status,po.created_at,'Receive goods' AS action FROM purchase_orders po LEFT JOIN purchase_order_closures c ON c.purchase_order_id=po.id WHERE po.status IN ('Ordered','PartiallyReceived') AND c.purchase_order_id IS NULL";
            $parts[]="SELECT 'Issue' AS task_type,id,order_number AS label,status,created_at,'Issue goods' AS action FROM sales_orders WHERE status='Approved'";
            $parts[]="SELECT 'Posting' AS task_type,id,CONCAT(kind,' #',id,' — ',reason) AS label,status,created_at,'Post stock changes' AS action FROM inventory_operations WHERE status='Approved'";
        }
        if($role==='Sales') {
            $parts[]="SELECT 'SalesDraft' AS task_type,id,order_number AS label,status,created_at,'Review and submit' AS action FROM sales_orders WHERE status='Draft' AND created_by=:draft_actor";
            $parts[]="SELECT 'SalesFollowUp' AS task_type,id,order_number AS label,status,created_at,'Track progress' AS action FROM sales_orders WHERE status IN ('PendingApproval','Approved') AND created_by=:follow_actor";
            $params['draft_actor']=$actor;$params['follow_actor']=$actor;
        }
        if($parts===[]) { throw new \LogicException('Unknown queue role.'); }
        $sql='('.implode(' UNION ALL ',$parts).') tasks WHERE 1=1';
        if($type!==''){$sql.=' AND task_type=:task_filter';$params['task_filter']=$type;}
        if($query!==''){$sql.=" AND label LIKE :query ESCAPE '!'";$params['query']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';}
        return [$sql,$params];
    }
    public function counts(string $role,int $actor,string $type,string $query): array
    {
        [$sql,$params]=$this->dataset($role,$actor,$type,$query);$statement=$this->pdo->prepare('SELECT task_type,COUNT(*) AS total FROM '.$sql.' GROUP BY task_type');$statement->execute($params);$counts=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row) { $counts[(string)$row['task_type']]=(int)$row['total']; }return $counts;
    }
    public function page(string $role,int $actor,string $type,string $query,int $limit,int $offset): array
    {
        [$sql,$params]=$this->dataset($role,$actor,$type,$query);$statement=$this->pdo->prepare('SELECT *,GREATEST(0,TIMESTAMPDIFF(DAY,created_at,NOW())) AS age_days FROM '.$sql.' ORDER BY created_at ASC,task_type ASC,id ASC LIMIT :row_limit OFFSET :row_offset');
        foreach($params as $name=>$value) { $statement->bindValue(':'.$name,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR); }
        $statement->bindValue(':row_limit',$limit,PDO::PARAM_INT);$statement->bindValue(':row_offset',$offset,PDO::PARAM_INT);$statement->execute();return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
