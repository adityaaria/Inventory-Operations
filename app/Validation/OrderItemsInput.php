<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;

/** Parses the primary order line plus up to 99 supplementary `items[n][...]` lines; services reject duplicates. */
final class OrderItemsInput
{
    public const MAX_ITEMS = 100;

    /**
     * @param array<string, mixed> $post
     * @return list<array{product_id: int, quantity: int, purchase_price: float}>
     */
    public static function purchase(array $post): array
    {
        return array_map(static fn (array $line): array => ['product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'purchase_price' => $line['price']], self::lines($post, 'purchase_price'));
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{product_id: int, quantity: int, selling_price: float}>
     */
    public static function sales(array $post): array
    {
        return array_map(static fn (array $line): array => ['product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'selling_price' => $line['price']], self::lines($post, 'selling_price'));
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{product_id: int, quantity: int, price: float}>
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
                'quantity' => InputValidator::positiveInt('quantity', $line['quantity'] ?? ''),
                'price' => InputValidator::nonNegativeMoney($priceField, $line[$priceField] ?? ''),
            ];
        }

        return $items;
    }
}
