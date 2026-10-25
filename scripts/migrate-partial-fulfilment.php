<?php
declare(strict_types=1);
// Applies database/migrations/20261011-partial-fulfilment.sql (D-14, ADR-020): PartiallyFulfilled SO status,
// sales_order_items.issued_quantity (Fulfilled lines backfilled to quantity) and sales_order_closures. Additive and
// repeatable: stock quantities, ledger, prices and order statuses are never changed. Afterwards it verifies that
// issued_quantity equals the Issue ledger per line and that Fulfilled ⇔ all lines complete, then re-runs the reservation
// reconciliation with the D-14 formula (a no-op on consistent data). If a check fails it prints a report, exits 2 and
// does not record schema version 'partial-fulfilment-1'.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $path=dirname(__DIR__).'/database/migrations/20261011-partial-fulfilment.sql';
    if (!is_file($path)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $sql=file_get_contents($path);
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
    $columns=$pdo->query("SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales_order_items' AND column_name='issued_quantity' AND is_nullable='NO')
        + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales_orders' AND column_name='status' AND column_type LIKE '%''PartiallyFulfilled''%')
        + (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sales_order_closures')");
    if ($columns===false || (int)$columns->fetchColumn()!==3) { throw new \App\Exception\InfrastructureException('Columns missing after migration.'); }
    $checks=(new App\Repository\MySql\PartialFulfilmentChecks($pdo))->report();
    if (array_sum($checks)!==0) {
        echo json_encode(['status'=>'conflict','migration'=>'20261011-partial-fulfilment','destructive'=>false,
            'message'=>'Sales order lines do not match their Issue ledger rows or status. The schema was extended but the version was not recorded. Inspect the listed counts, then run the migration again.',
            'checks'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
        exit(2);
    }
    $reconciler=new App\Repository\MySql\StockReservationReconciler($pdo);
    $report=$reconciler->reconcile();
    if ($report['status']==='conflict') {
        echo json_encode(['status'=>'conflict','migration'=>'20261011-partial-fulfilment','destructive'=>false,
            'message'=>'Open sales orders need more stock than is on hand. Nothing was re-reserved. Resolve these orders, then run the migration again.',
            'conflicts'=>$report['conflicts']],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(2);
    }
    if ($reconciler->mismatches()!==0) { throw new \App\Exception\InfrastructureException('Reservation invariant does not hold after migration.'); }
    $pdo->exec("INSERT INTO schema_versions (version, description) VALUES ('partial-fulfilment-1', 'Partial sales order fulfilment with PartiallyFulfilled status (D-14, ADR-020)') ON DUPLICATE KEY UPDATE description = VALUES(description)");
    $fulfilled=$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status='Fulfilled'");
    echo json_encode(['status'=>'ready','migration'=>'20261011-partial-fulfilment','destructive'=>false,
        'fulfilled_orders'=>$fulfilled===false?0:(int)$fulfilled->fetchColumn(),
        'reservation_updated_pairs'=>$report['updated_pairs'],'reserved_units'=>$report['reserved_units'],'reservation_mismatches'=>0]+$checks,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.'],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
