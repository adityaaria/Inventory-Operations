<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Repository\Contract\ProductUnitRepositoryInterface;
use App\Security\AuthContext;

/**
 * Barcode lookup (D-18, ADR-024): a scanned code resolves to one product unit (product_units.barcode, ADR-022), giving the
 * product, the unit and its factor to the base unit plus the active flags. Read-only. Scanning serves receiving, picking
 * and stock counts, which are warehouse tasks: Admin and WarehouseStaff may look up, Sales gets 403 (checked before the
 * input is parsed). Unknown codes are 404; malformed codes are 422.
 */
final class BarcodeService
{
    /** Same alphabet as stored barcodes (ProductUnitService::barcode()): 4–64 letters, digits or dashes. */
    private const PATTERN = '/^[A-Za-z0-9-]{4,64}$/D';

    public function __construct(
        private readonly ProductUnitRepositoryInterface $units,
        private readonly ProductRepositoryInterface $products,
    ) {
    }

    public function authorize(AuthContext $actor): void
    {
        if (!in_array($actor->role(), [User::ROLE_ADMIN, User::ROLE_WAREHOUSE_STAFF], true)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    /**
     * Validates scanner input: trimmed, 4–64 letters/digits/dashes; a 13-digit code must carry a valid EAN-13 check digit
     * (a misread digit is reported instead of silently looking up a wrong code).
     */
    public static function normalize(mixed $raw): string
    {
        $code = is_string($raw) ? trim($raw) : '';
        if (preg_match(self::PATTERN, $code) !== 1) {
            throw new ValidationException('A barcode is 4–64 letters, digits or dashes.');
        }
        if (preg_match('/^\d{13}$/D', $code) === 1 && ProductUnitService::ean13CheckDigit(substr($code, 0, 12)) !== (int) $code[12]) {
            throw new ValidationException('Barcode ' . $code . ' has an invalid EAN-13 check digit; scan it again.');
        }

        return $code;
    }

    /**
     * @return array{barcode: string, product: array{id: int, sku: string, name: string, is_active: bool, base_unit: string, tracks_expiry: bool},
     *               unit: array{code: string, factor_to_base: int, is_base: bool, is_active: bool}, usable: bool}
     */
    public function lookup(AuthContext $actor, mixed $raw): array
    {
        $this->authorize($actor);
        $code = self::normalize($raw);
        $unit = $this->units->findByBarcode($code) ?? throw new HttpException(404, 'Barcode not found.');
        $product = $this->products->findById($unit->productId()) ?? throw new HttpException(404, 'Barcode not found.');

        return [
            'barcode' => $code,
            'product' => [
                'id' => $product->id(),
                'sku' => $product->sku(),
                'name' => $product->name(),
                'is_active' => $product->isActive(),
                'base_unit' => $product->unit(),
                'tracks_expiry' => $product->tracksExpiry(),
            ],
            'unit' => [
                'code' => $unit->code(),
                'factor_to_base' => $unit->factor(),
                'is_base' => $unit->isBase(),
                'is_active' => $unit->isActive(),
            ],
            // A scan is only applied to documents when both the product and the unit are active.
            'usable' => $product->isActive() && $unit->isActive(),
        ];
    }
}
