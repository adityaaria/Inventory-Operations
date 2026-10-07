<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';
$pdo = (new App\Support\DatabaseFactory($config))->create();
$result = (new App\Repository\MySql\MySqlOperationalHealthRepository($pdo))->fingerprint();
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
