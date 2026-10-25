<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface BusinessOperationRepositoryInterface
{
    /** @param array<string,mixed> $header @param list<array<string,mixed>> $items */
    public function create(array $header,array $items): int;
    /** @return array<string,mixed>|null */
    public function find(int $id,bool $lock=false): ?array;
    public function decide(int $id,string $status,int $actor,string $reason): void;
    public function posted(int $id,int $actor): void;
    /** Transfer Approved → Dispatched (D-15, ADR-021): stamps the dispatcher and sets dispatched_quantity = quantity per line. */
    public function dispatched(int $id,int $actor): void;
    /**
     * Transfer Dispatched → Received: per product the good, damaged and short units (good + damaged + short = dispatched),
     * the receiver and the discrepancy reason (null when nothing was damaged or short).
     * @param array<int,array{good:int,damaged:int,short:int}> $lines keyed by product id
     */
    public function received(int $id,int $actor,array $lines,?string $reason): void;
    /** Open dispatched transfers, oldest dispatch first. @return list<array<string,mixed>> */
    public function inTransit(int $limit,int $offset): array;
    public function inTransitCount(): int;
    /** @return array<string,mixed>|null */
    public function pair(int $product,int $warehouse): ?array;
    /** @return array<string,mixed>|null */
    public function source(int $id,bool $lock=false): ?array;
    public function returned(int $source): int;
    public function recordReturn(int $source,int $quantity): void;
    /** @param array<string,string> $filters */
    public function count(array $filters): int;
    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    public function page(array $filters,int $limit,int $offset): array;
    /** @return list<array<string,mixed>> */
    public function recommendations(string $query,int $limit,int $offset,int $warehouseId=0): array;
    public function recommendationCount(string $query,int $warehouseId=0): int;
    /** Lots of a pair with stock or not, FEFO order, with expiry status fields (D-17, ADR-023). @return list<array<string,mixed>> */
    public function lots(int $product,int $warehouse): array;
    /** @return array<string,mixed>|null */
    public function lot(int $id): ?array;
    /** Expired lots with stock in a warehouse, earliest expiry first. @return list<array<string,mixed>> */
    public function expiredLots(int $warehouse,?int $product,int $limit): array;
    /**
     * Lots picked at dispatch (D-17): one row per line and source lot.
     * @param list<array{product_id:int,lot_id:int,lot_code:string,expiry_date:string|null,unit_cost:string,quantity:int}> $picked
     */
    public function recordTransferLots(int $id,array $picked): void;
    /**
     * Per-lot receipt of a transfer: received/damaged/short and the destination lot per transfer lot row.
     * @param list<array{id:int,received:int,damaged:int,short:int,destination_lot_id:int|null}> $rows
     */
    public function receiveTransferLots(int $id,array $rows): void;
}
