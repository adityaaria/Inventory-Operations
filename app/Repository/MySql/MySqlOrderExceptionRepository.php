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
    public function reject(int $id,int $actor,string $reason): void { $s=$this->pdo->prepare('INSERT INTO sales_order_rejections (sales_order_id,rejected_by,reason) VALUES (?,?,?)');$s->execute([$id,$actor,$reason]); }
}
