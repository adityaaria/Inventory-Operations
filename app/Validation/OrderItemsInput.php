<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;

/** Parses the primary order line plus up to 99 supplementary `items[n][...]` lines; services reject duplicates. */
final class OrderItemsInput
{
    public const MAX_ITEMS = 100;

    /**
     * Reads the line price field (`purchase_price`) and `price_reason` as raw text; PurchaseOrderService validates them
     * against the master price (D-13, ADR-019). A posted `list_price` is never read: the server snapshots the master.
     *
     * @param array<string, mixed> $post
     * @return list<array{product_id: int, quantity: int, unit_code: string|null, price: string|null, price_reason: string|null}>
     */
    public static function purchase(array $post): array
    {
        return self::lines($post, 'purchase_price');
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{product_id: int, quantity: int, unit_code: string|null, price: string|null, price_reason: string|null}>
     */
    public static function sales(array $post): array
    {
        return self::lines($post, 'selling_price');
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{product_id: int, quantity: int, unit_code: string|null, price: string|null, price_reason: string|null}>
     */
    private static function lines(array $post, string $priceField): array
    {
        $extra = $post['items'] ?? [];
        if (!is_array($extra) || count($extra) > self::MAX_ITEMS - 1) {
            throw new ValidationException('Use at most ' . self::MAX_ITEMS . ' product items.');
        }
        $items = [];
        foreach ([$post, ...array_values($extra)] as $line) {
            if (!is_array($line)) {
                throw new ValidationException('Invalid product item.');
            }
            $items[] = [
                'product_id' => InputValidator::positiveInt('product_id', $line['product_id'] ?? ''),
                // Quantity in the chosen unit (D-16, ADR-022); an empty or missing unit means the base unit.
                'quantity' => InputValidator::positiveInt('quantity', $line['quantity'] ?? ''),
                'unit_code' => self::text($line['unit_code'] ?? null, 'Unit'),
                'price' => self::text($line[$priceField] ?? null, 'Price'),
                'price_reason' => self::text($line['price_reason'] ?? null, 'Price reason'),
            ];
        }

        return $items;
    }

    private static function text(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ValidationException($label . ' must be text.');
        }

        return $value;
    }
}
