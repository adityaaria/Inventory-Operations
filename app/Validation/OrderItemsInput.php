<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;

/** Parses the primary order line plus up to 99 supplementary `items[n][...]` lines; services reject duplicates. */
final class OrderItemsInput
{
    public const MAX_ITEMS = 100;

    /**
     * Prices are not read: services take each line's price from the product master.
     *
     * @param array<string, mixed> $post
     * @return list<array{product_id: int, quantity: int}>
     */
    public static function purchase(array $post): array
    {
        return self::lines($post);
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{product_id: int, quantity: int}>
     */
    public static function sales(array $post): array
    {
        return self::lines($post);
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{product_id: int, quantity: int}>
     */
    private static function lines(array $post): array
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
            ];
        }

        return $items;
    }
}
