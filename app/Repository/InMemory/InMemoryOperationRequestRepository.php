<?php
declare(strict_types=1);
namespace App\Repository\InMemory;
use App\Repository\Contract\OperationRequestRepositoryInterface;
use App\Exception\HttpException;
final class InMemoryOperationRequestRepository implements OperationRequestRepositoryInterface
{
    /** @var array<string,array{hash:string,completed:bool}> */
    private array $requests=[];
    public function replay(int $actorId,string $key,string $hash): bool
    {
        $scope=$actorId.':'.$key;
        if (!isset($this->requests[$scope])) { $this->requests[$scope]=['hash'=>$hash,'completed'=>false]; }
        if ($this->requests[$scope]['hash']!==$hash) { throw new HttpException(409,'Idempotency key was already used for a different request.'); }
        return $this->requests[$scope]['completed'];
    }
    public function complete(int $actorId,string $key): void { $this->requests[$actorId.':'.$key]['completed']=true; }
}
