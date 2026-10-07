<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface OrderExceptionRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function closure(int $id): ?array;
    public function close(int $id,int $actor,string $reason): void;
    /** @return array<string,mixed>|null */
    public function rejection(int $id): ?array;
    public function reject(int $id,int $actor,string $reason): void;
}
