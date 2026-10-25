<?php

declare(strict_types=1);

use App\Repository\MySql\MySqlOperationalQueryRepository;
use App\Service\LowStockService;
use App\Support\Config;
use App\Support\DatabaseFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnvironment();
$service = new LowStockService(new MySqlOperationalQueryRepository((new DatabaseFactory($config))->create()));
$rows = $service->rows();

if ($rows === []) {
    echo "No low-stock rows.\n";
    exit(0);
}

// Low = available (quantity − reserved for Approved sales orders) < effective reorder point (D-10/D-12). The first
// fields keep their earlier format; reserved/available are appended.
foreach ($rows as $row) {
    echo sprintf(
        "%s | %s | %s | quantity=%d | reorder=%d%s | reserved=%d | available=%d\n",
        (string) $row['sku'],
        (string) $row['product_name'],
        (string) $row['warehouse_name'],
        (int) $row['quantity'],
        (int) $row['reorder_point'],
        ($row['reorder_point_override'] ?? null) !== null ? ' (warehouse override)' : '',
        (int) ($row['reserved_quantity'] ?? 0),
        (int) ($row['available_quantity'] ?? $row['quantity']),
    );
}
