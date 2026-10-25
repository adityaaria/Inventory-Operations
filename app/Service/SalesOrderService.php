<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Repository\Contract\DocumentSequenceRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\SalesOrderRepositoryInterface;
use App\Security\AuthContext;
use App\Support\OrderNumber;
use App\Exception\ValidationException;

final class SalesOrderService
{
    /**
     * @param array<int, Customer> $customers
     * @param array<int, Warehouse> $warehouses
     */
    public function __construct(
        private readonly SalesOrderRepositoryInterface $orders,
        private readonly DocumentSequenceRepositoryInterface $sequences,
        private readonly ProductRepositoryInterface $products,
        private readonly array $customers,
        private readonly array $warehouses,
        private readonly StockService $stockService,
        private readonly ?OperationIdempotency $idempotency = null,
        private readonly OrderLinePricing $pricing = new OrderLinePricing(),
        private readonly ?\App\Repository\Contract\OrderExceptionRepositoryInterface $exceptions = null,
        private readonly ?ProductUnitCatalog $units = null,
        private readonly ?PriceResolver $prices = null,
        private readonly ?DeliveryNoteService $deliveryNotes = null,
    ) {
    }

    /** Line validation and pricing shared with quotations (D-19, ADR-025); master prices without a resolver. */
    private function lineBuilder(): SalesLineBuilder
    {
        return new SalesLineBuilder($this->products, $this->units(), $this->pricing, $this->prices);
    }

    private function units(): ProductUnitCatalog
    {
        return $this->units ?? new ProductUnitCatalog($this->products);
    }

    /**
     * The order number is assigned by the server (D-11, ADR-017); clients cannot choose it.
     * Line prices default to the product master selling_price; a different `price` needs a `price_reason`, may not be
     * below the current purchase price, and Sales may discount at most SALES_MAX_DISCOUNT_PERCENT (D-13, ADR-019, see
     * OrderLinePricing). The master snapshot (list_price) is always taken here, never from the client.
     *
     * Units (D-16, ADR-022): `quantity` is in the line's `unit_code` (empty = base unit); the line stores the unit snapshot
     * and the base quantity; prices, the Sales discount cap and the cost floor are compared per entered unit.
     * Price lists (D-19, ADR-025): the list price is re-resolved here from the customer's price list tier (else master);
     * the client never supplies it, and the line stores its source.
     *
     * @param list<array{product_id: int, quantity: int, unit_code?: string|null, price?: mixed, price_reason?: mixed}> $items
     */
    public function createDraft(AuthContext $actor, int $customerId, int $warehouseId, array $items): SalesOrder
    {
        $this->assertCanCreate($actor);
        $this->assertHeader($customerId, $warehouseId);
        $lines = $this->lineBuilder()->lines($actor, $customerId, $items);

        // The number is claimed in the same transaction as the draft insert, after validation: invalid input never
        // touches the sequence, and a failed insert rolls the claim back (no gap, never a duplicate).
        return $this->stockService->transaction(function () use ($customerId, $warehouseId, $actor, $lines): SalesOrder {
            $period = $this->sequences->currentPeriod();
            $number = OrderNumber::format(OrderNumber::SALES, $period, $this->sequences->next(OrderNumber::SALES, $period));
            $this->units()->confirmSnapshots($lines);

            return $this->orders->createDraft($number, $customerId, $warehouseId, $actor->userId(), $lines);
        });
    }

    /**
     * SO Draft converted from an accepted quotation (D-19, ADR-025). Called by QuotationService inside its transaction
     * after the quotation row is locked and checked; the quoted lines are re-validated (active product and unit, same
     * factor, cost floor and Sales cap against the quoted list price) before the SO number is claimed. The order belongs
     * to $ownerId (the quotation's creator) and records quotation_id (UNIQUE: one SO per quotation) and per line the
     * quotation line it came from.
     *
     * @param list<array{id: int, product_id: int, unit_code: string, unit_factor: int, unit_quantity: int, unit_price: string, list_price: string, price_reason: ?string, price_source: string, price_list_id: ?int}> $quoted
     */
    public function createDraftFromQuotation(AuthContext $actor, int $ownerId, int $customerId, int $warehouseId, int $quotationId, array $quoted): SalesOrder
    {
        $this->assertCanCreate($actor);
        $this->assertHeader($customerId, $warehouseId);
        if ($quoted === []) {
            throw new ValidationException('At least one item is required.');
        }
        $lines = $this->lineBuilder()->quotedLines($actor, $quoted);

        return $this->stockService->transaction(function () use ($customerId, $warehouseId, $ownerId, $lines, $quotationId): SalesOrder {
            $period = $this->sequences->currentPeriod();
            $number = OrderNumber::format(OrderNumber::SALES, $period, $this->sequences->next(OrderNumber::SALES, $period));
            $this->units()->confirmSnapshots($lines);

            return $this->orders->createDraft($number, $customerId, $warehouseId, $ownerId, $lines, $quotationId);
        });
    }

    /** Largest discount below the master selling price that Sales may give (D-13, ADR-019); shown as a form hint. */
    public function salesMaxDiscountPercent(): int
    {
        return $this->pricing->salesMaxDiscountPercent();
    }

    /**
     * Stock at the order's source warehouse that this order could use, per ordered product, for open orders only
     * (D-12, ADR-018): available = quantity − reserved_quantity. An Approved or PartiallyFulfilled order's own
     * reservation (its unissued remainder, D-14/ADR-020) is part of reserved_quantity but belongs to this order, so it is
     * added back; such an order can therefore never show a shortage. A closed remainder holds nothing and is not shown.
     * Informational: approve() and issue() lock and re-check inside their transactions.
     *
     * @return array<int, int> product id => quantity usable by this order
     */
    public function sourceAvailability(AuthContext $actor, SalesOrder $order): array
    {
        if ($actor->role() === User::ROLE_SALES && $order->createdBy() !== $actor->userId()) {
            throw new HttpException(403, 'Forbidden');
        }
        $open = [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_PENDING_APPROVAL, SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PARTIALLY_FULFILLED];
        if (!in_array($order->status(), $open, true) || $this->remainderClosure($order->id()) !== null) {
            return [];
        }
        $reserving = in_array($order->status(), SalesOrder::RESERVING_STATUSES, true);
        $ownReservation = [];
        foreach ($order->items() as $item) {
            $ownReservation[$item->productId()] = $reserving ? $item->remainingQuantity() : 0;
        }
        $available = array_fill_keys(array_keys($ownReservation), 0);
        foreach ($this->products->stocksForProducts(array_keys($ownReservation)) as $stocks) {
            foreach ($stocks as $stock) {
                if ($stock->warehouseId() === $order->sourceWarehouseId()) {
                    $available[$stock->productId()] = $stock->availableQuantity() + $ownReservation[$stock->productId()];
                }
            }
        }

        return $available;
    }

    public function submit(AuthContext $actor, int $id): void
    {
        $this->stockService->transaction(function () use ($actor, $id): void {
            $order = $this->findOrder($id);
            $this->assertOwnOrAdmin($actor, $order);
            if ($order->status() !== SalesOrder::STATUS_DRAFT) {
                throw new ValidationException('Only Draft sales orders can be submitted.');
            }

            $this->orders->submit($id);

        });
    }

    /**
     * PendingApproval → Approved and reserve the order's lines at the source warehouse in one transaction (D-12,
     * ADR-018): Admin only (D-03), lock the order, then StockService locks the stock rows in product order, requires
     * available ≥ quantity per line (422 "Insufficient available stock", nothing changed), reserves, marks the order
     * Approved and appends the `sales-orders.approve` audit row with the reserved lines.
     */
    public function approve(AuthContext $actor, int $id): void
    {
        $this->assertAdmin($actor);
        $this->stockService->transaction(function () use ($actor, $id): void {
            $order = $this->findOrder($id);
            if ($order->status() !== SalesOrder::STATUS_PENDING_APPROVAL) {
                throw new ValidationException('Only PendingApproval sales orders can be approved.');
            }

            $this->stockService->reserve(
                $order->sourceWarehouseId(),
                $this->movements($order),
                $actor->userId(),
                'SO',
                $order->id(),
                fn (): null => $this->markApproved($order->id(), $actor->userId()),
                'sales-orders.approve',
            );
        });
    }

    /**
     * Cancels a Draft, PendingApproval or Approved order. An Approved order releases its reservation in the same
     * transaction; earlier states hold no reservation. The `sales-orders.cancel` audit row records the prior status.
     * Cancelling an already Cancelled order stays a no-op. A PartiallyFulfilled order cannot be cancelled: goods have left
     * the warehouse, so its remainder is closed with a reason instead (closeRemaining(), D-14/ADR-020).
     */
    public function rejectOrCancel(AuthContext $actor, int $id): void
    {
        $this->assertAdmin($actor);
        $this->stockService->transaction(function () use ($actor, $id): void {
            $order = $this->findOrder($id);
            if ($order->status() === SalesOrder::STATUS_FULFILLED) {
                throw new ValidationException('Fulfilled sales orders cannot be cancelled.');
            }
            if ($order->status() === SalesOrder::STATUS_PARTIALLY_FULFILLED) {
                throw new ValidationException('Partially fulfilled sales orders cannot be cancelled. Close the remaining fulfilment with a reason instead.');
            }
            if ($order->status() === SalesOrder::STATUS_CANCELLED) {
                return; // Already cancelled: unchanged no-op, as before; nothing to release or record.
            }

            $this->stockService->release(
                $order->sourceWarehouseId(),
                $order->status() === SalesOrder::STATUS_APPROVED ? $this->remainingMovements($order) : [],
                $actor->userId(),
                'SO',
                $order->id(),
                fn (): null => $this->markCancelled($order->id()),
                'sales-orders.cancel',
                ['previous_status' => $order->status()],
            );
        });
    }

    /**
     * Issues one shipment (D-14, ADR-020; mirrors the PO receipt). $issueQuantitiesByItemId maps sales order item id to
     * the quantity shipped now (0..remaining per line, at least one line above 0); null means "every remaining unit"
     * (trusted callers and a form that posts no quantities). One transaction: lock the order, replay check (ADR-008, the
     * hash covers the per-line quantities), closure/state check, then StockService locks the stock rows in product order
     * and re-checks quantity and reservation after the lock, lowers both in one statement per line and appends one Issue
     * ledger row per line; the order lines record issued_quantity, the status becomes Fulfilled when every line is
     * complete and PartiallyFulfilled otherwise, and the `sales-orders.issue` audit row describes the shipment.
     * Quantities are whole line units (D-16, ADR-022: a karton line ships karton); stock moves in base units.
     * Delivery note (D-19, ADR-025): in the same callback, after the stock, lot and ledger rows are written, the shipment's
     * surat jalan DO-YYMM-NNNN is created with the lots used; $shipment carries the optional vehicle and driver. Any
     * failure rolls back stock, ledger, order and note together. A replay creates nothing.
     *
     * @param array<int, int>|null $issueQuantitiesByItemId item id => quantity in the line unit
     * @param array<string, mixed> $shipment optional `vehicle` and `driver` for the delivery note
     */
    public function issue(AuthContext $actor, int $id, ?string $requestKey = null, ?array $issueQuantitiesByItemId = null, array $shipment = []): void
    {
        $this->assertCanIssue($actor);
        if ($issueQuantitiesByItemId !== null) {
            // Payload-only checks happen before the replay lookup, so an all-zero request can never match a stored key.
            $issueQuantitiesByItemId = $this->requestedIssue($issueQuantitiesByItemId);
        }
        $details = DeliveryNoteService::shipmentDetails($shipment);
        $this->stockService->transaction(function () use ($actor, $id, $requestKey, $issueQuantitiesByItemId, $details): void {
            $order = $this->findOrder($id);
            if ($requestKey !== null) {
                if ($this->idempotency === null) { throw new \LogicException('Idempotency repository is required.'); }
                // A completed replay returns before any stock work, so a shipment is never issued twice.
                if ($this->idempotency->replay($actor->userId(), $requestKey, 'issue', $id, $issueQuantitiesByItemId ?? [])) { return; }
            }
            if ($this->remainderClosure($id) !== null) {
                throw new ValidationException('The remaining fulfilment of this sales order is closed; no further goods can be issued.');
            }
            if (!in_array($order->status(), SalesOrder::ISSUABLE_STATUSES, true)) {
                throw new ValidationException('Only Approved or PartiallyFulfilled sales orders can be issued.');
            }

            [$accepted, $movements] = $this->acceptedIssue($order, $issueQuantitiesByItemId ?? $this->remainingUnitsByItem($order));
            $remainingAfter = $order->remainingUnits() - array_sum($accepted);
            $status = $remainingAfter === 0 ? SalesOrder::STATUS_FULFILLED : SalesOrder::STATUS_PARTIALLY_FULFILLED;
            $this->stockService->issue(
                $order->sourceWarehouseId(),
                $movements,
                $actor->userId(),
                'SO',
                $order->id(),
                function (array $shipped) use ($order, $accepted, $status, $actor, $details): void {
                    $this->recordIssue($order->id(), $accepted, $status);
                    $this->deliveryNotes?->createForShipment($actor, $order, $accepted, $shipped, $details);
                },
                true,
                ['shipment_units' => array_sum($accepted), 'remaining_units' => $remainingAfter, 'status_after' => $status],
            );
            if ($requestKey !== null) { $this->idempotency->complete($actor->userId(), $requestKey); }
        });
    }

    /**
     * Closes the unshipped remainder of a PartiallyFulfilled order (D-14, ADR-020; mirror of the PO close-remainder of
     * ADR-009): Admin only, mandatory reason. One transaction: lock the order, then StockService releases the remaining
     * reservation (stock rows locked in product order), the closure row is stored and the strict
     * `sales-orders.close-remainder` audit row records reason and released units. The status stays PartiallyFulfilled
     * (goods were shipped); further issues are refused. An Approved order with nothing shipped is refused: cancel it.
     */
    public function closeRemaining(AuthContext $actor, int $id, mixed $reason): void
    {
        $this->assertAdmin($actor);
        $reason = BusinessOperationInput::reason($reason);
        $exceptions = $this->exceptions ?? throw new \LogicException('Order exception repository is required.');
        $this->stockService->transaction(function () use ($actor, $id, $reason, $exceptions): void {
            $order = $this->findOrder($id);
            if ($exceptions->salesClosure($id) !== null) {
                throw new ValidationException('The remaining fulfilment of this sales order is already closed.');
            }
            if ($order->status() === SalesOrder::STATUS_APPROVED) {
                throw new ValidationException('Nothing has been issued for this order yet. Cancel it instead; cancellation releases the whole reservation.');
            }
            if ($order->status() !== SalesOrder::STATUS_PARTIALLY_FULFILLED) {
                throw new ValidationException('Only a partially fulfilled sales order can close its remaining fulfilment.');
            }
            $units = $order->remainingUnits();
            $this->stockService->release(
                $order->sourceWarehouseId(),
                $this->remainingMovements($order),
                $actor->userId(),
                'SO',
                $order->id(),
                static function () use ($exceptions, $id, $actor, $reason, $units): null {
                    $exceptions->closeSales($id, $actor->userId(), $reason, $units);

                    return null;
                },
                'sales-orders.close-remainder',
                ['reason' => $reason, 'closed_units' => $units],
            );
        });
    }

    /** Closure metadata of the order's remaining fulfilment, or null when it is open (or no repository is wired). @return array<string, mixed>|null */
    public function remainderClosure(int $id): ?array
    {
        return $this->exceptions?->salesClosure($id);
    }

    /** @return list<StockMovement> */
    private function movements(SalesOrder $order): array
    {
        return array_map(static fn ($item): StockMovement => new StockMovement($item->productId(), $item->quantity()), $order->items());
    }

    /** Unissued quantity per line, i.e. what the order still holds reserved. @return list<StockMovement> */
    private function remainingMovements(SalesOrder $order): array
    {
        $movements = [];
        foreach ($order->items() as $item) {
            if ($item->remainingQuantity() > 0) {
                $movements[] = new StockMovement($item->productId(), $item->remainingQuantity());
            }
        }

        return $movements;
    }

    /** @return array<int, int> item id => remaining quantity in line units, for lines with something left */
    private function remainingUnitsByItem(SalesOrder $order): array
    {
        $remaining = [];
        foreach ($order->items() as $item) {
            if ($item->remainingUnitQuantity() > 0) {
                $remaining[$item->id()] = $item->remainingUnitQuantity();
            }
        }

        return $remaining;
    }

    /**
     * @param array<int, int> $quantities
     * @return array<int, int> non-zero quantities per item id
     */
    private function requestedIssue(array $quantities): array
    {
        $requested = [];
        foreach ($quantities as $itemId => $quantity) {
            if ($quantity < 0) {
                throw new ValidationException('Issue quantity cannot be negative.');
            }
            if ($quantity > 0) {
                $requested[$itemId] = $quantity;
            }
        }
        if ($requested === []) {
            throw new ValidationException('Enter an issue quantity above zero for at least one line.');
        }

        return $requested;
    }

    /**
     * Validates the shipment against the locked order: every item belongs to the order and quantity ≤ remaining.
     *
     * @param array<int, int> $requested
     * @return array{0: array<int, int>, 1: list<StockMovement>}
     */
    private function acceptedIssue(SalesOrder $order, array $requested): array
    {
        $accepted = [];
        $movements = [];
        $known = [];
        foreach ($order->items() as $item) {
            $known[$item->id()] = true;
            $quantity = $requested[$item->id()] ?? 0;
            if ($quantity === 0) {
                continue;
            }
            if ($quantity > $item->remainingUnitQuantity()) {
                throw new ValidationException('Issue quantity cannot exceed the remaining quantity (' . $item->remainingUnitQuantity() . ' left on line #' . $item->id() . ').');
            }
            $base = $quantity * $item->unitFactor();
            $accepted[$item->id()] = $base;
            $movements[] = new StockMovement($item->productId(), $base);
        }
        if (array_diff_key($requested, $known) !== []) {
            throw new ValidationException('Issue quantities must refer to lines of this sales order.');
        }
        if ($accepted === []) {
            throw new ValidationException('Nothing is left to issue on this sales order.');
        }

        return [$accepted, $movements];
    }

    /** @param array<int, int> $accepted */
    private function recordIssue(int $id, array $accepted, string $status): null
    {
        $this->orders->recordIssue($id, $accepted, $status);

        return null;
    }


    private function markApproved(int $id, int $approvedBy): null
    {
        $this->orders->approve($id, $approvedBy);

        return null;
    }

    private function markCancelled(int $id): null
    {
        $this->orders->cancel($id);

        return null;
    }

    private function assertCanCreate(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN && $actor->role() !== User::ROLE_SALES) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertCanIssue(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN && $actor->role() !== User::ROLE_WAREHOUSE_STAFF) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertAdmin(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertOwnOrAdmin(AuthContext $actor, SalesOrder $order): void
    {
        if ($actor->role() === User::ROLE_ADMIN) {
            return;
        }
        if ($actor->role() !== User::ROLE_SALES || $order->createdBy() !== $actor->userId()) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertHeader(int $customerId, int $warehouseId): void
    {
        $customer = $this->customers[$customerId] ?? null;
        if (!$customer instanceof Customer || !$customer->isActive()) {
            throw new ValidationException('Active customer is required.');
        }
        $warehouse = $this->warehouses[$warehouseId] ?? null;
        if (!$warehouse instanceof Warehouse || !$warehouse->isActive()) {
            throw new ValidationException('Active warehouse is required.');
        }
    }

    private function findOrder(int $id): SalesOrder
    {
        return $this->orders->lockById($id) ?? throw new ValidationException('Sales order not found.');
    }
}
