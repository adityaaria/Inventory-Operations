<?php
declare(strict_types=1);
// Deliberately independent of session initialization and browser controllers.
require_once dirname(__DIR__) . '/vendor/autoload.php';
$ready = false;
try {
    $config = App\Support\Config::fromEnvironment();
    $pdo = (new App\Support\DatabaseFactory($config))->create();
    (new App\Repository\MySql\MySqlOperationalHealthRepository($pdo))->assertReady();
    foreach (['log', 'sessions'] as $directory) {
        $path = dirname(__DIR__) . '/var/' . $directory;
        if (!is_dir($path) || !is_writable($path)) { throw new \App\Exception\InfrastructureException('Storage unavailable.'); }
    }
    $ready = true;
} catch (Throwable $error) {
    // No DSN, credentials, schema names or exception detail in health output.
}
if (PHP_SAPI !== 'cli') {
    http_response_code($ready ? 200 : 503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
}
echo json_encode(['status' => $ready ? 'ready' : 'unavailable'], JSON_THROW_ON_ERROR) . PHP_EOL;
if (PHP_SAPI === 'cli') { exit($ready ? 0 : 1); }
