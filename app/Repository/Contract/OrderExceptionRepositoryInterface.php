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
    /** Closed remaining fulfilment of a sales order (D-14, ADR-020): reason, closed_by, closed_units, created_at. @return array<string,mixed>|null */
    public function salesClosure(int $id): ?array;
    public function closeSales(int $id,int $actor,string $reason,int $closedUnits): void;
}
