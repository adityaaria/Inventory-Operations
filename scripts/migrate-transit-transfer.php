<?php
declare(strict_types=1);
// Applies database/migrations/20261012-transit-transfer.sql (D-15, ADR-021): Dispatched/Received transfer statuses,
// dispatch/receipt stamps, per-line dispatched/received/damaged/short quantities. Additive and repeatable: Posted
// transfers become Received with dispatched = received = quantity (already applied, never re-applied); stock quantities
// and ledger rows are never changed. Afterwards it verifies the transit invariants (ledger per line, status, in-transit
// equation, discrepancy reason) and stock = ledger. If a check fails it prints a report, exits 2 and does not record
// schema version 'transit-transfer-1'.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $path=dirname(__DIR__).'/database/migrations/20261012-transit-transfer.sql';
    if (!is_file($path)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $sql=file_get_contents($path);
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
    $columns=$pdo->query("SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_operations' AND column_name IN ('dispatched_by','dispatched_at','received_by','received_at','receipt_reason'))
        + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_operation_items' AND column_name IN ('dispatched_quantity','received_quantity','damaged_quantity','short_quantity'))
        + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_operations' AND column_name='status' AND column_type LIKE '%''Dispatched''%' AND column_type LIKE '%''Received''%')
        + (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_name IN ('chk_operation_transit_status','chk_operation_transit_stamps','chk_operation_items_dispatched','chk_operation_items_receipt'))");
    if ($columns===false || (int)$columns->fetchColumn()!==14) { throw new \App\Exception\InfrastructureException('Columns missing after migration.'); }
    $checks=(new App\Repository\MySql\TransferTransitChecks($pdo))->report();
    $ledger=$pdo->query("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type = 'Receipt' THEN quantity WHEN movement_type = 'Issue' THEN -quantity ELSE COALESCE(quantity_delta, 0) END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)");
    $checks['ledger_mismatches']=$ledger===false?-1:(int)$ledger->fetchColumn();
    if (array_sum($checks)!==0) {
        echo json_encode(['status'=>'conflict','migration'=>'20261012-transit-transfer','destructive'=>false,
            'message'=>'Transfer lines do not match their TRANSFER ledger rows or status. The schema was extended but the version was not recorded. Inspect the listed counts, then run the migration again.',
            'checks'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
        exit(2);
    }
    $pdo->exec("INSERT INTO schema_versions (version, description) VALUES ('transit-transfer-1', 'Two-step warehouse transfers with goods in transit (D-15, ADR-021)') ON DUPLICATE KEY UPDATE description = VALUES(description)");
    $counts=$pdo->query("SELECT status, COUNT(*) FROM inventory_operations WHERE kind='Transfer' GROUP BY status");
    echo json_encode(['status'=>'ready','migration'=>'20261012-transit-transfer','destructive'=>false,
        'transfers'=>$counts===false?[]:$counts->fetchAll(PDO::FETCH_KEY_PAIR),
        'in_transit_units'=>(new App\Repository\MySql\TransferTransitChecks($pdo))->inTransitUnits()]+$checks,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.'],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
