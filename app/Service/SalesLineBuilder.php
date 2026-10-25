<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Exception\ValidationException;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Security\AuthContext;
use App\Support\UnitSet;

/**
 * Validates and prices sales lines for SO drafts and quotations (shared since D-19, ADR-025).
 *
 * Per line: active product, no duplicate product, positive quantity in the line unit (ADR-022), then the list price is
 * resolved (customer price list tier or master, PriceResolver) and the submitted price is checked against it
 * (ADR-019: reason, Sales cap, cost floor; OrderLinePricing). Without a resolver every line is master-priced as before.
 *
 * @phpstan-type SalesLine array{product_id: int, quantity: int, unit_code: string, unit_factor: int, unit_quantity: int, selling_price: float, list_price: float, price_reason: string|null, price_source: string, price_list_id: int|null}
 */
final class SalesLineBuilder
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductUnitCatalog $units,
        private readonly OrderLinePricing $pricing,
        private readonly ?PriceResolver $prices = null,
    ) {
    }

    /**
     * @param list<array{product_id: int, quantity: int, unit_code?: string|null, price?: mixed, price_reason?: mixed}> $items
     * @return list<SalesLine>
     */
    public function lines(AuthContext $actor, int $customerId, array $items): array
    {
        if ($items === []) {
            throw new ValidationException('At least one item is required.');
        }
        $seenProducts = [];
        $lines = [];
        foreach ($items as $item) {
            $product = $this->products->findById($item['product_id']);
            if (!$product instanceof Product || !$product->isActive()) {
                throw new ValidationException('Active product is required.');
            }
            if (isset($seenProducts[$item['product_id']])) {
                throw new ValidationException('Duplicate product lines are not allowed.');
            }
            if ($item['quantity'] <= 0) {
                throw new ValidationException('Quantity must be positive.');
            }
            $seenProducts[$item['product_id']] = true;
            $unit = $this->units->forProduct($product)->resolve($item['unit_code'] ?? null, $product->sku());
            $resolved = $this->prices?->resolve($customerId, $product, $unit, $item['quantity']);
            $fromList = $resolved !== null && $resolved['source'] === PriceResolver::SOURCE_PRICE_LIST;
            $priced = $this->pricing->salesLine($actor, $product, $item['price'] ?? null, $item['price_reason'] ?? null, $unit, $fromList ? $resolved['price_cents'] : null);
            $lines[] = [
                'product_id' => $item['product_id'],
                'quantity' => UnitSet::toBase($item['quantity'], $unit->factor()),
                'unit_code' => $unit->code(),
                'unit_factor' => $unit->factor(),
                'unit_quantity' => $item['quantity'],
                'selling_price' => $priced['price'],
                'list_price' => $priced['list_price'],
                'price_reason' => $priced['price_reason'],
                'price_source' => $fromList ? PriceResolver::SOURCE_PRICE_LIST : PriceResolver::SOURCE_MASTER,
                'price_list_id' => $fromList ? $resolved['price_list_id'] : null,
            ];
        }

        return $lines;
    }

    /**
     * Re-validates quoted lines for conversion to an SO (D-19, ADR-025): the product and the snapshotted unit are still
     * active with the same factor, and the quoted price passes the same pricing rules against the quoted list price
     * (cost floor on the current purchase price, Sales cap, reason). Prices, sources and reasons carry over.
     *
     * @param list<array{id: int, product_id: int, unit_code: string, unit_factor: int, unit_quantity: int, unit_price: string, list_price: string, price_reason: ?string, price_source: string, price_list_id: ?int}> $quoted
     * @return list<array{product_id: int, quantity: int, unit_code: string, unit_factor: int, unit_quantity: int, selling_price: float, list_price: float, price_reason: string|null, price_source: string, price_list_id: int|null, quotation_item_id: int}>
     */
    public function quotedLines(AuthContext $actor, array $quoted): array
    {
        $lines = [];
        foreach ($quoted as $line) {
            $product = $this->products->findById($line['product_id']);
            if (!$product instanceof Product || !$product->isActive()) {
                throw new ValidationException('Product #' . $line['product_id'] . ' of the quotation is no longer active; the quotation cannot be converted.');
            }
            $unit = $this->units->forProduct($product)->find($line['unit_code']);
            if ($unit === null || !$unit->isActive() || $unit->factor() !== $line['unit_factor']) {
                throw new ValidationException('Unit "' . $line['unit_code'] . '" of ' . $product->sku() . ' changed since the quotation; the quotation cannot be converted.');
            }
            $priced = $this->pricing->salesLine($actor, $product, $line['unit_price'], $line['price_reason'], $unit, \App\Support\TaxSettings::cents($line['list_price']));
            $lines[] = [
                'product_id' => $line['product_id'],
                'quantity' => UnitSet::toBase($line['unit_quantity'], $unit->factor()),
                'unit_code' => $unit->code(),
                'unit_factor' => $unit->factor(),
                'unit_quantity' => $line['unit_quantity'],
                'selling_price' => $priced['price'],
                'list_price' => $priced['list_price'],
                'price_reason' => $priced['price_reason'],
                'price_source' => $line['price_source'],
                'price_list_id' => $line['price_list_id'],
                'quotation_item_id' => $line['id'],
            ];
        }

        return $lines;
    }
}
