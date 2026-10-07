<?php
declare(strict_types=1);
namespace App\Repository\MySql;
use App\Repository\Contract\AuditQueryRepositoryInterface;
use PDO;
final class MySqlAuditQueryRepository implements AuditQueryRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}
    /** @param array<string,string> $filters @return array{string,array<string,string>} */
    private function where(array $filters): array
    {
        $clauses=[]; $params=[];
        foreach (['action','status','actor_id'] as $key) {
            if (($filters[$key] ?? '') !== '') { $clauses[]='a.'.$key.' = :'.$key; $params[$key]=$filters[$key]; }
        }
        if (($filters['from'] ?? '') !== '') { $clauses[]='a.created_at >= :from'; $params['from']=$filters['from'].' 00:00:00'; }
        if (($filters['to'] ?? '') !== '') { $clauses[]='a.created_at < DATE_ADD(:to, INTERVAL 1 DAY)'; $params['to']=$filters['to'].' 00:00:00'; }
        return [$clauses ? ' WHERE '.implode(' AND ',$clauses) : '',$params];
    }
    public function count(array $filters): int
    {
        [$where,$params]=$this->where($filters);
        $statement=$this->pdo->prepare('SELECT COUNT(*) FROM audit_logs a'.$where);
        $statement->execute($params); return (int)$statement->fetchColumn();
    }
    public function page(array $filters,int $limit,int $offset): array
    {
        [$where,$params]=$this->where($filters);
        $statement=$this->pdo->prepare('SELECT a.id,a.created_at,a.actor_id,u.email AS actor_email,a.action,a.entity_type,a.entity_id,a.status FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id'.$where.' ORDER BY a.created_at DESC,a.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key=>$value) { $statement->bindValue(':'.$key,$value); }
        $statement->bindValue(':limit',$limit,PDO::PARAM_INT); $statement->bindValue(':offset',$offset,PDO::PARAM_INT);
        $statement->execute(); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
