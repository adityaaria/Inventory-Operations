<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Exception\ValidationException;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\PriceListRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Support\BusinessDate;

/** Price lists for unit tests (D-19, ADR-025); customer assignment lives in the customer repository. */
final class InMemoryPriceListRepository implements PriceListRepositoryInterface
{
    /** @var array<int, array{id: int, name: string, description: string, is_active: bool, valid_from: ?string, valid_to: ?string}> */
    private array $lists = [];
    /** @var array<int, array{id: int, price_list_id: int, product_id: int, unit_code: string, min_quantity: int, unit_price: string}> */
    private array $items = [];
    private int $nextList = 1;
    private int $nextItem = 1;

    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly ?ProductRepositoryInterface $products = null,
        private ?string $today = null,
    ) {
    }

    public function setToday(string $today): void
    {
        $this->today = $today;
    }

    public function all(): array
    {
        $rows = array_map(fn (array $list): array => $this->header($list), array_values($this->lists));
        usort($rows, static fn (array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);

        return $rows;
    }

    public function find(int $id): ?array
    {
        return isset($this->lists[$id]) ? $this->header($this->lists[$id]) : null;
    }

    public function create(string $name, string $description, bool $isActive, ?string $validFrom, ?string $validTo): int
    {
        foreach ($this->lists as $list) {
            if (strcasecmp($list['name'], $name) === 0) {
                throw new ValidationException('A record with that unique value already exists.');
            }
        }
        $id = $this->nextList++;
        $this->lists[$id] = ['id' => $id, 'name' => $name, 'description' => $description, 'is_active' => $isActive, 'valid_from' => $validFrom, 'valid_to' => $validTo];

        return $id;
    }

    public function update(int $id, string $name, string $description, bool $isActive, ?string $validFrom, ?string $validTo): void
    {
        $this->lists[$id] = ['id' => $id, 'name' => $name, 'description' => $description, 'is_active' => $isActive, 'valid_from' => $validFrom, 'valid_to' => $validTo];
    }

    public function items(int $priceListId): array
    {
        $rows = array_values(array_map(fn (array $item): array => $this->item($item), array_filter($this->items, static fn (array $item): bool => $item['price_list_id'] === $priceListId)));
        usort($rows, static fn (array $a, array $b): int => [$a['sku'], $a['unit_code'], $a['min_quantity']] <=> [$b['sku'], $b['unit_code'], $b['min_quantity']]);

        return $rows;
    }

    public function findItem(int $itemId): ?array
    {
        return isset($this->items[$itemId]) ? $this->item($this->items[$itemId]) : null;
    }

    public function addItem(int $priceListId, int $productId, string $unitCode, int $minQuantity, string $unitPrice): int
    {
        foreach ($this->items as $item) {
            if ([$item['price_list_id'], $item['product_id'], $item['unit_code'], $item['min_quantity']] === [$priceListId, $productId, $unitCode, $minQuantity]) {
                throw new ValidationException('A record with that unique value already exists.');
            }
        }
        $id = $this->nextItem++;
        $this->items[$id] = ['id' => $id, 'price_list_id' => $priceListId, 'product_id' => $productId, 'unit_code' => $unitCode, 'min_quantity' => $minQuantity, 'unit_price' => $unitPrice];

        return $id;
    }

    public function updateItem(int $itemId, int $minQuantity, string $unitPrice): void
    {
        $this->items[$itemId]['min_quantity'] = $minQuantity;
        $this->items[$itemId]['unit_price'] = $unitPrice;
    }

    public function deleteItem(int $itemId): void
    {
        unset($this->items[$itemId]);
    }

    public function tiersFor(int $customerId, int $productId, string $unitCode, string $today): array
    {
        $listId = $this->customers->findById($customerId)?->priceListId();
        $list = $listId === null ? null : ($this->lists[$listId] ?? null);
        if ($list === null || !$list['is_active'] || ($list['valid_from'] !== null && $list['valid_from'] > $today) || ($list['valid_to'] !== null && $list['valid_to'] < $today)) {
            return [];
        }
        $tiers = [];
        foreach ($this->items as $item) {
            if ($item['price_list_id'] === $list['id'] && $item['product_id'] === $productId && $item['unit_code'] === $unitCode) {
                $tiers[] = ['item_id' => $item['id'], 'price_list_id' => $list['id'], 'price_list_name' => $list['name'], 'min_quantity' => $item['min_quantity'], 'unit_price' => $item['unit_price']];
            }
        }
        usort($tiers, static fn (array $a, array $b): int => $a['min_quantity'] <=> $b['min_quantity']);

        return $tiers;
    }

    public function today(): string
    {
        return $this->today ?? BusinessDate::today();
    }

    /** @param array{id: int, name: string, description: string, is_active: bool, valid_from: ?string, valid_to: ?string} $list @return array{id: int, name: string, description: string, is_active: bool, valid_from: ?string, valid_to: ?string, item_count: int, customer_count: int} */
    private function header(array $list): array
    {
        return $list + [
            'item_count' => count(array_filter($this->items, static fn (array $item): bool => $item['price_list_id'] === $list['id'])),
            'customer_count' => count($this->customers->forPriceList($list['id'])),
        ];
    }

    /** @param array{id: int, price_list_id: int, product_id: int, unit_code: string, min_quantity: int, unit_price: string} $item @return array{id: int, price_list_id: int, product_id: int, sku: string, product_name: string, unit_code: string, min_quantity: int, unit_price: string} */
    private function item(array $item): array
    {
        $product = $this->products?->findById($item['product_id']);

        return ['id' => $item['id'], 'price_list_id' => $item['price_list_id'], 'product_id' => $item['product_id'], 'sku' => $product?->sku() ?? ('#' . $item['product_id']), 'product_name' => $product?->name() ?? '',
            'unit_code' => $item['unit_code'], 'min_quantity' => $item['min_quantity'], 'unit_price' => $item['unit_price']];
    }
}
