<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Customer price lists with quantity tiers per product and unit (D-19, ADR-025). Amounts are plain decimal strings.
 *
 * @phpstan-type PriceListRow array{id: int, name: string, description: string, is_active: bool, valid_from: ?string, valid_to: ?string, item_count: int, customer_count: int}
 * @phpstan-type PriceListItemRow array{id: int, price_list_id: int, product_id: int, sku: string, product_name: string, unit_code: string, min_quantity: int, unit_price: string}
 * @phpstan-type PriceTierRow array{item_id: int, price_list_id: int, price_list_name: string, min_quantity: int, unit_price: string}
 */
interface PriceListRepositoryInterface
{
    /** @return list<PriceListRow> by name */
    public function all(): array;

    /** @return PriceListRow|null */
    public function find(int $id): ?array;

    public function create(string $name, string $description, bool $isActive, ?string $validFrom, ?string $validTo): int;

    public function update(int $id, string $name, string $description, bool $isActive, ?string $validFrom, ?string $validTo): void;

    /** @return list<PriceListItemRow> by SKU, unit and tier */
    public function items(int $priceListId): array;

    /** @return PriceListItemRow|null */
    public function findItem(int $itemId): ?array;

    public function addItem(int $priceListId, int $productId, string $unitCode, int $minQuantity, string $unitPrice): int;

    public function updateItem(int $itemId, int $minQuantity, string $unitPrice): void;

    public function deleteItem(int $itemId): void;

    /**
     * Tiers of the customer's assigned list for the product and unit, ascending by min_quantity, only when the list is
     * active and valid on $today (valid_from <= today <= valid_to, open ends allowed). Empty = master price.
     *
     * @return list<PriceTierRow>
     */
    public function tiersFor(int $customerId, int $productId, string $unitCode, string $today): array;

    /** Business date (APP_TIMEZONE) on the session clock, as the expiry rule (ADR-023). */
    public function today(): string;
}
