<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\PriceListRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\TransactionManagerInterface;
use App\Security\AuthContext;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\RequestOrigin;
use App\Support\TaxSettings;

/**
 * Admin maintenance of customer price lists (D-19, ADR-025): lists (name, active, validity), tiered items per product
 * and unit, and customer assignment. 403 for every other role before any input is read. An item may not be below the
 * current cost per unit (base purchase price × factor) when it is maintained. Each change and its strict audit row are
 * written in one transaction. Lists are deactivated, not deleted (SO lines reference them).
 */
final class PriceListService
{
    private const PRICE_PATTERN = '/^\d{1,12}(?:\.\d{1,2})?$/D';

    public function __construct(
        private readonly PriceListRepositoryInterface $lists,
        private readonly CustomerRepositoryInterface $customers,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductUnitCatalog $units,
        private readonly TransactionManagerInterface $transactions,
        private readonly ?AuditLogRepositoryInterface $audit = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(AuthContext $actor): array
    {
        $this->assertAdmin($actor);

        return $this->lists->all();
    }

    /** @return array<string, mixed> list + items + assigned customers */
    public function show(AuthContext $actor, int $id): array
    {
        $this->assertAdmin($actor);
        $list = $this->lists->find($id) ?? throw new HttpException(404, 'Price list not found.');

        return $list + ['items' => $this->lists->items($id), 'customers' => $this->customers->forPriceList($id), 'today' => $this->lists->today()];
    }

    /** @param array<string, mixed> $input name, description, is_active, valid_from, valid_to */
    public function create(AuthContext $actor, array $input): int
    {
        $this->assertAdmin($actor);
        $header = self::header($input);

        return $this->transactions->run(function () use ($actor, $header): int {
            $id = $this->lists->create(...$header);
            $this->record($actor, 'price-lists.create', $id, ['name' => $header[0], 'is_active' => $header[2], 'valid_from' => $header[3], 'valid_to' => $header[4]]);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(AuthContext $actor, int $id, array $input): void
    {
        $this->assertAdmin($actor);
        $header = self::header($input);
        $this->transactions->run(function () use ($actor, $id, $header): void {
            $before = $this->lists->find($id) ?? throw new ValidationException('Price list not found.');
            $this->lists->update($id, ...$header);
            $this->record($actor, 'price-lists.update', $id, ['name' => $header[0], 'is_active' => $header[2], 'valid_from' => $header[3], 'valid_to' => $header[4],
                'previous' => ['name' => $before['name'], 'is_active' => $before['is_active'], 'valid_from' => $before['valid_from'], 'valid_to' => $before['valid_to']]]);
        });
    }

    /** @param array<string, mixed> $input product_id, unit_code, min_quantity, unit_price */
    public function addItem(AuthContext $actor, int $priceListId, array $input): int
    {
        $this->assertAdmin($actor);
        $productId = \App\Validation\InputValidator::positiveInt('product_id', $input['product_id'] ?? '');
        $product = $this->products->findById($productId);
        if ($product === null || !$product->isActive()) {
            throw new ValidationException('Active product is required.');
        }
        $unitCode = is_string($input['unit_code'] ?? null) ? trim((string) $input['unit_code']) : '';
        $unit = $this->units->forProduct($product)->find($unitCode === '' ? $product->unit() : $unitCode);
        if ($unit === null || !$unit->isActive()) {
            throw new ValidationException('Unit "' . $unitCode . '" is not an active unit of ' . $product->sku() . '.');
        }
        $minimum = self::minimum($input['min_quantity'] ?? '');
        $price = $this->price($input['unit_price'] ?? null, $product, $unit);

        return $this->transactions->run(function () use ($actor, $priceListId, $product, $unit, $minimum, $price): int {
            $this->lists->find($priceListId) ?? throw new ValidationException('Price list not found.');
            $id = $this->lists->addItem($priceListId, $product->id(), $unit->code(), $minimum, TaxSettings::decimal($price));
            $this->record($actor, 'price-lists.item-add', $priceListId, ['item_id' => $id, 'product_id' => $product->id(), 'unit_code' => $unit->code(), 'min_quantity' => $minimum, 'unit_price' => TaxSettings::decimal($price)]);

            return $id;
        });
    }

    /** @param array<string, mixed> $input min_quantity, unit_price */
    public function updateItem(AuthContext $actor, int $itemId, array $input): void
    {
        $this->assertAdmin($actor);
        $item = $this->lists->findItem($itemId) ?? throw new ValidationException('Price list item not found.');
        $product = $this->products->findById($item['product_id']) ?? throw new ValidationException('Product not found.');
        $unit = $this->units->forProduct($product)->find($item['unit_code']) ?? throw new ValidationException('Unit not found.');
        $minimum = self::minimum($input['min_quantity'] ?? '');
        $price = $this->price($input['unit_price'] ?? null, $product, $unit);
        $this->transactions->run(function () use ($actor, $item, $minimum, $price): void {
            $this->lists->updateItem($item['id'], $minimum, TaxSettings::decimal($price));
            $this->record($actor, 'price-lists.item-update', $item['price_list_id'], ['item_id' => $item['id'], 'min_quantity' => $minimum, 'unit_price' => TaxSettings::decimal($price),
                'previous' => ['min_quantity' => $item['min_quantity'], 'unit_price' => $item['unit_price']]]);
        });
    }

    public function deleteItem(AuthContext $actor, int $itemId): int
    {
        $this->assertAdmin($actor);
        $item = $this->lists->findItem($itemId) ?? throw new ValidationException('Price list item not found.');
        $this->transactions->run(function () use ($actor, $item): void {
            $this->lists->deleteItem($item['id']);
            $this->record($actor, 'price-lists.item-delete', $item['price_list_id'], ['item_id' => $item['id'], 'product_id' => $item['product_id'], 'unit_code' => $item['unit_code'],
                'min_quantity' => $item['min_quantity'], 'unit_price' => $item['unit_price']]);
        });

        return $item['price_list_id'];
    }

    /** Assigns the customer to the list, or with null removes the assignment (master prices). */
    public function assign(AuthContext $actor, int $customerId, ?int $priceListId): void
    {
        $this->assertAdmin($actor);
        $customer = $this->customers->findById($customerId) ?? throw new ValidationException('Customer not found.');
        if ($priceListId !== null && $this->lists->find($priceListId) === null) {
            throw new ValidationException('Price list not found.');
        }
        $this->transactions->run(function () use ($actor, $customer, $priceListId): void {
            $this->customers->assignPriceList($customer->id(), $priceListId);
            $this->audit?->append($actor->userId(), 'customers.price-list', 'customers', $customer->id(), 'success', RequestOrigin::none(),
                ['price_list_id' => $priceListId, 'previous_price_list_id' => $customer->priceListId()]);
        });
    }

    /** @param array<string, mixed> $input @return array{0: string, 1: string, 2: bool, 3: ?string, 4: ?string} */
    private static function header(array $input): array
    {
        $name = trim((string) (is_string($input['name'] ?? null) ? $input['name'] : ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('Price list name is required (at most 120 characters).');
        }
        $description = trim((string) (is_string($input['description'] ?? null) ? $input['description'] : ''));
        if (mb_strlen($description) > 255) {
            throw new ValidationException('Description may have at most 255 characters.');
        }
        $date = static function (mixed $value, string $label): ?string {
            $text = is_string($value) ? trim($value) : '';
            if ($text === '') {
                return null;
            }
            if (!BusinessDate::isValidDate($text)) {
                throw new ValidationException($label . ' must be a valid date (YYYY-MM-DD).');
            }

            return $text;
        };
        $from = $date($input['valid_from'] ?? null, 'Valid from');
        $to = $date($input['valid_to'] ?? null, 'Valid to');
        if ($from !== null && $to !== null && $to < $from) {
            throw new ValidationException('Valid to cannot be before valid from.');
        }

        return [$name, $description, in_array($input['is_active'] ?? '', ['1', 1, true, 'on'], true), $from, $to];
    }

    private static function minimum(mixed $value): int
    {
        $minimum = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if (!is_int($minimum)) {
            throw new ValidationException('Minimum quantity must be a whole number of at least 1.');
        }

        return $minimum;
    }

    /** Price per unit in cents; never below the current cost per unit (base purchase price × factor). */
    private function price(mixed $value, \App\Entity\Product $product, \App\Entity\ProductUnit $unit): int
    {
        $text = is_string($value) ? trim($value) : (is_int($value) ? (string) $value : '');
        if (preg_match(self::PRICE_PATTERN, $text) !== 1) {
            throw new ValidationException('Unit price must be a non-negative amount with at most 2 decimals.');
        }
        $price = TaxSettings::cents($text);
        $cost = $unit->costCents($product);
        if ($price < $cost) {
            throw new ValidationException(sprintf('Price list price for %s (%s per %s) is below its current purchase price (%s per %s); below-cost prices are not allowed.',
                $product->sku(), Money::rupiah($price / 100), $unit->code(), Money::rupiah($cost / 100), $unit->code()));
        }

        return $price;
    }

    /** @param array<string, mixed> $metadata */
    private function record(AuthContext $actor, string $action, int $priceListId, array $metadata): void
    {
        $this->audit?->append($actor->userId(), $action, 'price_lists', $priceListId, 'success', RequestOrigin::none(), $metadata);
    }

    private function assertAdmin(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN) {
            throw new HttpException(403, 'Forbidden');
        }
    }
}
