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
     * list_price is the master price snapshot (defaults to purchase_price); price_reason is required by the schema when the
     * two differ (D-13, ADR-019). Callers outside PurchaseOrderService are tests and fixtures.
     *
     * @param list<array{product_id: int, quantity: int, purchase_price: float, list_price?: float, price_reason?: string|null, unit_code?: string|null, unit_factor?: int, unit_quantity?: int}> $items quantity in base units; unit_* is the D-16 snapshot
     */
    public function createDraft(string $orderNumber, int $supplierId, int $warehouseId, int $createdBy, array $items): PurchaseOrder;

    public function markOrdered(int $id): void;

    /** @param array<int, int> $receivedQuantitiesByItemId */
    public function recordReceipt(int $id, array $receivedQuantitiesByItemId, string $status): void;

    public function cancel(int $id): void;
}
