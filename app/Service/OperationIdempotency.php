<?php
declare(strict_types=1);
namespace App\Service;
use App\Repository\Contract\OperationRequestRepositoryInterface;
use App\Exception\ValidationException;
final class OperationIdempotency
{
    public function __construct(private readonly OperationRequestRepositoryInterface $requests) {}
    /** @param array<int,int> $quantities @param array<int,mixed> $extra lot splits (D-17); joins the hash only when non-empty */
    public function replay(int $actorId,string $key,string $operation,int $orderId,array $quantities=[],array $extra=[]): bool
    {
        self::validateKey($key);
        $quantities=array_filter($quantities,static fn(int $quantity): bool => $quantity!==0);
        ksort($quantities,SORT_NUMERIC);
        ksort($extra,SORT_NUMERIC);
        $hash=hash('sha256',json_encode($extra===[]?[$operation,$orderId,$quantities]:[$operation,$orderId,$quantities,$extra],JSON_THROW_ON_ERROR));
        return $this->requests->replay($actorId,$key,$hash);
    }
    public static function validateKey(mixed $key): string
    {
        if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D',$key)!==1) { throw new ValidationException('A valid operation key is required. Reload the order page.'); }
        return $key;
    }
    public function complete(int $actorId,string $key): void { $this->requests->complete($actorId,$key); }
}
