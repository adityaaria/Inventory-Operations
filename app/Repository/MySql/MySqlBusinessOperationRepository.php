<?php
declare(strict_types=1);
namespace App\Repository\MySql;
use App\Repository\Contract\BusinessOperationRepositoryInterface;
use PDO;
final class MySqlBusinessOperationRepository implements BusinessOperationRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}
    public function create(array $header,array $items): int
    {
        $s=$this->pdo->prepare('INSERT INTO inventory_operations (kind,warehouse_id,destination_id,reason,created_by,condition_confirmed) VALUES (:kind,:warehouse_id,:destination_id,:reason,:created_by,:condition_confirmed)');$s->execute($header);
        $id=(int)$this->pdo->lastInsertId();$s=$this->pdo->prepare('INSERT INTO inventory_operation_items (operation_id,product_id,quantity,baseline,source_ledger_id) VALUES (?,?,?,?,?)');
        foreach($items as $item) { $s->execute([$id,$item['product_id'],$item['quantity'],$item['baseline']??null,$item['source_ledger_id']??null]); }return $id;
    }
    public function find(int $id,bool $lock=false): ?array
    {
        if($lock && !$this->pdo->inTransaction()) { throw new \LogicException('Operation lock requires a transaction.'); }
        $s=$this->pdo->prepare('SELECT * FROM inventory_operations WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($row)) { return null; }
        $s=$this->pdo->prepare('SELECT i.*,p.sku,p.name AS product_name FROM inventory_operation_items i JOIN products p ON p.id=i.product_id WHERE operation_id=? ORDER BY product_id');$s->execute([$id]);$row['items']=$s->fetchAll(PDO::FETCH_ASSOC);return $row;
    }
    public function decide(int $id,string $status,int $actor,string $reason): void
    {
        if($status==='Cancelled'){$s=$this->pdo->prepare('UPDATE inventory_operations SET status=?,decision_reason=? WHERE id=?');$s->execute([$status,$reason,$id]);return;}
        $s=$this->pdo->prepare('UPDATE inventory_operations SET status=?,approved_by=?,approved_at=CURRENT_TIMESTAMP,decision_reason=? WHERE id=?');$s->execute([$status,$actor,$reason,$id]);
    }
    public function posted(int $id,int $actor): void
    {
        $s=$this->pdo->prepare("UPDATE inventory_operations SET status='Posted',posted_by=?,posted_at=CURRENT_TIMESTAMP WHERE id=?");$s->execute([$actor,$id]);
    }
    public function pair(int $product,int $warehouse): ?array
    {
        $s=$this->pdo->prepare('SELECT ps.quantity FROM product_stocks ps JOIN products p ON p.id=ps.product_id JOIN warehouses w ON w.id=ps.warehouse_id WHERE ps.product_id=? AND ps.warehouse_id=? AND p.is_active=1 AND w.is_active=1');$s->execute([$product,$warehouse]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    public function source(int $id,bool $lock=false): ?array
    {
        if($lock && !$this->pdo->inTransaction()) { throw new \LogicException('Source lock requires a transaction.'); }
        $s=$this->pdo->prepare('SELECT * FROM stock_ledger WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    public function returned(int $source): int
    {
        if(!$this->pdo->inTransaction()) { throw new \LogicException('Return allowance requires a transaction.'); }
        $s=$this->pdo->prepare('INSERT INTO inventory_return_totals (source_ledger_id) VALUES (?) ON DUPLICATE KEY UPDATE source_ledger_id=source_ledger_id');$s->execute([$source]);
        $s=$this->pdo->prepare('SELECT returned_quantity FROM inventory_return_totals WHERE source_ledger_id=? FOR UPDATE');$s->execute([$source]);return (int)$s->fetchColumn();
    }
    public function recordReturn(int $source,int $quantity): void
    {
        $s=$this->pdo->prepare('UPDATE inventory_return_totals SET returned_quantity=returned_quantity+? WHERE source_ledger_id=?');$s->execute([$quantity,$source]);
    }

    /** @param array<string,string> $filters @return array{string,array<string,string>} */
    private function where(array $filters): array
    {
        $clauses=[];$params=[];
        foreach(['kind','status'] as $key) { if(($filters[$key]??'')!==''){$clauses[]='o.'.$key.'=:'.$key;$params[$key]=$filters[$key];} }
        if(($filters['q']??'')!==''){$clauses[]='o.reason LIKE :query';$params['query']='%'.strtr($filters['q'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';$clauses[count($clauses)-1].=" ESCAPE '!'";}
        return [$clauses?' WHERE '.implode(' AND ',$clauses):'',$params];
    }
    public function count(array $filters): int {[$where,$params]=$this->where($filters);$s=$this->pdo->prepare('SELECT COUNT(*) FROM inventory_operations o'.$where);$s->execute($params);return (int)$s->fetchColumn();}
    public function page(array $filters,int $limit,int $offset): array
    {
        [$where,$params]=$this->where($filters);$s=$this->pdo->prepare('SELECT o.*,w.name AS warehouse_name,d.name AS destination_name,u.email AS creator_email FROM inventory_operations o JOIN warehouses w ON w.id=o.warehouse_id LEFT JOIN warehouses d ON d.id=o.destination_id JOIN users u ON u.id=o.created_by'.$where.' ORDER BY o.created_at DESC,o.id DESC LIMIT :limit OFFSET :offset');
        foreach($params as $key=>$value) { $s->bindValue($key,$value); }$s->bindValue('limit',$limit,PDO::PARAM_INT);$s->bindValue('offset',$offset,PDO::PARAM_INT);$s->execute();return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    /** @return string */
    private function recommendationSql(): string
    {
        return "SELECT p.id AS product_id,p.sku,p.name AS product_name,w.id AS warehouse_id,w.name AS warehouse_name,ps.quantity,p.reorder_point,COALESCE(inbound.quantity,0) AS inbound,GREATEST(0,CAST(p.reorder_point AS SIGNED)-CAST(ps.quantity AS SIGNED)-COALESCE(inbound.quantity,0)) AS suggested
        FROM product_stocks ps JOIN products p ON p.id=ps.product_id JOIN warehouses w ON w.id=ps.warehouse_id
        LEFT JOIN (SELECT i.product_id,po.destination_warehouse_id AS warehouse_id,SUM(i.quantity-i.received_quantity) AS quantity FROM purchase_order_items i JOIN purchase_orders po ON po.id=i.purchase_order_id LEFT JOIN purchase_order_closures c ON c.purchase_order_id=po.id WHERE po.status IN ('Ordered','PartiallyReceived') AND c.purchase_order_id IS NULL GROUP BY i.product_id,po.destination_warehouse_id) inbound ON inbound.product_id=p.id AND inbound.warehouse_id=w.id
        WHERE p.is_active=1 AND w.is_active=1 AND (p.name LIKE :name ESCAPE '!' OR p.sku LIKE :sku ESCAPE '!') AND (:any_warehouse=0 OR w.id=:warehouse_id)";
    }
    /** @return array<string,string|int> */
    private function queryParams(string $query,int $warehouseId): array { $pattern='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';return ['name'=>$pattern,'sku'=>$pattern,'any_warehouse'=>$warehouseId,'warehouse_id'=>$warehouseId]; }
    public function recommendationCount(string $query,int $warehouseId=0): int {$s=$this->pdo->prepare('SELECT COUNT(*) FROM ('.$this->recommendationSql().') r WHERE suggested>0');foreach($this->queryParams($query,$warehouseId) as $key=>$value) { $s->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR); }$s->execute();return (int)$s->fetchColumn();}
    public function recommendations(string $query,int $limit,int $offset,int $warehouseId=0): array
    {
        $s=$this->pdo->prepare('SELECT * FROM ('.$this->recommendationSql().') r WHERE suggested>0 ORDER BY sku,warehouse_id LIMIT :limit OFFSET :offset');foreach($this->queryParams($query,$warehouseId) as $key=>$value) { $s->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR); }$s->bindValue('limit',$limit,PDO::PARAM_INT);$s->bindValue('offset',$offset,PDO::PARAM_INT);$s->execute();return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
