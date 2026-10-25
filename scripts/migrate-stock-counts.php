<?php
declare(strict_types=1);
// Barcode lookup and stock count sessions (D-18, ADR-024). Back up first, then:
//   php scripts/migrate-stock-counts.php --dry-run   read-only: which tables are missing, whether the lot source CHECK
//                                                    already allows STOCK_COUNT, barcodes available for scanning.
//   php scripts/migrate-stock-counts.php             applies database/migrations/20261012-stock-counts.sql (additive and
//                                                    idempotent: four new tables, the lot source CHECK widened by one
//                                                    value), verifies tables, constraints and the count invariants, then
//                                                    records schema version stock-count-1. No existing row is changed.
// Re-running after success is a no-op that re-verifies. Exit 0 ready, 2 verification failed, 1 error.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
$dryRun=in_array('--dry-run',$argv??[],true);
const COUNT_TABLES=['stock_counts','stock_count_products','stock_count_counters','stock_count_lines'];
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $state=static function () use ($pdo): array {
        $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('".implode("','",COUNT_TABLES)."')");
        $present=$tables===false?[]:array_map('strval',$tables->fetchAll(PDO::FETCH_COLUMN));
        $check=$pdo->query("SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND constraint_name='chk_stock_lots_source' AND check_clause LIKE '%STOCK_COUNT%'");
        $version=$pdo->query("SELECT COUNT(*) FROM schema_versions WHERE version='stock-count-1'");
        return [
            'tables_missing'=>array_values(array_diff(COUNT_TABLES,$present)),
            'lot_source_allows_stock_count'=>$check!==false && (int)$check->fetchColumn()===1,
            'already_applied'=>$version!==false && (int)$version->fetchColumn()===1,
        ];
    };
    $before=$state();
    $barcodes=(int)$pdo->query('SELECT COUNT(*) FROM product_units WHERE barcode IS NOT NULL')->fetchColumn();
    if ($dryRun) {
        echo json_encode(['migration'=>'20261012-stock-counts','mode'=>'dry-run','destructive'=>false]+$before+['barcodes_available'=>$barcodes],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
        exit(0);
    }
    $path=dirname(__DIR__).'/database/migrations/20261012-stock-counts.sql';
    $sql=is_file($path)?file_get_contents($path):false;
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
    $after=$state();
    $checks=(new App\Repository\MySql\StockCountChecks($pdo))->report();
    $lots=(new App\Repository\MySql\BatchLotChecks($pdo))->report();
    $ready=$after['tables_missing']===[] && $after['lot_source_allows_stock_count'] && array_sum($checks)===0 && array_sum($lots)===0;
    if ($ready && !$after['already_applied']) {
        $pdo->prepare('INSERT INTO schema_versions (version, description) VALUES (:version, :description) ON DUPLICATE KEY UPDATE description = VALUES(description)')
            ->execute(['version'=>'stock-count-1','description'=>'Barcode lookup and stock count sessions with blind count, recount and variance posting (D-18, ADR-024)']);
    }
    echo json_encode(['migration'=>'20261012-stock-counts','destructive'=>false,'status'=>$ready?'ready':'failed','already_applied'=>$before['already_applied'],
        'tables_created'=>$before['tables_missing'],'lot_source_widened'=>!$before['lot_source_allows_stock_count'],'barcodes_available'=>$barcodes,
        'count_checks'=>$checks,'lot_checks'=>$lots],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
    exit($ready?0:2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.','detail'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
