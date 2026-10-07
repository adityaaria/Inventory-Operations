<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\StockRepositoryInterface;
use App\Repository\Contract\WarehouseRepositoryInterface;
use App\Security\AuthContext;

/**
 * Read-only revalidation of a locally restored form draft against current permissions, master-data status
 * and stock. Advisory only: submitting the form still runs every service rule, lock and transaction.
 */
final class DraftCheckService
{
    /** Form key => roles allowed to submit it; mirrors the create routes. */
    public const FORMS = [
        'purchase-order' => [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF],
        'sales-order' => [User::ROLE_ADMIN, User::ROLE_SALES],
        'stock-proposal' => [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF],
    ];
    private const MAX_ITEMS = 100;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly StockRepositoryInterface $stocks,
    ) {
    }

    /**
     * @param list<int> $productIds
     * @return array{form: string, warehouse: array{id: int, active: bool}|null, items: list<array{product_id: int, active: bool, available: int|null}>}
     */
    public function check(AuthContext $actor, string $form, ?int $warehouseId, array $productIds): array
    {
        if (!isset(self::FORMS[$form])) {
            throw new ValidationException('Unknown draft form.');
        }
        if (!in_array($actor->role(), self::FORMS[$form], true)) {
            throw new HttpException(403, 'Forbidden');
        }
        if (count($productIds) > self::MAX_ITEMS) {
            throw new ValidationException('Use at most ' . self::MAX_ITEMS . ' product items.');
        }
        $warehouse = $warehouseId === null ? null : $this->warehouses->findById($warehouseId);
        $warehouseActive = $warehouse !== null && $warehouse->isActive();
        $stockWarehouse = $warehouseActive ? $warehouseId : null;
        $items = [];
        foreach (array_values(array_unique($productIds)) as $productId) {
            $product = $this->products->findById($productId);
            $items[] = [
                'product_id' => $productId,
                'active' => $product !== null && $product->isActive(),
                'available' => $product !== null && $stockWarehouse !== null ? $this->stocks->quantity($productId, $stockWarehouse) : null,
            ];
        }

        return [
            'form' => $form,
            'warehouse' => $warehouseId === null ? null : ['id' => $warehouseId, 'active' => $warehouseActive],
            'items' => $items,
        ];
    }
}
