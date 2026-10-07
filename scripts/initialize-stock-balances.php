<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';
$pdo = (new App\Support\DatabaseFactory($config))->create();
$service = new App\Service\StockService(
    new App\Repository\MySql\MySqlStockRepository($pdo),
    new App\Repository\MySql\MySqlStockLedgerRepository($pdo),
    new App\Repository\MySql\MySqlAuditLogRepository($pdo),
    new App\Repository\MySql\MySqlStockCatalogRepository($pdo),
    new App\Repository\MySql\MySqlTransactionManager($pdo),
);
$service->initializeCatalog();
echo "Missing product/warehouse pairs initialized at zero; existing quantities and ledger unchanged.\n";
