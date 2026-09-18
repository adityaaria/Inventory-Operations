<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Config;
use PHPUnit\Framework\TestCase;

final class BootstrapSmokeTest extends TestCase
{
    public function testConfigReturnsTypedValues(): void
    {
        $config = new Config([
            'APP_NAME' => 'Inventory Test',
            'APP_PORT' => '8080',
            'APP_DEBUG' => 'true',
        ]);

        self::assertSame('Inventory Test', $config->string('APP_NAME'));
        self::assertSame(8080, $config->int('APP_PORT'));
        self::assertTrue($config->bool('APP_DEBUG'));
    }

    public function testConfigFailsForMissingKey(): void
    {
        $config = new Config([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing configuration value: APP_NAME');

        $config->string('APP_NAME');
    }
}
