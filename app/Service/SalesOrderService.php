<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\SalesOrderRepositoryInterface;
use App\Security\AuthContext;
use App\Exception\ValidationException;

final class SalesOrderService
{
    /**
     * @param array<int, Customer> $customers
     * @param array<int, Warehouse> $warehouses
     */
    public function __construct(
        private readonly SalesOrderRepositoryInterface $orders,
        private readonly ProductRepositoryInterface $products,
        private readonly array $customers,
        private readonly array $warehouses,
        private readonly StockService $stockService,
        private readonly ?OperationIdempotency $idempotency = null,
    ) {
    }

    /**
     * @param list<array{product_id: int, quantity: int, selling_price: float}> $items
     */
    public function createDraft(AuthContext $actor, string $orderNumber, int $customerId, int $warehouseId, array $items): SalesOrder
    {
        $this->assertCanCreate($actor);
        $this->assertHeader($orderNumber, $customerId, $warehouseId);
        $this->assertItems($items);

        return $this->orders->createDraft(trim($orderNumber), $customerId, $warehouseId, $actor->userId(), $items);
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

    public function approve(AuthContext $actor, int $id): void
    {
        $this->stockService->transaction(function () use ($actor, $id): void {
            $this->assertAdmin($actor);
            $order = $this->findOrder($id);
            if ($order->status() !== SalesOrder::STATUS_PENDING_APPROVAL) {
                throw new ValidationException('Only PendingApproval sales orders can be approved.');
            }

            $this->orders->approve($id, $actor->userId());

        });
    }

    public function rejectOrCancel(AuthContext $actor, int $id): void
    {
        $this->stockService->transaction(function () use ($actor, $id): void {
            $this->assertAdmin($actor);
            $order = $this->findOrder($id);
            if ($order->status() === SalesOrder::STATUS_FULFILLED) {
                throw new ValidationException('Fulfilled sales orders cannot be cancelled.');
            }

            $this->orders->cancel($id);

        });
    }

    public function issue(AuthContext $actor, int $id, ?string $requestKey = null): void
    {
        $this->stockService->transaction(function () use ($actor, $id, $requestKey): void {
            $this->assertCanIssue($actor);
            $order = $this->findOrder($id);
            if ($requestKey !== null) {
                if ($this->idempotency === null) { throw new \LogicException('Idempotency repository is required.'); }
                if ($this->idempotency->replay($actor->userId(), $requestKey, 'issue', $id)) { return; }
            }
            if ($order->status() !== SalesOrder::STATUS_APPROVED) {
                throw new ValidationException('Only Approved sales orders can be issued.');
            }

            $movements = [];
            foreach ($order->items() as $item) {
                $movements[] = new StockMovement($item->productId(), $item->quantity());
            }

            $this->stockService->issue(
                $order->sourceWarehouseId(),
                $movements,
                $actor->userId(),
                'SO',
                $order->id(),
                fn (): null => $this->markFulfilled($order->id()),
            );
            if ($requestKey !== null) { $this->idempotency->complete($actor->userId(), $requestKey); }

        });
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

    private function assertHeader(string $orderNumber, int $customerId, int $warehouseId): void
    {
        \App\Validation\InputValidator::requiredString('order_number', $orderNumber, 50);
        if (trim($orderNumber) === '') {
            throw new ValidationException('Order number is required.');
        }
        $customer = $this->customers[$customerId] ?? null;
        if (!$customer instanceof Customer || !$customer->isActive()) {
            throw new ValidationException('Active customer is required.');
        }
        $warehouse = $this->warehouses[$warehouseId] ?? null;
        if (!$warehouse instanceof Warehouse || !$warehouse->isActive()) {
            throw new ValidationException('Active warehouse is required.');
        }
    }

    /**
     * @param list<array{product_id: int, quantity: int, selling_price: float}> $items
     */
    private function assertItems(array $items): void
    {
        if ($items === []) {
            throw new ValidationException('At least one item is required.');
        }
        $seenProducts = [];
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
            \App\Validation\InputValidator::nonNegativeMoney('selling_price', $item['selling_price']);
            if ($item['selling_price'] < 0) {
                throw new ValidationException('Selling price cannot be negative.');
            }
            $seenProducts[$item['product_id']] = true;
        }
    }

    private function findOrder(int $id): SalesOrder
    {
        return $this->orders->lockById($id) ?? throw new ValidationException('Sales order not found.');
    }

    private function markFulfilled(int $id): null
    {
        $this->orders->markFulfilled($id);

        return null;
    }
}
