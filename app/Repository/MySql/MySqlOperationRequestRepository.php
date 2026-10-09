<?php
declare(strict_types=1);
namespace App\Repository\MySql;
use App\Exception\HttpException;
use App\Exception\PersistenceException;
use App\Repository\Contract\OperationRequestRepositoryInterface;
use PDO;
final class MySqlOperationRequestRepository implements OperationRequestRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}
    public function replay(int $actorId, string $key, string $hash): bool
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Operation request requires the stock transaction.'); }
        $insert=$this->pdo->prepare('INSERT INTO operation_requests (actor_id,request_key,payload_hash) VALUES (:actor,:key,:hash) ON DUPLICATE KEY UPDATE id=id');
        $insert->execute(['actor'=>$actorId,'key'=>$key,'hash'=>$hash]);
        $select=$this->pdo->prepare('SELECT payload_hash,completed FROM operation_requests WHERE actor_id=:actor AND request_key=:key FOR UPDATE');
        $select->execute(['actor'=>$actorId,'key'=>$key]); $row=$select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { throw new PersistenceException('Operation request unavailable.'); }
        if (!hash_equals((string)$row['payload_hash'],$hash)) { throw new HttpException(409,'Idempotency key was already used for a different request.'); }
        return (bool)$row['completed'];
    }
    public function complete(int $actorId, string $key): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Operation request requires the stock transaction.'); }
        $statement=$this->pdo->prepare('UPDATE operation_requests SET completed=TRUE WHERE actor_id=:actor AND request_key=:key');
        $statement->execute(['actor'=>$actorId,'key'=>$key]);
    }
}
