<?php

declare(strict_types=1);

use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Service\LowStockService;
use App\Support\Config;
use App\Support\DatabaseFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @var Config $config */
$config = require dirname(__DIR__) . '/config/config.php';
$service = new LowStockService(new MySqlOperationalQueryRepository((new DatabaseFactory($config))->create()));
$rows = $service->rows();

if ($rows === []) {
    echo "No low-stock rows.\n";
    exit(0);
}

foreach ($rows as $row) {
    echo sprintf(
        "%s | %s | %s | quantity=%d | reorder=%d\n",
        (string) $row['sku'],
        (string) $row['product_name'],
        (string) $row['warehouse_name'],
        (int) $row['quantity'],
        (int) $row['reorder_point'],
    );
}
