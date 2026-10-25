<?php
declare(strict_types=1);
// Applies database/migrations/20261010-stock-reservation.sql (D-12, ADR-018), then sets reserved_quantity from the
// currently Approved sales orders. Additive and repeatable: quantities, ledger, orders and seed data are never changed.
// If an Approved order needs more than the physical stock of its source warehouse, nothing is reserved, the conflicting
// orders are listed (exit 2) and the schema version is not recorded: resolve those orders first, then run it again.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $path=dirname(__DIR__).'/database/migrations/20261010-stock-reservation.sql';
    if (!is_file($path)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $sql=file_get_contents($path);
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
    $reconciler=new App\Repository\MySql\StockReservationReconciler($pdo);
    $report=$reconciler->reconcile();
    if ($report['status']==='conflict') {
        echo json_encode(['status'=>'conflict','migration'=>'20261010-stock-reservation','destructive'=>false,
            'message'=>'Approved sales orders need more stock than is on hand. Nothing was reserved. Resolve these orders (receive stock or cancel them), then run the migration again.',
            'conflicts'=>$report['conflicts']],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(2);
    }
    if ($reconciler->mismatches()!==0) { throw new \App\Exception\InfrastructureException('Reservation invariant does not hold after migration.'); }
    $pdo->exec("INSERT INTO schema_versions (version, description) VALUES ('stock-reservation-1', 'Stock reservation at sales order approval (D-12, ADR-018)') ON DUPLICATE KEY UPDATE description = VALUES(description)");
    echo json_encode(['status'=>'ready','migration'=>'20261010-stock-reservation','destructive'=>false,'updated_pairs'=>$report['updated_pairs'],'reserved_units'=>$report['reserved_units'],'reservation_mismatches'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.'],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
