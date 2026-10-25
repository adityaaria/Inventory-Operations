<?php
declare(strict_types=1);
// Batch/lot tracking, expiry and FEFO (D-17, ADR-023). Back up first, then:
//   php scripts/migrate-batch-expiry.php --dry-run   read-only report of
//                                                    what the data step would do (pairs/units/value converted to LEGACY
//                                                    lots, categories and products that will track expiry, in-transit
//                                                    transfers without lots, unsafe conditions). Requires the DDL; when
//                                                    the tables are missing it reports that and changes nothing.
//   php scripts/migrate-batch-expiry.php             DDL (database/migrations/20261012-batch-expiry.sql, additive and
//                                                    idempotent), then the data step in ONE transaction
//                                                    (App\Repository\MySql\BatchExpiryMigration): LEGACY lots, watermark,
//                                                    tracks_expiry, every invariant re-checked, schema version recorded.
// Any failed check rolls the data step back, prints the report and exits 2. Re-running after success is a no-op that
// re-verifies.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
$dryRun=in_array('--dry-run',$argv??[],true);
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $tables=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('stock_lots','stock_lot_watermark','inventory_operation_lots')");
    $hasSchema=$tables!==false && (int)$tables->fetchColumn()===3;
    if ($dryRun) {
        if (!$hasSchema) {
            echo json_encode(['migration'=>'20261012-batch-expiry','mode'=>'dry-run','destructive'=>false,'schema'=>'missing',
                'pairs_to_convert'=>(int)$pdo->query('SELECT COUNT(*) FROM product_stocks WHERE quantity > 0')->fetchColumn(),
                'units_to_convert'=>(int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM product_stocks WHERE quantity > 0')->fetchColumn(),
                'message'=>'The lot tables do not exist yet; the run without --dry-run adds them (additive DDL) before the data step.'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
            exit(0);
        }
        echo json_encode(['migration'=>'20261012-batch-expiry','destructive'=>false]+(new App\Repository\MySql\BatchExpiryMigration($pdo))->dryRun(),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(0);
    }
    $path=dirname(__DIR__).'/database/migrations/20261012-batch-expiry.sql';
    $sql=is_file($path)?file_get_contents($path):false;
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
    $migration=new App\Repository\MySql\BatchExpiryMigration($pdo);
    $report=$migration->apply();
    $statistics=(new App\Repository\MySql\BatchLotChecks($pdo))->statistics();
    echo json_encode(['migration'=>'20261012-batch-expiry','destructive'=>false]+$report+['statistics'=>$statistics],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(($report['status']??'')==='ready'?0:2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.','detail'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
