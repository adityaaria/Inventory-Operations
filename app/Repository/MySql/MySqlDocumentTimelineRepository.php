<?php
declare(strict_types=1);
namespace App\Repository\MySql;
use PDO;
use App\Repository\Contract\DocumentTimelineRepositoryInterface;
final class MySqlDocumentTimelineRepository implements DocumentTimelineRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}
    public function document(string $kind,int $id): ?array
    {
        $table=match($kind){'PO'=>'purchase_orders','SO'=>'sales_orders','Operation'=>'inventory_operations',default=>throw new \InvalidArgumentException('Invalid document type.')};
        $statement=$this->pdo->prepare('SELECT id,created_by,created_at,status FROM '.$table.' WHERE id=:id');$statement->execute(['id'=>$id]);$row=$statement->fetch(PDO::FETCH_ASSOC);return $row===false?null:$row;
    }
    public function links(string $kind,int $id): array
    {
        if($kind!=='Operation') { return []; }
        $statement=$this->pdo->prepare("SELECT DISTINCT s.reference_type AS kind,s.reference_id AS id FROM inventory_operation_items i JOIN stock_ledger s ON s.id=i.source_ledger_id WHERE i.operation_id=:id AND s.reference_type IN ('PO','SO') ORDER BY s.reference_type,s.reference_id");$statement->execute(['id'=>$id]);
        return array_map(static fn(array $row): array=>['kind'=>(string)$row['kind'],'id'=>(int)$row['id']],$statement->fetchAll(PDO::FETCH_ASSOC));
    }
    public function events(string $kind,int $id,bool $related,int $limit): array
    {
        $table=match($kind){'PO'=>'purchase_orders','SO'=>'sales_orders','Operation'=>'inventory_operations',default=>throw new \InvalidArgumentException('Invalid document type.')};
        $creationDetail=$kind==='Operation'?'d.reason':"''";
        $parts=["SELECT d.created_at AS event_at,0 AS sequence,'Document created' AS title,u.name AS actor,$creationDetail AS detail,'' AS metadata,0 AS operation_id FROM $table d LEFT JOIN users u ON u.id=d.created_by WHERE d.id=:created_id"];
        $params=['created_id'=>$id];
        if($kind==='SO'){$parts[]="SELECT d.approved_at AS event_at,1 AS sequence,'SO approved' AS title,u.name AS actor,'' AS detail,'' AS metadata,0 AS operation_id FROM sales_orders d LEFT JOIN users u ON u.id=d.approved_by WHERE d.id=:approved_id AND d.approved_at IS NOT NULL";$params['approved_id']=$id;}
        if($kind==='Operation'){$parts[]="SELECT d.posted_at AS event_at,2 AS sequence,'Stock operation posted' AS title,u.name AS actor,'' AS detail,'' AS metadata,0 AS operation_id FROM inventory_operations d LEFT JOIN users u ON u.id=d.posted_by WHERE d.id=:posted_id AND d.posted_at IS NOT NULL";$params['posted_id']=$id;}
        $entityTypes=match($kind){'PO'=>['PO','purchase-orders'],'SO'=>['SO','sales-orders'],default=>['Adjustment','Transfer','SupplierReturn','CustomerReturn','ADJUSTMENT','TRANSFER','SUPPLIER_RETURN','CUSTOMER_RETURN']};
        $placeholders=[];foreach($entityTypes as $n=>$type){$placeholders[]=':entity'.$n;$params['entity'.$n]=$type;}
        $parts[]="SELECT a.created_at AS event_at,a.id+100 AS sequence,a.action AS title,u.name AS actor,'' AS detail,a.metadata_json AS metadata,0 AS operation_id FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id WHERE a.entity_id=:audit_id AND a.entity_type IN (".implode(',',$placeholders).") AND a.status='success' AND a.action NOT IN ('purchase-orders.receive','sales-orders.issue','inventory-operations.post','sales-orders.approve','inventory-operations.propose')";$params['audit_id']=$id;
        if($kind==='Operation'){$ref="reference_type IN ('ADJUSTMENT','TRANSFER','SUPPLIER_RETURN','CUSTOMER_RETURN')";}else{$ref='reference_type=:ledger_kind';$params['ledger_kind']=$kind;}
        $parts[]="SELECT l.created_at AS event_at,l.id+100000 AS sequence,CONCAT(l.movement_type,' ledger #',l.id) AS title,u.name AS actor,CONCAT(p.sku,' · ',w.name,' · change ',CASE WHEN l.movement_type='Issue' THEN -CAST(l.quantity AS SIGNED) WHEN l.movement_type='Adjustment' THEN l.quantity_delta ELSE CAST(l.quantity AS SIGNED) END) AS detail,'' AS metadata,0 AS operation_id FROM stock_ledger l JOIN products p ON p.id=l.product_id JOIN warehouses w ON w.id=l.warehouse_id LEFT JOIN users u ON u.id=l.performed_by WHERE l.reference_id=:ledger_id AND $ref";$params['ledger_id']=$id;
        if($related&&$kind!=='Operation') {
            $parts[]="SELECT o.posted_at AS event_at,o.id+200000 AS sequence,CONCAT(o.kind,' posted') AS title,u.name AS actor,CONCAT('Related return #',o.id) AS detail,'' AS metadata,o.id AS operation_id FROM inventory_operations o LEFT JOIN users u ON u.id=o.posted_by WHERE o.status='Posted' AND o.kind IN ('SupplierReturn','CustomerReturn') AND EXISTS(SELECT 1 FROM inventory_operation_items i JOIN stock_ledger s ON s.id=i.source_ledger_id WHERE i.operation_id=o.id AND s.reference_type=:return_kind AND s.reference_id=:return_id)";
            $params['return_kind']=$kind;$params['return_id']=$id;
        }
        $statement=$this->pdo->prepare('SELECT * FROM ('.implode(' UNION ALL ',$parts).') events ORDER BY event_at DESC,sequence DESC,title ASC LIMIT :row_limit');foreach($params as $name=>$value) { $statement->bindValue(':'.$name,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR); }$statement->bindValue(':row_limit',$limit,PDO::PARAM_INT);$statement->execute();return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
