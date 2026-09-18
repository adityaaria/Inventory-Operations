<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\SalesOrder;
use App\Support\OrderSearchCriteria;
use App\Support\PaginatedResult;

interface SalesOrderRepositoryInterface
{
    /** @return list<SalesOrder> */
    public function all(): array;

    /** @return PaginatedResult<SalesOrder> */
    public function search(OrderSearchCriteria $criteria, ?int $createdBy = null): PaginatedResult;

    /** @return list<SalesOrder> */
    public function forCreator(int $createdBy): array;

    public function findById(int $id): ?SalesOrder;

    /**
     * @param list<array{product_id: int, quantity: int, selling_price: float}> $items
     */
    public function createDraft(string $orderNumber, int $customerId, int $warehouseId, int $createdBy, array $items): SalesOrder;

    public function submit(int $id): void;
    public function approve(int $id, int $approvedBy): void;
    public function cancel(int $id): void;
    public function markFulfilled(int $id): void;
}
