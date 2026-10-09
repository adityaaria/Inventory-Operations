<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ApplicationBootstrap;
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

    public function testConfigFromEnvironmentPrefersEnvVariablesAndFallsBackToDefaults(): void
    {
        $originalEnv = $_ENV;
        $originalIdle = getenv('SESSION_IDLE_SECONDS');
        $_ENV['APP_NAME'] = 'From Environment';
        unset($_ENV['SESSION_IDLE_SECONDS']);
        putenv('SESSION_IDLE_SECONDS');

        try {
            $config = Config::fromEnvironment();

            self::assertSame('From Environment', $config->string('APP_NAME'));
            self::assertSame(1800, $config->int('SESSION_IDLE_SECONDS'));
        } finally {
            $_ENV = $originalEnv;
            if ($originalIdle !== false) {
                putenv('SESSION_IDLE_SECONDS=' . $originalIdle);
            }
        }
    }

    public function testBootInstallsAGlobalHandlerThatLogsAndHidesApiErrors(): void
    {
        $root = sys_get_temp_dir() . '/ioms-boot-' . bin2hex(random_bytes(4));
        mkdir($root . '/var/log', 0700, true);
        $originalUri = $_SERVER['REQUEST_URI'] ?? null;
        $originalDisplay = ini_get('display_errors');
        $originalEnv = $_ENV;
        $_ENV['APP_DEBUG'] = 'false';

        try {
            self::assertInstanceOf(Config::class, ApplicationBootstrap::boot($root));
            $handler = set_exception_handler(null);
            self::assertIsCallable($handler);
            self::assertSame('0', ini_get('display_errors'));

            $_SERVER['REQUEST_URI'] = '/api/products/SKU-1/availability';
            ob_start();
            $handler(new \RuntimeException('database password leaked?'));
            $body = (string) ob_get_clean();

            self::assertSame('{"error":"Unexpected server error."}', $body);
            self::assertStringContainsString('"handler":"global_exception"', (string) file_get_contents($root . '/var/log/app.log'));
        } finally {
            $_ENV = $originalEnv;
            $_SERVER['REQUEST_URI'] = $originalUri;
            if ($originalUri === null) {
                unset($_SERVER['REQUEST_URI']);
            }
            ini_set('display_errors', (string) $originalDisplay);
            array_map('unlink', glob($root . '/var/log/*') ?: []);
            rmdir($root . '/var/log');
            rmdir($root . '/var');
            rmdir($root);
        }
    }
}
