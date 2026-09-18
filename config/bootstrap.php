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
    http_response_code(500);
    $message = $config->bool('APP_DEBUG')
        ? htmlspecialchars($throwable->getMessage(), ENT_QUOTES, 'UTF-8')
        : 'Unexpected server error.';

    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Server Error</title>';
    echo '<body><h1>Server Error</h1><p>' . $message . '</p></body></html>';
});

return $config;
