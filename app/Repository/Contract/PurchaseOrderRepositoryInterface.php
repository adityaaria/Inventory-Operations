<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\PurchaseOrder;
use App\Support\OrderSearchCriteria;
use App\Support\PaginatedResult;

interface PurchaseOrderRepositoryInterface
{
    /** @return list<PurchaseOrder> */
    public function all(): array;

    /** @return PaginatedResult<PurchaseOrder> */
    public function search(OrderSearchCriteria $criteria): PaginatedResult;

    public function findById(int $id): ?PurchaseOrder;

    /** Lock the source order inside the caller transaction. */
    public function lockById(int $id): ?PurchaseOrder;

    /**
     * @param list<array{product_id: int, quantity: int, purchase_price: float}> $items
     */
    public function createDraft(string $orderNumber, int $supplierId, int $warehouseId, int $createdBy, array $items): PurchaseOrder;

    public function markOrdered(int $id): void;

    /** @param array<int, int> $receivedQuantitiesByItemId */
    public function recordReceipt(int $id, array $receivedQuantitiesByItemId, string $status): void;

    public function cancel(int $id): void;
}
