<?php

declare(strict_types=1);

namespace App\Validation;

use App\Entity\StockLot;
use App\Exception\ValidationException;
use App\Support\BusinessDate;

/** Lot code and expiry input rules (D-17, ADR-023). */
final class LotInput
{
    public const MAX_CODE = 60;

    public static function code(mixed $value): string
    {
        $code = is_string($value) ? trim($value) : '';
        if ($code === '' || strlen($code) > self::MAX_CODE || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $code) !== 1) {
            throw new ValidationException('Lot code must be 1-' . self::MAX_CODE . ' letters, digits, dots, dashes, slashes or underscores.');
        }
        if (strcasecmp($code, StockLot::LEGACY) === 0) {
            throw new ValidationException('Lot code LEGACY is reserved for stock migrated without lots.');
        }

        return $code;
    }

    public static function expiry(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return null;
        }
        if (!BusinessDate::isValidDate($text)) {
            throw new ValidationException('Expiry date must be a valid date (YYYY-MM-DD).');
        }

        return $text;
    }

    /** Per-base unit cost in whole cents, rounded half up: line price per entered unit ÷ unit factor. */
    public static function costPerBase(float $unitPrice, int $factor): string
    {
        $cents = (int) round($unitPrice * 100);
        $perBase = intdiv(2 * $cents + max(1, $factor), 2 * max(1, $factor));

        return sprintf('%d.%02d', intdiv($perBase, 100), $perBase % 100);
    }

    /**
     * Parses the splits of one receipt line from form arrays (lot_code[], expiry_date[], lot_quantity[]). Rows that are
     * completely empty are skipped. A single row without quantity takes the whole line quantity.
     *
     * @return list<array{lot_code: string, expiry_date: string|null, quantity: int}>
     */
    public static function splitsFromForm(mixed $codes, mixed $expiries, mixed $quantities, int $lineQuantity): array
    {
        $codes = is_array($codes) ? array_values($codes) : [];
        $expiries = is_array($expiries) ? array_values($expiries) : [];
        $quantities = is_array($quantities) ? array_values($quantities) : [];
        if (count($codes) > 50) {
            throw new ValidationException('Use at most 50 lots per line.');
        }
        $rows = [];
        foreach ($codes as $index => $code) {
            $expiry = $expiries[$index] ?? '';
            $quantity = $quantities[$index] ?? '';
            if (trim((string) (is_scalar($code) ? $code : '')) === '' && trim((string) (is_scalar($expiry) ? $expiry : '')) === '' && trim((string) (is_scalar($quantity) ? $quantity : '')) === '') {
                continue;
            }
            $rows[] = [$code, $expiry, $quantity];
        }
        $splits = [];
        foreach ($rows as [$code, $expiry, $quantity]) {
            $text = is_scalar($quantity) ? trim((string) $quantity) : '';
            if ($text === '' && count($rows) === 1) {
                $units = $lineQuantity;
            } elseif (preg_match('/^[1-9]\d{0,9}$/D', $text) === 1) {
                $units = (int) $text;
            } else {
                throw new ValidationException('Enter a whole quantity above zero for every lot.');
            }
            $splits[] = ['lot_code' => self::code($code), 'expiry_date' => self::expiry($expiry), 'quantity' => $units];
        }

        return $splits;
    }
}
