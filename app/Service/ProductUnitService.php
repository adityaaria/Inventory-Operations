<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductUnit;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\AuditLogRepositoryInterface;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\ProductUnitRepositoryInterface;
use App\Repository\Contract\TransactionManagerInterface;
use App\Security\AuthContext;
use App\Support\RequestOrigin;
use App\Validation\InputValidator;

/**
 * Admin management of product units (D-16, ADR-022): add, edit, deactivate, defaults, barcode and per-unit prices.
 * Authorization and validation live here (403 before any input is parsed). One transaction per change: lock the
 * product's unit rows, validate against them, write, append the `products.units` audit row.
 *
 * Immutability: once any PO/SO line or stock-operation line has snapshotted a unit code, that unit's code and factor can
 * no longer change (historical lines keep their own snapshot, but a silently changed factor would make the unit list
 * disagree with what people ordered). Deactivate it and add a new unit instead. The base unit is always factor 1, is
 * renamed only through the product's own Unit field (products.unit), never deactivated and has no own prices.
 */
final class ProductUnitService
{
    public const AUDIT_ACTION = 'products.units';
    public const MAX_FACTOR = 1000000;
    private const CODE_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]{0,29}$/uD';
    private const BARCODE_PATTERN = '/^[0-9A-Za-z-]{4,64}$/D';

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductUnitRepositoryInterface $units,
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

    /** @param array<string, mixed> $input unit_code, factor_to_base, barcode, purchase_price, selling_price, is_purchase_default, is_sales_default */
    public function add(AuthContext $actor, int $productId, array $input): ProductUnit
    {
        $this->assertCanManage($actor);
        $product = $this->product($productId);
        $code = self::code($input['unit_code'] ?? null);
        $factor = self::factor($input['factor_to_base'] ?? null);
        $values = ['code' => $code, 'factor' => $factor, 'is_base' => false, 'is_active' => true] + self::details($input);

        return $this->transactions->run(function () use ($actor, $product, $values): ProductUnit {
            $current = $this->units->lockForProduct($product->id());
            if (strcasecmp($values['code'], $product->unit()) === 0) {
                throw new ValidationException('"' . $values['code'] . '" is already the base unit of ' . $product->sku() . '.');
            }
            foreach ($current as $unit) {
                if (strcasecmp($unit->code(), $values['code']) === 0) {
                    throw new ValidationException('Unit "' . $values['code'] . '" already exists for ' . $product->sku() . '. Edit or reactivate it instead.');
                }
            }
            $this->ensureBaseRow($product, $current);
            $this->clearOtherDefaults($product->id(), $values, 0);
            $created = $this->units->create($product->id(), $values);
            $this->record($actor, $product->id(), 'add', $created, null);

            return $this->units->find($created->id()) ?? $created;
        });
    }

    /** @param array<string, mixed> $input same fields as add(), plus is_active ('1' keeps the unit active) */
    public function update(AuthContext $actor, int $productId, int $unitId, array $input): ProductUnit
    {
        $this->assertCanManage($actor);
        $product = $this->product($productId);
        $details = self::details($input);
        $active = ($input['is_active'] ?? '1') === '1' || ($input['is_active'] ?? null) === true;

        return $this->transactions->run(function () use ($actor, $product, $unitId, $input, $details, $active): ProductUnit {
            $current = $this->units->lockForProduct($product->id());
            $unit = null;
            foreach ($current as $candidate) {
                if ($candidate->id() === $unitId) {
                    $unit = $candidate;
                }
            }
            if ($unit === null) {
                throw new HttpException(404, 'Unit not found for this product.');
            }
            if ($unit->isBase()) {
                // Base unit: code = products.unit (rename through Edit Product), factor 1, always active, no own prices.
                if (!$active) {
                    throw new ValidationException('The base unit cannot be deactivated.');
                }
                $values = ['code' => $unit->code(), 'factor' => 1, 'is_active' => true, 'purchase_price' => null, 'selling_price' => null] + $details;
            } else {
                $code = array_key_exists('unit_code', $input) ? self::code($input['unit_code']) : $unit->code();
                $factor = array_key_exists('factor_to_base', $input) ? self::factor($input['factor_to_base']) : $unit->factor();
                if (($factor !== $unit->factor() || $code !== $unit->code()) && $this->units->isUsed($product->id(), $unit->code())) {
                    throw new ValidationException('Unit "' . $unit->code() . '" is already used on documents, so its code and factor cannot change. Deactivate it and add a new unit instead.');
                }
                foreach ($current as $other) {
                    if ($other->id() !== $unit->id() && strcasecmp($other->code(), $code) === 0) {
                        throw new ValidationException('Unit "' . $code . '" already exists for ' . $product->sku() . '.');
                    }
                }
                $values = ['code' => $code, 'factor' => $factor, 'is_active' => $active] + $details;
            }
            if (!$values['is_active']) {
                // An inactive unit cannot be a default; the purpose falls back to the base unit.
                $values['is_purchase_default'] = false;
                $values['is_sales_default'] = false;
            }
            $this->clearOtherDefaults($product->id(), $values, $unit->id());
            $updated = $this->units->update($unit->id(), $values);
            $this->record($actor, $product->id(), 'update', $updated, $unit);

            return $this->units->find($updated->id()) ?? $updated;
        });
    }

    private function product(int $productId): Product
    {
        return $this->products->findById($productId) ?? throw new HttpException(404, 'Product not found.');
    }

    /** Stores the base unit row when an older product has none yet (normally created with the product). @param list<ProductUnit> $current */
    private function ensureBaseRow(Product $product, array $current): void
    {
        foreach ($current as $unit) {
            if ($unit->isBase()) {
                return;
            }
        }
        $this->units->create($product->id(), ['code' => $product->unit(), 'factor' => 1, 'barcode' => null, 'purchase_price' => null, 'selling_price' => null, 'is_base' => true, 'is_purchase_default' => false, 'is_sales_default' => false, 'is_active' => true]);
    }

    /**
     * At most one default per purpose (UNIQUE key): the previous default loses its flag before this unit takes it.
     * @param array{is_purchase_default: bool, is_sales_default: bool} $values
     */
    private function clearOtherDefaults(int $productId, array $values, int $unitId): void
    {
        if ($values['is_purchase_default']) {
            $this->units->clearDefault($productId, 'purchase', $unitId);
        }
        if ($values['is_sales_default']) {
            $this->units->clearDefault($productId, 'sales', $unitId);
        }
    }

    private function record(AuthContext $actor, int $productId, string $change, ProductUnit $unit, ?ProductUnit $previous): void
    {
        $describe = static fn (ProductUnit $u): array => ['unit_code' => $u->code(), 'factor_to_base' => $u->factor(), 'barcode' => $u->barcode(), 'purchase_price' => $u->purchasePrice(), 'selling_price' => $u->sellingPrice(), 'is_purchase_default' => $u->isPurchaseDefault(), 'is_sales_default' => $u->isSalesDefault(), 'is_active' => $u->isActive()];
        $this->audit->append($actor->userId(), self::AUDIT_ACTION, 'products', $productId, 'success', RequestOrigin::none(), ['change' => $change, 'unit_id' => $unit->id()] + $describe($unit) + ($previous === null ? [] : ['previous' => $describe($previous)]));
    }

    public static function code(mixed $value): string
    {
        $code = is_string($value) ? trim($value) : '';
        if ($code === '' || mb_strlen($code) > 30 || preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new ValidationException('Unit code must be 1–30 letters, digits, spaces or . _ / - characters (e.g. karton, box, pcs).');
        }

        return $code;
    }

    public static function factor(mixed $value): int
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        if (preg_match('/^\d{1,7}$/D', $text) !== 1 || (int) $text < 2 || (int) $text > self::MAX_FACTOR) {
            throw new ValidationException('Factor to base must be a whole number from 2 to ' . number_format(self::MAX_FACTOR, 0, ',', '.') . ' (the base unit itself has factor 1).');
        }

        return (int) $text;
    }

    /** Barcode: optional, 4–64 letters/digits/dashes; a 13-digit code must carry a valid EAN-13 check digit. */
    public static function barcode(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return null;
        }
        if (preg_match(self::BARCODE_PATTERN, $text) !== 1) {
            throw new ValidationException('Barcode must be 4–64 letters, digits or dashes.');
        }
        if (preg_match('/^\d{13}$/D', $text) === 1 && self::ean13CheckDigit(substr($text, 0, 12)) !== (int) $text[12]) {
            throw new ValidationException('Barcode ' . $text . ' has an invalid EAN-13 check digit.');
        }

        return $text;
    }

    /** EAN-13 check digit of the first 12 digits. */
    public static function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;
        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /** @param array<string, mixed> $input @return array{barcode: ?string, purchase_price: ?float, selling_price: ?float, is_purchase_default: bool, is_sales_default: bool} */
    private static function details(array $input): array
    {
        return [
            'barcode' => self::barcode($input['barcode'] ?? null),
            'purchase_price' => self::price('purchase_price', $input['purchase_price'] ?? null),
            'selling_price' => self::price('selling_price', $input['selling_price'] ?? null),
            'is_purchase_default' => self::flag($input['is_purchase_default'] ?? null),
            'is_sales_default' => self::flag($input['is_sales_default'] ?? null),
        ];
    }

    private static function price(string $field, mixed $value): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            $value = number_format((float) $value, 2, '.', '');
        }
        if (!is_string($value) || preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', trim($value)) !== 1) {
            throw new ValidationException(ucfirst(str_replace('_', ' ', $field)) . ' per unit must be empty (base price × factor) or a non-negative amount with at most 2 decimals.');
        }

        return InputValidator::nonNegativeMoney($field, trim($value));
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === '1' || $value === 1 || $value === 'on';
    }
}
