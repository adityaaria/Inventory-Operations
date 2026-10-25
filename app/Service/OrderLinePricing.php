<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductUnit;
use App\Entity\User;
use App\Exception\ValidationException;
use App\Security\AuthContext;
use App\Support\Money;

/**
 * Negotiated line prices (D-13, ADR-019, user decision; partially supersedes ADR-015).
 *
 * The master price stays the default and is always snapshotted by the server as list_price; a client never supplies it.
 * - empty or equal to the current master price: the master price is used and the reason is dropped (null);
 * - otherwise a trimmed reason of 3–255 characters is required;
 * - prices are non-negative, at most 2 decimals and fit DECIMAL(14,2);
 * - PO: a different price must be greater than zero;
 * - SO: never below the product's current purchase price (no below-cost sale); Sales may discount at most
 *   SALES_MAX_DISCOUNT_PERCENT below master, Admin has no discount cap; above master is allowed for both.
 * Amounts are compared in integer cents so binary floats never decide a rule.
 *
 * Units (D-16, ADR-022): every price is per the line's entered unit. The master price of a unit is its own unit price,
 * else base price × factor; the cost floor per unit is the base purchase price × factor. Without a unit the line is in
 * the base unit (factor 1), exactly as before.
 */
final class OrderLinePricing
{
    public const DEFAULT_SALES_MAX_DISCOUNT_PERCENT = 10;
    public const REASON_MIN = 3;
    public const REASON_MAX = 255;
    private const PRICE_PATTERN = '/^\d{1,12}(?:\.\d{1,2})?$/D';

    public function __construct(private readonly int $salesMaxDiscountPercent = self::DEFAULT_SALES_MAX_DISCOUNT_PERCENT)
    {
        if ($salesMaxDiscountPercent < 0 || $salesMaxDiscountPercent > 100) {
            throw new \LogicException('SALES_MAX_DISCOUNT_PERCENT must be between 0 and 100.');
        }
    }

    public function salesMaxDiscountPercent(): int
    {
        return $this->salesMaxDiscountPercent;
    }

    /**
     * @return array{price: float, list_price: float, price_reason: string|null}
     */
    public function purchaseLine(Product $product, mixed $submittedPrice, mixed $reason, ?ProductUnit $unit = null): array
    {
        $unit ??= ProductUnit::baseFor($product);
        $list = self::cents($unit->unitPurchasePrice($product));
        $price = $this->submittedCents($product, $submittedPrice, 'Purchase price');
        if ($price === null || $price === $list) {
            return self::line($list, $list, null);
        }
        if ($price === 0) {
            throw new ValidationException('Purchase price for ' . $product->sku() . ' must be greater than zero when it differs from the master price.');
        }

        return self::line($price, $list, $this->reason($product, $reason));
    }

    /**
     * $listCents (D-19, ADR-025): the resolved list price per unit when it does not come from the master — a customer
     * price list tier or a quoted price being converted. The reason requirement and the Sales cap are then measured
     * against it, and the cost floor also applies to the list price itself (a list maintained before a cost increase
     * never sells below cost). Without it the master unit price is the list price, exactly as before.
     *
     * @return array{price: float, list_price: float, price_reason: string|null}
     */
    public function salesLine(AuthContext $actor, Product $product, mixed $submittedPrice, mixed $reason, ?ProductUnit $unit = null, ?int $listCents = null): array
    {
        $unit ??= ProductUnit::baseFor($product);
        $list = $listCents ?? self::cents($unit->unitSellingPrice($product));
        $listLabel = $listCents === null ? 'master price' : 'list price';
        $price = $this->submittedCents($product, $submittedPrice, 'Selling price');
        if ($price === null || $price === $list) {
            if ($listCents !== null) {
                $this->assertNotBelowCost($product, $unit, $list);
            }

            return self::line($list, $list, null);
        }
        $this->assertNotBelowCost($product, $unit, $price);
        // Integer comparison of price ≥ list × (100 − cap) / 100, so no rounding decides the cap.
        if ($actor->role() !== User::ROLE_ADMIN && $price * 100 < $list * (100 - $this->salesMaxDiscountPercent)) {
            throw new ValidationException(sprintf(
                'Sales may discount at most %d%% below the %s; the lowest selling price for %s is %s. Ask an Admin for a larger discount.',
                $this->salesMaxDiscountPercent,
                $listLabel,
                $product->sku(),
                Money::rupiah(intdiv($list * (100 - $this->salesMaxDiscountPercent) + 99, 100) / 100),
            ));
        }

        return self::line($price, $list, $this->reason($product, $reason, $listLabel));
    }

    private function assertNotBelowCost(Product $product, ProductUnit $unit, int $price): void
    {
        $cost = $unit->costCents($product);
        if ($price < $cost) {
            throw new ValidationException(sprintf(
                'Selling price for %s (%s per %s) is below its current purchase price (%s per %s); below-cost sales are not allowed.',
                $product->sku(),
                Money::rupiah($price / 100),
                $unit->code(),
                Money::rupiah($cost / 100),
                $unit->code(),
            ));
        }
    }

    /** Null when no price was submitted (use the master price). */
    private function submittedCents(Product $product, mixed $value, string $label): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value) && is_finite($value) && abs($value * 100 - round($value * 100)) < 1e-6) {
            $value = number_format($value, 2, '.', '');
        }
        if (!is_string($value) || preg_match(self::PRICE_PATTERN, trim($value)) !== 1) {
            throw new ValidationException($label . ' for ' . $product->sku() . ' must be a non-negative amount with at most 2 decimals (up to 999999999999.99).');
        }
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function reason(Product $product, mixed $reason, string $listLabel = 'master price'): string
    {
        $text = is_string($reason) ? trim($reason) : '';
        $length = mb_strlen($text);
        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw new ValidationException('A reason of ' . self::REASON_MIN . '–' . self::REASON_MAX . ' characters is required when the price of ' . $product->sku() . ' differs from the ' . $listLabel . '.');
        }

        return $text;
    }

    /** @return array{price: float, list_price: float, price_reason: string|null} */
    private static function line(int $price, int $list, ?string $reason): array
    {
        return ['price' => $price / 100, 'list_price' => $list / 100, 'price_reason' => $reason];
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
