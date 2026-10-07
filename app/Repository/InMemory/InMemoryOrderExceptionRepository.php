<?php
declare(strict_types=1);
namespace App\Repository\InMemory;
use App\Repository\Contract\OrderExceptionRepositoryInterface;
final class InMemoryOrderExceptionRepository implements OrderExceptionRepositoryInterface
{
    /** @var array<int,array<string,mixed>> */ private array $closures=[];
    /** @var array<int,array<string,mixed>> */ private array $rejections=[];
    public function closure(int $id): ?array { return $this->closures[$id]??null; }
    public function close(int $id,int $actor,string $reason): void { $this->closures[$id]=['reason'=>$reason,'closed_by'=>$actor]; }
    public function rejection(int $id): ?array { return $this->rejections[$id]??null; }
    public function reject(int $id,int $actor,string $reason): void { $this->rejections[$id]=['reason'=>$reason,'rejected_by'=>$actor]; }
}
