<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ApplicationBootstrap;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ApplicationBootstrapCoverageTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testGlobalHandlerRendersSafeHtmlErrorPageForBrowserRequests(): void
    {
        $root = sys_get_temp_dir() . '/ioms-boot-html-' . bin2hex(random_bytes(4));
        mkdir($root . '/var/log', 0700, true);
        $_ENV['APP_DEBUG'] = 'false';

        try {
            ApplicationBootstrap::boot($root);
            $handler = set_exception_handler(null);
            self::assertIsCallable($handler);

            $_SERVER['REQUEST_URI'] = '/purchase-orders/5';
            ob_start();
            $handler(new \RuntimeException('SQLSTATE secret detail'));
            $body = (string) ob_get_clean();

            self::assertSame(500, http_response_code());
            self::assertStringContainsString('<h1>Server Error</h1>', $body);
            self::assertStringContainsString('Unexpected server error.', $body);
            self::assertStringNotContainsString('SQLSTATE secret detail', $body);
            $log = (string) file_get_contents($root . '/var/log/app.log');
            self::assertStringContainsString('"handler":"global_exception"', $log);
            self::assertStringContainsString('"exception":"RuntimeException"', $log);
            self::assertStringNotContainsString('SQLSTATE secret detail', $log);
        } finally {
            array_map('unlink', glob($root . '/var/log/*') ?: []);
            rmdir($root . '/var/log');
            rmdir($root . '/var');
            rmdir($root);
        }
    }

    #[RunInSeparateProcess]
    public function testGlobalHandlerShowsMessageForBrowserRequestsInDebugMode(): void
    {
        $root = sys_get_temp_dir() . '/ioms-boot-dbg-' . bin2hex(random_bytes(4));
        mkdir($root . '/var/log', 0700, true);
        $_ENV['APP_DEBUG'] = 'true';
        unset($_SERVER['REQUEST_URI']);

        try {
            ApplicationBootstrap::boot($root);
            self::assertSame('1', ini_get('display_errors'));
            $handler = set_exception_handler(null);
            self::assertIsCallable($handler);

            ob_start();
            $handler(new \RuntimeException('debug <detail>'));
            $body = (string) ob_get_clean();

            self::assertStringContainsString('debug &lt;detail&gt;', $body);
        } finally {
            array_map('unlink', glob($root . '/var/log/*') ?: []);
            rmdir($root . '/var/log');
            rmdir($root . '/var');
            rmdir($root);
        }
    }
}
