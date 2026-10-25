<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProductStock;
use App\Exception\HttpException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\TransactionManagerInterface;
use App\Security\AuthContext;
use App\Support\ReorderThreshold;
use App\Support\RequestOrigin;

/**
 * Per-warehouse reorder point override (D-10, ADR-016). Admin only. Changes a threshold, never a quantity,
 * so no stock ledger row is written; the change and its audit row commit together.
 */
final class ReorderPointService
{
    public const AUDIT_ACTION = 'products.reorder-point';

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly MasterDataAuthorizationService $authorization,
        private readonly AuditLogRepositoryInterface $audit,
        private readonly TransactionManagerInterface $transactions,
    ) {
    }

    public function assertCanManage(AuthContext $actor): void
    {
        if (!$this->authorization->canWrite($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    /** Sets the override, or clears it when $override is null. Returns the pair as stored after the change. */
    public function setOverride(AuthContext $actor, int $productId, int $warehouseId, ?int $override): ProductStock
    {
        $this->assertCanManage($actor);
        ReorderThreshold::assertValidOverride($override);

        return $this->transactions->run(function () use ($actor, $productId, $warehouseId, $override): ProductStock {
            $stock = $this->products->lockStock($productId, $warehouseId)
                ?? throw new HttpException(404, 'Product stock for this warehouse was not found.');
            $previous = $stock->reorderPointOverride();
            if ($previous === $override) {
                return $stock;
            }
            $this->products->setReorderPointOverride($productId, $warehouseId, $override);
            $this->audit->append($actor->userId(), self::AUDIT_ACTION, 'products', $productId, 'success', RequestOrigin::none(), [
                'warehouse_id' => $warehouseId,
                'previous_override' => $previous,
                'override' => $override,
                'product_reorder_point' => $stock->reorderPoint(),
                'effective_reorder_point' => ReorderThreshold::effective($stock->reorderPoint(), $override),
            ]);

            return $this->products->lockStock($productId, $warehouseId) ?? $stock;
        });
    }
}
