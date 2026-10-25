<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductUnit;
use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\PriceListRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Security\AuthContext;
use App\Support\Money;
use App\Validation\InputValidator;

/**
 * Price resolution for a new SO or quotation line (D-19, ADR-025). One rule for the form, the API and the server:
 * the customer's assigned price list, when active and valid today (APP_TIMEZONE), at the highest tier whose
 * min_quantity ≤ the line's unit quantity, for exactly the line's product and unit; otherwise the master unit price
 * (ADR-022). A tier below the current cost per unit (base purchase price × factor) is refused instead of being used.
 *
 * @phpstan-type Resolution array{source: string, price_cents: int, master_cents: int, price_list_id: ?int, price_list_name: ?string, item_id: ?int, min_quantity: ?int, next_tier: ?array{min_quantity: int, price_cents: int}}
 */
final class PriceResolver
{
    public const SOURCE_MASTER = 'MASTER';
    public const SOURCE_PRICE_LIST = 'PRICE_LIST';

    public function __construct(
        private readonly PriceListRepositoryInterface $lists,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductUnitCatalog $units,
    ) {
    }

    /** @return Resolution */
    public function resolve(int $customerId, Product $product, ProductUnit $unit, int $unitQuantity, ?string $today = null): array
    {
        $master = OrderLinePricing::cents($unit->unitSellingPrice($product));
        $chosen = null;
        $next = null;
        foreach ($this->lists->tiersFor($customerId, $product->id(), $unit->code(), $today ?? $this->lists->today()) as $tier) {
            if ($tier['min_quantity'] <= max(1, $unitQuantity)) {
                $chosen = $tier;
            } elseif ($next === null) {
                $next = $tier;
            }
        }
        $nextTier = $next === null ? null : ['min_quantity' => $next['min_quantity'], 'price_cents' => self::decimalCents($next['unit_price'])];
        if ($chosen === null) {
            return ['source' => self::SOURCE_MASTER, 'price_cents' => $master, 'master_cents' => $master, 'price_list_id' => null, 'price_list_name' => null, 'item_id' => null, 'min_quantity' => null, 'next_tier' => $nextTier];
        }
        $price = self::decimalCents($chosen['unit_price']);
        $cost = $unit->costCents($product);
        if ($price < $cost) {
            throw new ValidationException(sprintf(
                'Price list "%s" sets %s per %s for %s from %d %s, below its current purchase price (%s per %s). An Admin must update the price list before it can be used.',
                $chosen['price_list_name'], Money::rupiah($price / 100), $unit->code(), $product->sku(), $chosen['min_quantity'], $unit->code(), Money::rupiah($cost / 100), $unit->code(),
            ));
        }

        return ['source' => self::SOURCE_PRICE_LIST, 'price_cents' => $price, 'master_cents' => $master, 'price_list_id' => $chosen['price_list_id'], 'price_list_name' => $chosen['price_list_name'],
            'item_id' => $chosen['item_id'], 'min_quantity' => $chosen['min_quantity'], 'next_tier' => $nextTier];
    }

    /**
     * GET /api/price-resolution for the SO and quotation forms (Admin and Sales; Warehouse has no pricing work, 403).
     * The answer is informational: the server re-resolves every line when the document is saved.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function lookup(AuthContext $actor, array $query): array
    {
        if (!in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_SALES], true)) {
            throw new HttpException(403, 'Forbidden');
        }
        $customerId = InputValidator::positiveInt('customer_id', $query['customer_id'] ?? '');
        $product = $this->products->findById(InputValidator::positiveInt('product_id', $query['product_id'] ?? ''));
        if ($product === null || !$product->isActive()) {
            throw new ValidationException('Active product is required.');
        }
        $quantityInput = $query['quantity'] ?? '';
        $quantity = $quantityInput === '' ? 1 : InputValidator::positiveInt('quantity', $quantityInput);
        $unitCode = is_string($query['unit_code'] ?? null) ? (string) $query['unit_code'] : null;
        $unit = $this->units->forProduct($product)->resolve($unitCode, $product->sku());
        $resolved = $this->resolve($customerId, $product, $unit, $quantity);

        return [
            'product_id' => $product->id(),
            'unit_code' => $unit->code(),
            'quantity' => $quantity,
            'source' => $resolved['source'],
            'price' => self::decimal($resolved['price_cents']),
            'master_price' => self::decimal($resolved['master_cents']),
            'price_list_id' => $resolved['price_list_id'],
            'price_list_name' => $resolved['price_list_name'],
            'min_quantity' => $resolved['min_quantity'],
            'next_tier' => $resolved['next_tier'] === null ? null : ['min_quantity' => $resolved['next_tier']['min_quantity'], 'price' => self::decimal($resolved['next_tier']['price_cents'])],
            'label' => self::label($resolved, $unit->code()),
        ];
    }

    /** "Price list Grosir, from 10 karton" or "Master price". @param Resolution $resolved */
    public static function label(array $resolved, string $unitCode): string
    {
        return $resolved['source'] === self::SOURCE_PRICE_LIST
            ? 'Price list ' . $resolved['price_list_name'] . ', from ' . $resolved['min_quantity'] . ' ' . $unitCode
            : 'Master price';
    }

    private static function decimalCents(string $amount): int
    {
        return \App\Support\TaxSettings::cents($amount);
    }

    private static function decimal(int $cents): string
    {
        return \App\Support\TaxSettings::decimal($cents);
    }
}
