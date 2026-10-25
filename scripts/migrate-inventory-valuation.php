<?php
declare(strict_types=1);
// Inventory valuation FIFO per batch (D-20, ADR-026). Back up first, then:
//   php scripts/migrate-inventory-valuation.php --dry-run   read-only: rows to backfill per movement type with their cost
//                                                           (cents), the lot value now, the opening value of the LEGACY
//                                                           lots and the projected roll-forward difference (must be 0).
//   php scripts/migrate-inventory-valuation.php             phase A (database/migrations/20261012-inventory-valuation.sql,
//                                                           additive columns + index), then the backfill in ONE
//                                                           transaction (App\Repository\MySql\InventoryValuationMigration:
//                                                           every ledger row with a lot gets its lot's unit cost and
//                                                           cost_amount; every invariant re-checked), then phase B
//                                                           (20261012-inventory-valuation-finalize.sql: the CHECK), and
//                                                           records schema version inventory-valuation-1.
// Rows before the ADR-023 watermark have no lot and keep no cost. Any failed check rolls the backfill back and exits 2
// (columns added, data untouched, version not recorded). Re-running after success is a no-op that re-verifies.
// Run it right before activating the new application image: from phase B on, the old image cannot write ledger rows.
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
    $migration=new App\Repository\MySql\InventoryValuationMigration($pdo);
    if ($dryRun) {
        echo json_encode(['migration'=>'20261012-inventory-valuation','destructive'=>false]+$migration->dryRun(),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(0);
    }
    $alreadyApplied=$migration->applied();
    $sqlFile($pdo,'20261012-inventory-valuation.sql');
    $report=$migration->apply();
    if (($report['status']??'')!=='ready') {
        echo json_encode(['migration'=>'20261012-inventory-valuation','destructive'=>false,
            'message'=>'A check failed; the backfill was rolled back and the version was not recorded. Inspect the counts, fix the cause and run again.']+$report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(2);
    }
    $sqlFile($pdo,'20261012-inventory-valuation-finalize.sql');
    $constraint=$pdo->query("SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND constraint_name='chk_stock_ledger_cost'");
    $checks=$migration->checks();
    $ready=$constraint!==false && (int)$constraint->fetchColumn()===1 && array_sum($checks)===0;
    if ($ready && !$alreadyApplied) {
        $pdo->prepare('INSERT INTO schema_versions (version, description) VALUES (:version, :description) ON DUPLICATE KEY UPDATE description = VALUES(description)')
            ->execute(['version'=>App\Repository\MySql\InventoryValuationMigration::VERSION,'description'=>'Inventory valuation FIFO per batch: ledger unit_cost and cost_amount (D-20, ADR-026)']);
    }
    echo json_encode(['migration'=>'20261012-inventory-valuation','destructive'=>false,'status'=>$ready?'ready':'failed','already_applied'=>$alreadyApplied]+$report+['final_checks'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit($ready?0:2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.','detail'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
