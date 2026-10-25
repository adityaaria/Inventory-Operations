<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\ProductStock;
use App\Exception\ValidationException;
use App\Support\ReorderThreshold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** D-10 / ADR-016: one strict `<` rule against the effective (override else product) reorder point. */
final class ReorderThresholdTest extends TestCase
{
    public function testEffectiveThresholdUsesOverrideOnlyWhenSet(): void
    {
        self::assertSame(10, ReorderThreshold::effective(10, null));
        self::assertSame(25, ReorderThreshold::effective(10, 25));
        self::assertSame(0, ReorderThreshold::effective(10, 0), 'A zero override disables alerts for that warehouse.');
    }

    /** @return iterable<string, array{int, int, ?int, bool}> */
    public static function boundaryCases(): iterable
    {
        yield 'below product default' => [9, 10, null, true];
        yield 'equal to product default is not low' => [10, 10, null, false];
        yield 'above product default' => [11, 10, null, false];
        yield 'below override although above default' => [24, 10, 25, true];
        yield 'equal to override is not low' => [25, 10, 25, false];
        yield 'lower override clears a default alert' => [6, 10, 5, false];
        yield 'zero override never low' => [0, 10, 0, false];
    }

    #[DataProvider('boundaryCases')]
    public function testLowIsStrictlyBelowEffectiveThreshold(int $quantity, int $default, ?int $override, bool $low): void
    {
        self::assertSame($low, ReorderThreshold::isLow($quantity, $default, $override));
        self::assertSame($low, (new ProductStock(1, 2, 'SKU', 'Item', 'Main', $quantity, $default, $override))->isLowStock());
    }

    public function testProductStockExposesSourceOfThreshold(): void
    {
        $default = new ProductStock(1, 2, 'SKU', 'Item', 'Main', 3, 10);
        $override = new ProductStock(1, 2, 'SKU', 'Item', 'Main', 3, 10, 2);

        self::assertFalse($default->hasReorderPointOverride());
        self::assertNull($default->reorderPointOverride());
        self::assertSame(10, $default->effectiveReorderPoint());
        self::assertTrue($override->hasReorderPointOverride());
        self::assertSame(2, $override->reorderPointOverride());
        self::assertSame(2, $override->effectiveReorderPoint());
        self::assertSame(10, $override->reorderPoint(), 'reorderPoint() keeps the product default.');
    }

    public function testInactiveWarehouseOrProductIsNeverLow(): void
    {
        self::assertFalse((new ProductStock(1, 2, 'SKU', 'Item', 'Closed', 0, 10, null, false, true))->isLowStock());
        self::assertFalse((new ProductStock(1, 2, 'SKU', 'Item', 'Main', 0, 10, null, true, false))->isLowStock());
        self::assertTrue((new ProductStock(1, 2, 'SKU', 'Item', 'Main', 0, 10, null, true, true))->isLowStock());
    }

    public function testParseOverrideAcceptsEmptyAndDigitsOnly(): void
    {
        self::assertNull(ReorderThreshold::parseOverride(''));
        self::assertNull(ReorderThreshold::parseOverride('  '));
        self::assertSame(0, ReorderThreshold::parseOverride('0'));
        self::assertSame(42, ReorderThreshold::parseOverride(' 42 '));
        self::assertSame(ReorderThreshold::MAX, ReorderThreshold::parseOverride((string) ReorderThreshold::MAX));
        foreach (['-1', '2.5', 'abc', '1e3', (string) (ReorderThreshold::MAX + 1), '99999999999', ['7']] as $invalid) {
            try {
                ReorderThreshold::parseOverride($invalid);
                self::fail('Accepted ' . json_encode($invalid));
            } catch (ValidationException $error) {
                self::assertStringStartsWith('Warehouse reorder point must be a whole number', $error->getMessage());
            }
        }
    }

    public function testOverrideValidationAcceptsColumnRangeAndNull(): void
    {
        ReorderThreshold::assertValidOverride(null);
        ReorderThreshold::assertValidOverride(0);
        ReorderThreshold::assertValidOverride(ReorderThreshold::MAX);
        foreach ([-1, ReorderThreshold::MAX + 1] as $invalid) {
            try {
                ReorderThreshold::assertValidOverride($invalid);
                self::fail('Accepted ' . $invalid);
            } catch (ValidationException $error) {
                self::assertSame(422, $error->statusCode());
            }
        }
    }
}
