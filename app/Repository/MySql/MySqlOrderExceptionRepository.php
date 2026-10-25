<?php
declare(strict_types=1);
namespace App\Repository\MySql;
use App\Repository\Contract\OrderExceptionRepositoryInterface;
use PDO;
final class MySqlOrderExceptionRepository implements OrderExceptionRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}
    public function closure(int $id): ?array { $s=$this->pdo->prepare('SELECT * FROM purchase_order_closures WHERE purchase_order_id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null; }
    public function close(int $id,int $actor,string $reason): void { $s=$this->pdo->prepare('INSERT INTO purchase_order_closures (purchase_order_id,closed_by,reason) VALUES (?,?,?)');$s->execute([$id,$actor,$reason]); }
    public function rejection(int $id): ?array { $s=$this->pdo->prepare('SELECT * FROM sales_order_rejections WHERE sales_order_id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null; }
    public function salesClosure(int $id): ?array { $s=$this->pdo->prepare('SELECT c.sales_order_id,c.closed_by,c.reason,c.closed_units,c.created_at,u.name AS closed_by_name FROM sales_order_closures c LEFT JOIN users u ON u.id=c.closed_by WHERE c.sales_order_id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null; }
    public function closeSales(int $id,int $actor,string $reason,int $closedUnits): void { $s=$this->pdo->prepare('INSERT INTO sales_order_closures (sales_order_id,closed_by,reason,closed_units) VALUES (?,?,?,?)');$s->execute([$id,$actor,$reason,$closedUnits]); }
    public function reject(int $id,int $actor,string $reason): void { $s=$this->pdo->prepare('INSERT INTO sales_order_rejections (sales_order_id,rejected_by,reason) VALUES (?,?,?)');$s->execute([$id,$actor,$reason]); }
}
