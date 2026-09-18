<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\JsonFileLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonFileLoggerTest extends TestCase
{
    public function testLogWritesStructuredJsonLineAndCreatesDirectory(): void
    {
        $path = sys_get_temp_dir() . '/inventory-json-logger-test/app.log';
        @unlink($path);
        @rmdir(dirname($path));

        $logger = new JsonFileLogger($path);
        $logger->log('info', 'Audit event stored', ['action' => 'auth.login_success']);

        $line = file($path, FILE_IGNORE_NEW_LINES)[0] ?? '';
        $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('info', $payload['level']);
        self::assertSame('Audit event stored', $payload['message']);
        self::assertSame('auth.login_success', $payload['context']['action']);
        self::assertArrayHasKey('timestamp', $payload);

        @unlink($path);
        @rmdir(dirname($path));
    }

    public function testErrorIncludesExceptionContext(): void
    {
        $path = sys_get_temp_dir() . '/inventory-json-logger-error-test/app.log';
        @unlink($path);
        @rmdir(dirname($path));

        $logger = new JsonFileLogger($path);
        $logger->error(new RuntimeException('Broken flow'), ['handler' => 'global_exception']);

        $line = file($path, FILE_IGNORE_NEW_LINES)[0] ?? '';
        $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('error', $payload['level']);
        self::assertSame('Broken flow', $payload['message']);
        self::assertSame('global_exception', $payload['context']['handler']);
        self::assertSame(RuntimeException::class, $payload['context']['exception']);

        @unlink($path);
        @rmdir(dirname($path));
    }
}
