<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testReadsStringIntAndBoolValues(): void
    {
        $config = new Config([
            'APP_NAME' => 'Inventory',
            'APP_PORT' => '8080',
            'APP_DEBUG' => 'true',
        ]);

        self::assertSame('Inventory', $config->string('APP_NAME'));
        self::assertSame(8080, $config->int('APP_PORT'));
        self::assertTrue($config->bool('APP_DEBUG'));
    }

    /** @dataProvider boolValueProvider */
    public function testBoolAcceptsCommonTruthyAndFalsyStrings(string $raw, bool $expected): void
    {
        $config = new Config(['FLAG' => $raw]);

        self::assertSame($expected, $config->bool('FLAG'));
    }

    /** @return list<array{0: string, 1: bool}> */
    public static function boolValueProvider(): array
    {
        return [
            ['1', true],
            ['TRUE', true],
            ['yes', true],
            ['On', true],
            ['0', false],
            ['false', false],
            ['no', false],
            ['off', false],
        ];
    }

    public function testMissingKeyThrows(): void
    {
        $config = new Config([]);

        $this->expectException(InvalidArgumentException::class);

        $config->string('MISSING');
    }

    public function testEmptyStringValueIsTreatedAsMissing(): void
    {
        $config = new Config(['APP_NAME' => '']);

        $this->expectException(InvalidArgumentException::class);

        $config->string('APP_NAME');
    }

    public function testNonScalarValueRejected(): void
    {
        $config = new Config(['APP_NAME' => ['not', 'scalar']]);

        $this->expectException(InvalidArgumentException::class);

        $config->string('APP_NAME');
    }

    public function testNonNumericIntValueRejected(): void
    {
        $config = new Config(['APP_PORT' => 'not-a-number']);

        $this->expectException(InvalidArgumentException::class);

        $config->int('APP_PORT');
    }

    public function testInvalidBoolValueRejected(): void
    {
        $config = new Config(['APP_DEBUG' => 'maybe']);

        $this->expectException(InvalidArgumentException::class);

        $config->bool('APP_DEBUG');
    }
}
