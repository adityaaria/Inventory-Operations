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

    /** Lock the source order inside the caller transaction. */
    public function lockById(int $id): ?SalesOrder;

    /**
     * list_price is the master price snapshot (defaults to selling_price); price_reason is required by the schema when the
     * two differ (D-13, ADR-019). Callers outside SalesOrderService are tests and fixtures.
     *
     * price_source/price_list_id record where list_price came from and quotation_item_id the converted quotation line;
     * $quotationId is the accepted quotation of a converted order (D-19, ADR-025).
     *
     * @param list<array{product_id: int, quantity: int, selling_price: float, list_price?: float, price_reason?: string|null, unit_code?: string|null, unit_factor?: int, unit_quantity?: int, price_source?: string, price_list_id?: int|null, quotation_item_id?: int|null}> $items quantity in base units; unit_* is the D-16 snapshot
     */
    public function createDraft(string $orderNumber, int $customerId, int $warehouseId, int $createdBy, array $items, ?int $quotationId = null): SalesOrder;

    public function submit(int $id): void;
    public function approve(int $id, int $approvedBy): void;
    public function cancel(int $id): void;
    /** Marks every line fully issued and the order Fulfilled (fixtures/tests; the service uses recordIssue()). */
    public function markFulfilled(int $id): void;

    /**
     * Adds one shipment (D-14, ADR-020): issued_quantity += quantity per item of this order, then sets $status
     * (PartiallyFulfilled or Fulfilled) when the order is Approved or PartiallyFulfilled. Runs inside the caller's
     * stock transaction after the order lock; the CHECK issued_quantity <= quantity is the database guard.
     *
     * @param array<int, int> $issuedQuantitiesByItemId
     */
    public function recordIssue(int $id, array $issuedQuantitiesByItemId, string $status): void;
}
