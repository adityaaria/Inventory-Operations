<?php

declare(strict_types=1);

use App\Support\Config;
use App\Support\JsonFileLogger;

require_once dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(E_ALL);

/** @var Config $config */
$config = require __DIR__ . '/config.php';
$logger = new JsonFileLogger(dirname(__DIR__) . '/var/log/app.log');

ini_set('display_errors', $config->bool('APP_DEBUG') ? '1' : '0');

set_exception_handler(static function (Throwable $throwable) use ($config, $logger): void {
    $logger->error($throwable, ['handler' => 'global_exception']);
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $response = str_starts_with($uri, '/api/')
        ? \App\Http\ErrorResponder::api('Unexpected server error.', 500)
        : \App\Http\ErrorResponder::browser($throwable, $config->bool('APP_DEBUG'));
    $response->send();
});

return $config;
