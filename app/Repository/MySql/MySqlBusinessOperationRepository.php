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
        $header+=['purpose'=>'Standard'];
        $s=$this->pdo->prepare('INSERT INTO inventory_operations (kind,warehouse_id,destination_id,reason,created_by,condition_confirmed,purpose) VALUES (:kind,:warehouse_id,:destination_id,:reason,:created_by,:condition_confirmed,:purpose)');$s->execute($header);
        $id=(int)$this->pdo->lastInsertId();// Unit snapshot (D-16, ADR-022): quantity is base = unit_quantity × unit_factor; no unit = products.unit, factor 1.
        $s=$this->pdo->prepare('INSERT INTO inventory_operation_items (operation_id,product_id,quantity,baseline,source_ledger_id,unit_code,unit_factor,unit_quantity,lot_id,lot_code,expiry_date) VALUES (?,?,?,?,?,COALESCE(?,(SELECT p.unit FROM products p WHERE p.id=?),\'\'),?,?,?,?,?)');
        foreach($items as $item) { $factor=(int)($item['unit_factor']??1);$s->execute([$id,$item['product_id'],$item['quantity'],$item['baseline']??null,$item['source_ledger_id']??null,$item['unit_code']??null,$item['product_id'],$factor,$item['unit_quantity']??intdiv((int)$item['quantity'],$factor),$item['lot_id']??null,$item['lot_code']??null,$item['expiry_date']??null]); }return $id;
    }
    public function find(int $id,bool $lock=false): ?array
    {
        if($lock && !$this->pdo->inTransaction()) { throw new \LogicException('Operation lock requires a transaction.'); }
        $s=$this->pdo->prepare('SELECT o.*,w.name AS warehouse_name,d.name AS destination_name,du.name AS dispatcher_name,ru.name AS receiver_name FROM inventory_operations o JOIN warehouses w ON w.id=o.warehouse_id LEFT JOIN warehouses d ON d.id=o.destination_id LEFT JOIN users du ON du.id=o.dispatched_by LEFT JOIN users ru ON ru.id=o.received_by WHERE o.id=?'.($lock?' FOR UPDATE OF o':''));$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($row)) { return null; }
        $s=$this->pdo->prepare('SELECT i.*,p.sku,p.name AS product_name,l.lot_code AS counted_lot_code,l.expiry_date AS counted_lot_expiry FROM inventory_operation_items i JOIN products p ON p.id=i.product_id LEFT JOIN stock_lots l ON l.id=i.lot_id WHERE operation_id=? ORDER BY product_id,i.id');$s->execute([$id]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
        // Lots carried by transfer lines (D-17, ADR-023), in dispatch (FEFO) order. A plain read like the items above: they
        // are written only under the operation row lock, and a locking range read here would take gap locks that make two
        // concurrent dispatches deadlock on their inserts.
        $s=$this->pdo->prepare('SELECT * FROM inventory_operation_lots WHERE operation_id=? ORDER BY id');$s->execute([$id]);$lots=[];
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $lot) { $lots[(int)$lot['operation_item_id']][]=$lot; }
        foreach($items as &$item) { $item['lots']=$lots[(int)$item['id']]??[]; }unset($item);
        $row['items']=$items;return $row;
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
    public function dispatched(int $id,int $actor): void
    {
        $s=$this->pdo->prepare("UPDATE inventory_operations SET status='Dispatched',dispatched_by=?,dispatched_at=CURRENT_TIMESTAMP WHERE id=? AND kind='Transfer' AND status='Approved'");$s->execute([$actor,$id]);
        if($s->rowCount()!==1) { throw new \LogicException('Transfer is not Approved; nothing was dispatched.'); }
        $s=$this->pdo->prepare('UPDATE inventory_operation_items SET dispatched_quantity=quantity WHERE operation_id=? AND dispatched_quantity IS NULL');$s->execute([$id]);
    }
    public function received(int $id,int $actor,array $lines,?string $reason): void
    {
        $s=$this->pdo->prepare("UPDATE inventory_operations SET status='Received',received_by=?,received_at=CURRENT_TIMESTAMP,receipt_reason=? WHERE id=? AND kind='Transfer' AND status='Dispatched'");$s->execute([$actor,$reason,$id]);
        if($s->rowCount()!==1) { throw new \LogicException('Transfer is not Dispatched; nothing was received.'); }
        // The CHECK chk_operation_items_receipt guards good + damaged + short = dispatched per line.
        $s=$this->pdo->prepare('UPDATE inventory_operation_items SET received_quantity=?,damaged_quantity=?,short_quantity=? WHERE operation_id=? AND product_id=? AND received_quantity IS NULL AND dispatched_quantity IS NOT NULL');
        foreach($lines as $product=>$line) {
            $s->execute([$line['good'],$line['damaged'],$line['short'],$id,$product]);
            if($s->rowCount()!==1) { throw new \LogicException('Transfer line is missing or already received.'); }
        }
    }
    public function inTransit(int $limit,int $offset): array
    {
        $s=$this->pdo->prepare(self::IN_TRANSIT_SELECT.' ORDER BY o.dispatched_at ASC,o.id ASC LIMIT :limit OFFSET :offset');
        $s->bindValue('limit',$limit,PDO::PARAM_INT);$s->bindValue('offset',$offset,PDO::PARAM_INT);$s->execute();return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function inTransitCount(): int
    {
        $s=$this->pdo->query("SELECT COUNT(*) FROM inventory_operations WHERE kind='Transfer' AND status='Dispatched'");return $s===false?0:(int)$s->fetchColumn();
    }
    private const IN_TRANSIT_SELECT="SELECT o.id,o.reason,o.warehouse_id,o.destination_id,o.dispatched_at,o.approved_at,w.name AS warehouse_name,d.name AS destination_name,u.name AS dispatcher_name,
        (SELECT COUNT(*) FROM inventory_operation_items i WHERE i.operation_id=o.id) AS line_count,
        (SELECT COALESCE(SUM(i.dispatched_quantity),0) FROM inventory_operation_items i WHERE i.operation_id=o.id) AS units,
        GREATEST(0,TIMESTAMPDIFF(DAY,o.dispatched_at,NOW())) AS age_days
        FROM inventory_operations o JOIN warehouses w ON w.id=o.warehouse_id JOIN warehouses d ON d.id=o.destination_id LEFT JOIN users u ON u.id=o.dispatched_by
        WHERE o.kind='Transfer' AND o.status='Dispatched'";
    public function pair(int $product,int $warehouse): ?array
    {
        $s=$this->pdo->prepare('SELECT ps.quantity,ps.reserved_quantity FROM product_stocks ps JOIN products p ON p.id=ps.product_id JOIN warehouses w ON w.id=ps.warehouse_id WHERE ps.product_id=? AND ps.warehouse_id=? AND p.is_active=1 AND w.is_active=1');$s->execute([$product,$warehouse]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
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
    public function lots(int $product,int $warehouse): array
    {
        $s=$this->pdo->prepare('SELECT l.id,l.lot_code,l.expiry_date,l.received_at,l.unit_cost,l.quantity,(l.expiry_date IS NOT NULL AND l.expiry_date < '.ExpirySql::today().') AS expired FROM stock_lots l WHERE l.product_id=? AND l.warehouse_id=? ORDER BY l.expiry_key,l.received_at,l.id');
        $s->execute([$product,$warehouse]);return array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'lot_code'=>(string)$row['lot_code'],'expiry_date'=>$row['expiry_date'],'received_at'=>(string)$row['received_at'],'unit_cost'=>(string)$row['unit_cost'],'quantity'=>(int)$row['quantity'],'expired'=>(bool)$row['expired']],$s->fetchAll(PDO::FETCH_ASSOC));
    }
    public function lot(int $id): ?array
    {
        $s=$this->pdo->prepare('SELECT id,product_id,warehouse_id,lot_code,expiry_date,quantity,unit_cost FROM stock_lots WHERE id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    public function expiredLots(int $warehouse,?int $product,int $limit): array
    {
        $s=$this->pdo->prepare('SELECT l.id,l.product_id,l.lot_code,l.expiry_date,l.quantity FROM stock_lots l WHERE l.warehouse_id=:warehouse AND l.quantity>0 AND l.expiry_date IS NOT NULL AND l.expiry_date < '.ExpirySql::today().' AND (:any_product=0 OR l.product_id=:product) ORDER BY l.expiry_date,l.product_id,l.id LIMIT :row_limit');
        $s->bindValue('warehouse',$warehouse,PDO::PARAM_INT);$s->bindValue('any_product',$product??0,PDO::PARAM_INT);$s->bindValue('product',$product??0,PDO::PARAM_INT);$s->bindValue('row_limit',$limit,PDO::PARAM_INT);$s->execute();return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function recordTransferLots(int $id,array $picked): void
    {
        $s=$this->pdo->prepare('INSERT INTO inventory_operation_lots (operation_id,operation_item_id,product_id,source_lot_id,lot_code,expiry_date,unit_cost,dispatched_quantity) SELECT ?,i.id,?,?,?,?,?,? FROM inventory_operation_items i WHERE i.operation_id=? AND i.product_id=?');
        foreach($picked as $lot) { $s->execute([$id,$lot['product_id'],$lot['lot_id'],$lot['lot_code'],$lot['expiry_date'],$lot['unit_cost'],$lot['quantity'],$id,$lot['product_id']]);if($s->rowCount()!==1) { throw new \LogicException('Transfer line is missing for a picked lot.'); } }
    }
    public function receiveTransferLots(int $id,array $rows): void
    {
        $s=$this->pdo->prepare('UPDATE inventory_operation_lots SET received_quantity=?,damaged_quantity=?,short_quantity=?,destination_lot_id=? WHERE id=? AND operation_id=? AND received_quantity IS NULL');
        foreach($rows as $row) { $s->execute([$row['received'],$row['damaged'],$row['short'],$row['destination_lot_id'],$row['id'],$id]);if($s->rowCount()!==1) { throw new \LogicException('Transfer lot is missing or already received.'); } }
    }
    /** @return string */
    private function recommendationSql(): string
    {
        // Effective threshold = warehouse override, else product default (D-10, ADR-016). Stock counted against it is the
        // available quantity (physical − reserved for Approved SOs, D-12/ADR-018), the same rule as LowStockSql.
        // Supply already on its way is subtracted: open PO remainder and goods in transit to this warehouse (D-15, ADR-021).
        // Everything is in base units; the purchase default unit (else the base unit) lets the page round the suggestion
        // up to whole purchase units (D-16, ADR-022).
        return "SELECT p.id AS product_id,p.sku,p.name AS product_name,p.unit AS base_unit,COALESCE(pu.unit_code,p.unit) AS purchase_unit,COALESCE(pu.factor_to_base,1) AS purchase_factor,w.id AS warehouse_id,w.name AS warehouse_name,ps.quantity,ps.reserved_quantity,".LowStockSql::available()." AS available_quantity,".LowStockSql::EFFECTIVE." AS reorder_point,ps.reorder_point_override,COALESCE(inbound.quantity,0) AS inbound,COALESCE(transit.quantity,0) AS in_transit,GREATEST(0,CAST(".LowStockSql::EFFECTIVE." AS SIGNED)-".LowStockSql::available()."-COALESCE(inbound.quantity,0)-COALESCE(transit.quantity,0)) AS suggested
        FROM product_stocks ps JOIN products p ON p.id=ps.product_id JOIN warehouses w ON w.id=ps.warehouse_id
        LEFT JOIN product_units pu ON pu.product_id=p.id AND pu.is_purchase_default=1 AND pu.is_active=1
        LEFT JOIN (SELECT i.product_id,po.destination_warehouse_id AS warehouse_id,SUM(i.quantity-i.received_quantity) AS quantity FROM purchase_order_items i JOIN purchase_orders po ON po.id=i.purchase_order_id LEFT JOIN purchase_order_closures c ON c.purchase_order_id=po.id WHERE po.status IN ('Ordered','PartiallyReceived') AND c.purchase_order_id IS NULL GROUP BY i.product_id,po.destination_warehouse_id) inbound ON inbound.product_id=p.id AND inbound.warehouse_id=w.id
        LEFT JOIN (SELECT i.product_id,o.destination_id AS warehouse_id,SUM(i.dispatched_quantity) AS quantity FROM inventory_operation_items i JOIN inventory_operations o ON o.id=i.operation_id WHERE o.kind='Transfer' AND o.status='Dispatched' GROUP BY i.product_id,o.destination_id) transit ON transit.product_id=p.id AND transit.warehouse_id=w.id
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
