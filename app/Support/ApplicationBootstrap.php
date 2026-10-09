<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\ErrorResponder;

final class ApplicationBootstrap
{
    /** Process-wide setup shared by the front controller and migration scripts. */
    public static function boot(string $root): Config
    {
        error_reporting(E_ALL);

        $config = Config::fromEnvironment();
        $logger = new JsonFileLogger($root . '/var/log/app.log');

        ini_set('display_errors', $config->bool('APP_DEBUG') ? '1' : '0');

        set_exception_handler(static function (\Throwable $throwable) use ($config, $logger): void {
            $logger->error($throwable, ['handler' => 'global_exception']);
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $response = str_starts_with($uri, '/api/')
                ? ErrorResponder::api('Unexpected server error.', 500)
                : ErrorResponder::browser($throwable, $config->bool('APP_DEBUG'));
            $response->send();
        });

        return $config;
    }
}
