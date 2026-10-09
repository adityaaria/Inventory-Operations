<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testFormatsRupiahWithDotThousandsAndCommaDecimals(): void
    {
        self::assertSame('Rp 20.000,00', Money::rupiah(20000.0));
        self::assertSame('Rp 1.234.567,89', Money::rupiah(1234567.89));
        self::assertSame('Rp 250,50', Money::rupiah(250.5));
        self::assertSame('Rp 0,00', Money::rupiah(0.0));
        self::assertSame('-Rp 1.500,00', Money::rupiah(-1500.0));
    }
}
