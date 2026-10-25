<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Repository\Contract\DocumentSequenceRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\PurchaseOrderRepositoryInterface;
use App\Security\AuthContext;
use App\Support\OrderNumber;
use App\Exception\ValidationException;

final class PurchaseOrderService
{
    /**
     * @param array<int, Supplier> $suppliers
     * @param array<int, Warehouse> $warehouses
     */
    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $orders,
        private readonly DocumentSequenceRepositoryInterface $sequences,
        private readonly ProductRepositoryInterface $products,
        private readonly array $suppliers,
        private readonly array $warehouses,
        private readonly StockService $stockService,
        private readonly ?OperationIdempotency $idempotency = null,
        private readonly ?\App\Repository\Contract\OrderExceptionRepositoryInterface $exceptions = null,
        private readonly OrderLinePricing $pricing = new OrderLinePricing(),
        private readonly ?ProductUnitCatalog $units = null,
    ) {
    }

    private function units(): ProductUnitCatalog
    {
        return $this->units ?? new ProductUnitCatalog($this->products);
    }

    /**
     * The order number is assigned by the server (D-11, ADR-017); clients cannot choose it.
     * Line prices default to the product master purchase_price; a different `price` needs a `price_reason` (D-13,
     * ADR-019, see OrderLinePricing). The master snapshot (list_price) is always taken here, never from the client.
     *
     * Units (D-16, ADR-022): `quantity` is in the line's `unit_code` (empty = base unit); the line stores the unit snapshot
     * (code, factor, quantity as entered) and the base quantity; the price is per entered unit.
     *
     * @param list<array{product_id: int, quantity: int, unit_code?: string|null, price?: mixed, price_reason?: mixed}> $items
     */
    public function createDraft(AuthContext $actor, int $supplierId, int $warehouseId, array $items): PurchaseOrder
    {
        $this->assertCanCreate($actor);
        $this->assertHeader($supplierId, $warehouseId);
        $lines = $this->pricedItems($items);

        // The number is claimed in the same transaction as the draft insert, after validation: invalid input never
        // touches the sequence, and a failed insert rolls the claim back (no gap, never a duplicate).
        return $this->stockService->transaction(function () use ($supplierId, $warehouseId, $actor, $lines): PurchaseOrder {
            $period = $this->sequences->currentPeriod();
            $number = OrderNumber::format(OrderNumber::PURCHASE, $period, $this->sequences->next(OrderNumber::PURCHASE, $period));
            $this->units()->confirmSnapshots($lines);

            return $this->orders->createDraft($number, $supplierId, $warehouseId, $actor->userId(), $lines);
        });
    }

    public function markOrdered(AuthContext $actor, int $id): void
    {
        $this->stockService->transaction(function () use ($actor, $id): void {
            $this->assertAdmin($actor);
            $order = $this->findOrder($id);
            if ($order->status() !== PurchaseOrder::STATUS_DRAFT) {
                throw new ValidationException('Only Draft purchase orders can be ordered.');
            }

            $this->orders->markOrdered($id);

        });
    }

    /**
     * Receipt quantities are whole line units (D-16, ADR-022: a karton line receives karton); stock moves in base units.
     *
     * Lots (D-17, ADR-023): $lotsByItemId maps an item id to its splits [{lot_code, expiry_date, quantity in line units}]
     * that must add up to the received quantity. Without splits a line enters one lot named after the order number
     * (refused for products that track expiry). Unit cost per base = effective line price ÷ unit factor, in whole cents.
     *
     * @param array<int, int> $receivedQuantitiesByItemId item id => quantity in the line unit
     * @param array<int, list<array{lot_code: string, expiry_date: string|null, quantity: int}>>|null $lotsByItemId
     */
    public function receive(AuthContext $actor, int $id, array $receivedQuantitiesByItemId, ?string $requestKey = null, ?array $lotsByItemId = null): void
    {
        $lotsByItemId = $this->normalizedLots($lotsByItemId ?? []);
        $this->stockService->transaction(function () use ($actor, $id, $receivedQuantitiesByItemId, $requestKey, $lotsByItemId): void {
            $this->assertCanReceive($actor);
            $order = $this->findOrder($id);
            if ($this->isReplayedReceipt($actor, $id, $receivedQuantitiesByItemId, $requestKey, $lotsByItemId)) { return; }
            if ($this->exceptions?->closure($id)!==null) { throw new ValidationException('This PO remainder is closed.'); }
            if (!in_array($order->status(), PurchaseOrder::RECEIVABLE_STATUSES, true)) {
                throw new ValidationException('Purchase order is not receivable.');
            }

            [$acceptedReceipts, $movements] = $this->acceptedReceipts($order, $receivedQuantitiesByItemId, $lotsByItemId);
            $status = $this->statusAfterReceipt($order, $acceptedReceipts);
            $this->stockService->receive(
                $order->destinationWarehouseId(),
                $movements,
                $actor->userId(),
                'PO',
                $order->id(),
                fn (): null => $this->recordReceipt($order->id(), $acceptedReceipts, $status),
            );
            if ($requestKey !== null) { $this->idempotency?->complete($actor->userId(), $requestKey); }

        });
    }

    /**
     * @param array<int, mixed> $lots
     * @return array<int, list<array{lot_code: string, expiry_date: string|null, quantity: int}>>
     */
    private function normalizedLots(array $lots): array
    {
        $result = [];
        foreach ($lots as $itemId => $splits) {
            if (!is_array($splits) || count($splits) > 50) {
                throw new ValidationException('Invalid lot splits.');
            }
            foreach ($splits as $split) {
                if (!is_array($split) || !is_int($split['quantity'] ?? null) || $split['quantity'] <= 0) {
                    throw new ValidationException('Enter a whole quantity above zero for every lot.');
                }
                $result[(int) $itemId][] = [
                    'lot_code' => \App\Validation\LotInput::code($split['lot_code'] ?? null),
                    'expiry_date' => \App\Validation\LotInput::expiry($split['expiry_date'] ?? null),
                    'quantity' => $split['quantity'],
                ];
            }
        }

        return $result;
    }

    /**
     * A repeated request key with the same payload is a completed replay; a different payload is rejected by the repository.
     * Lot splits join the fingerprint only when present, so keys from before D-17 hash as before.
     *
     * @param array<int, int> $receivedQuantitiesByItemId
     * @param array<int, list<array{lot_code: string, expiry_date: string|null, quantity: int}>> $lots
     */
    private function isReplayedReceipt(AuthContext $actor, int $id, array $receivedQuantitiesByItemId, ?string $requestKey, array $lots = []): bool
    {
        if ($requestKey === null) {
            return false;
        }
        if ($this->idempotency === null) { throw new \LogicException('Idempotency repository is required.'); }

        return $this->idempotency->replay($actor->userId(), $requestKey, 'receipt', $id, $receivedQuantitiesByItemId, $lots);
    }

    /**
     * @param array<int, int> $receivedQuantitiesByItemId
     * @param array<int, list<array{lot_code: string, expiry_date: string|null, quantity: int}>> $lotsByItemId
     * @return array{0: array<int, int>, 1: list<StockMovement>} accepted base quantity per item id and the matching stock movements
     */
    private function acceptedReceipts(PurchaseOrder $order, array $receivedQuantitiesByItemId, array $lotsByItemId = []): array
    {
        $acceptedReceipts = [];
        $movements = [];
        $known = [];
        foreach ($order->items() as $item) {
            $known[$item->id()] = true;
            $quantity = $receivedQuantitiesByItemId[$item->id()] ?? 0;
            if ($quantity === 0) {
                continue;
            }
            if ($quantity < 0) {
                throw new ValidationException('Receipt quantity must be positive.');
            }
            if ($quantity > $item->remainingUnitQuantity()) {
                throw new ValidationException('Receipt quantity cannot exceed remaining quantity.');
            }
            $base = $quantity * $item->unitFactor();
            $cost = \App\Validation\LotInput::costPerBase($item->purchasePrice(), $item->unitFactor());
            $splits = [];
            $sum = 0;
            foreach ($lotsByItemId[$item->id()] ?? [] as $split) {
                $sum += $split['quantity'];
                $splits[] = new LotSplit($split['lot_code'], $split['expiry_date'], $split['quantity'] * $item->unitFactor(), $cost);
            }
            if ($splits !== [] && $sum !== $quantity) {
                throw new ValidationException('Lot quantities (' . $sum . ') must add up to the received quantity (' . $quantity . ').');
            }
            $acceptedReceipts[$item->id()] = $base;
            $movements[] = new StockMovement($item->productId(), $base, $splits, $order->orderNumber(), $cost);
        }
        if (array_diff_key($lotsByItemId, $known) !== []) {
            throw new ValidationException('Lot splits must refer to lines of this purchase order.');
        }

        if ($acceptedReceipts === []) {
            throw new ValidationException('At least one receipt quantity is required.');
        }

        return [$acceptedReceipts, $movements];
    }

    public function cancel(AuthContext $actor, int $id): void
    {
        $this->stockService->transaction(function () use ($actor, $id): void {
            $this->assertAdmin($actor);
            $order = $this->findOrder($id);
            if ($order->status() !== PurchaseOrder::STATUS_DRAFT && $order->status() !== PurchaseOrder::STATUS_ORDERED) {
                throw new ValidationException('Only Draft or Ordered purchase orders can be cancelled.');
            }

            $this->orders->cancel($id);

        });
    }

    private function assertCanCreate(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN && $actor->role() !== User::ROLE_WAREHOUSE_STAFF) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertCanReceive(AuthContext $actor): void
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

    private function assertHeader(int $supplierId, int $warehouseId): void
    {
        $supplier = $this->suppliers[$supplierId] ?? null;
        if (!$supplier instanceof Supplier || !$supplier->isActive()) {
            throw new ValidationException('Active supplier is required.');
        }
        $warehouse = $this->warehouses[$warehouseId] ?? null;
        if (!$warehouse instanceof Warehouse || !$warehouse->isActive()) {
            throw new ValidationException('Active warehouse is required.');
        }
    }

    /**
     * Validates the lines and prices each one: master purchase_price by default, or a negotiated price with a reason.
     *
     * @param list<array{product_id: int, quantity: int, unit_code?: string|null, price?: mixed, price_reason?: mixed}> $items
     * @return list<array{product_id: int, quantity: int, unit_code: string, unit_factor: int, unit_quantity: int, purchase_price: float, list_price: float, price_reason: string|null}>
     */
    private function pricedItems(array $items): array
    {
        if ($items === []) {
            throw new ValidationException('At least one item is required.');
        }

        $seenProducts = [];
        $lines = [];
        foreach ($items as $item) {
            $product = $this->products->findById($item['product_id']);
            if (!$product instanceof Product || !$product->isActive()) {
                throw new ValidationException('Active product is required.');
            }
            if (isset($seenProducts[$item['product_id']])) {
                throw new ValidationException('Duplicate product lines are not allowed.');
            }
            if ($item['quantity'] <= 0) {
                throw new ValidationException('Quantity must be positive.');
            }
            $seenProducts[$item['product_id']] = true;
            $unit = $this->units()->forProduct($product)->resolve($item['unit_code'] ?? null, $product->sku());
            $priced = $this->pricing->purchaseLine($product, $item['price'] ?? null, $item['price_reason'] ?? null, $unit);
            $lines[] = [
                'product_id' => $item['product_id'],
                'quantity' => \App\Support\UnitSet::toBase($item['quantity'], $unit->factor()),
                'unit_code' => $unit->code(),
                'unit_factor' => $unit->factor(),
                'unit_quantity' => $item['quantity'],
                'purchase_price' => $priced['price'],
                'list_price' => $priced['list_price'],
                'price_reason' => $priced['price_reason'],
            ];
        }

        return $lines;
    }

    private function findOrder(int $id): PurchaseOrder
    {
        return $this->orders->lockById($id) ?? throw new ValidationException('Purchase order not found.');
    }

    /**
     * @param array<int, int> $receivedQuantitiesByItemId
     */
    private function recordReceipt(int $orderId, array $receivedQuantitiesByItemId, string $status): null
    {
        $this->orders->recordReceipt($orderId, $receivedQuantitiesByItemId, $status);

        return null;
    }

    /**
     * @param array<int, int> $receivedQuantitiesByItemId
     */
    private function statusAfterReceipt(PurchaseOrder $order, array $receivedQuantitiesByItemId): string
    {
        foreach ($order->items() as $item) {
            $received = $receivedQuantitiesByItemId[$item->id()] ?? 0;
            if ($item->remainingQuantity() - $received > 0) {
                return PurchaseOrder::STATUS_PARTIALLY_RECEIVED;
            }
        }

        return PurchaseOrder::STATUS_RECEIVED;
    }
}
