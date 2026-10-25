<?php
declare(strict_types=1);
// Multiple units of measure (D-16, ADR-022). Back up first, then:
//   php scripts/migrate-multi-unit.php --dry-run   read-only report: products to convert and factors, products kept as
//                                                  base, rows to multiply, invariants and money totals before (no change);
//   php scripts/migrate-multi-unit.php             phase A DDL (database/migrations/20261012-multi-unit.sql, additive),
//                                                  then the data step in ONE transaction (App\Repository\MySql\MultiUnitMigration:
//                                                  convert, re-check every invariant, stock value within rounding and every
//                                                  PO/SO line total unchanged, record schema version 'multi-unit-1'), then
//                                                  phase B (20261012-multi-unit-finalize.sql: NOT NULL + CHECKs).
// Any failed check rolls the data step back, prints the report and exits 2 (schema extended, data untouched, version not
// recorded). Re-running after success is a no-op that re-verifies.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
$dryRun=in_array('--dry-run',$argv??[],true);
$sqlFile=static function (PDO $pdo,string $name): void {
    $path=dirname(__DIR__).'/database/migrations/'.$name;
    $sql=is_file($path)?file_get_contents($path):false;
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
};
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $migration=new App\Repository\MySql\MultiUnitMigration($pdo);
    if ($dryRun) {
        echo json_encode(['migration'=>'20261012-multi-unit','destructive'=>false]+$migration->dryRun(),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(0);
    }
    $sqlFile($pdo,'20261012-multi-unit.sql');
    $report=$migration->apply();
    if (($report['status']??'')==='conflict') {
        echo json_encode(['migration'=>'20261012-multi-unit','destructive'=>false,
            'message'=>'A check failed; the data step was rolled back and the version was not recorded. Inspect the counts, fix the cause and run again.']+$report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(2);
    }
    $sqlFile($pdo,'20261012-multi-unit-finalize.sql');
    $checks=(new App\Repository\MySql\MultiUnitChecks($pdo))->report();
    $constraints=$pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_name IN ('chk_purchase_order_items_unit_quantity','chk_sales_order_items_unit_quantity','chk_operation_items_unit','chk_purchase_order_items_received_whole_units','chk_sales_order_items_issued_whole_units')");
    if ($constraints===false || (int)$constraints->fetchColumn()!==5 || array_sum($checks)!==0) {
        echo json_encode(['status'=>'conflict','migration'=>'20261012-multi-unit','checks'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
        exit(2);
    }
    $units=$pdo->query('SELECT COUNT(*) FROM product_units');
    echo json_encode(['migration'=>'20261012-multi-unit','destructive'=>false]+$report+['unit_checks'=>$checks,'product_units'=>$units===false?0:(int)$units->fetchColumn()],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.','detail'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
