<?php
declare(strict_types=1);
// Applies database/migrations/20261010-negotiated-prices.sql (D-13, ADR-019). Additive and idempotent: adds list_price
// (backfilled from the existing line price, which came from the master under ADR-015) and price_reason to PO/SO items,
// plus CHECKs. Stock quantities, ledger, statuses and line prices are never touched. Re-running is a no-op.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $path=dirname(__DIR__).'/database/migrations/20261010-negotiated-prices.sql';
    if (!is_file($path)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $sql=file_get_contents($path);
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
    $columns=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN ('purchase_order_items','sales_order_items') AND ((column_name='list_price' AND is_nullable='NO') OR (column_name='price_reason' AND is_nullable='YES'))");
    $columns->execute();
    if ((int)$columns->fetchColumn()!==4) { throw new \App\Exception\InfrastructureException('Columns missing after migration.'); }
    $result=['status'=>'ready','migration'=>'20261010-negotiated-prices','destructive'=>false];
    foreach (['purchase_order_items'=>'purchase_price','sales_order_items'=>'selling_price'] as $table=>$price) {
        $result[$table]=[
            'lines'=>(int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),
            'overridden'=>(int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$price} <> list_price")->fetchColumn(),
            'override_without_reason'=>(int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$price} <> list_price AND price_reason IS NULL")->fetchColumn(),
        ];
        if ($result[$table]['override_without_reason']!==0) { throw new \App\Exception\InfrastructureException('Override without reason after migration.'); }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.'],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
