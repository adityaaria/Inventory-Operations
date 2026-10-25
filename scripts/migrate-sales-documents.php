<?php
declare(strict_types=1);
// Customer price lists, quotations, delivery notes and invoices with PPN (D-19, ADR-025). Back up first, then:
//   php scripts/migrate-sales-documents.php --dry-run   read-only: which tables/columns are missing and, when the schema
//                                                       exists, how many historical shipments would get a delivery note
//                                                       (conflicts listed). Changes nothing.
//   php scripts/migrate-sales-documents.php             applies database/migrations/20261012-sales-documents.sql (additive,
//                                                       idempotent DDL), then in ONE transaction creates one delivery note
//                                                       per historical SO shipment (App\Repository\MySql\DeliveryNoteMigration),
//                                                       re-checks every invariant, and records schema version
//                                                       sales-documents-1. No invoice is created for history.
// Re-running after success is a no-op that re-verifies. Exit 0 ready, 2 verification failed, 1 error.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
$dryRun=in_array('--dry-run',$argv??[],true);
const SALES_DOCUMENT_TABLES=['price_lists','price_list_items','quotations','quotation_items','delivery_notes','delivery_note_lines','delivery_note_lots','invoices','invoice_lines','invoice_payments'];
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $state=static function () use ($pdo): array {
        $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('".implode("','",SALES_DOCUMENT_TABLES)."')");
        $present=$tables===false?[]:array_map('strval',$tables->fetchAll(PDO::FETCH_COLUMN));
        $columns=$pdo->query("SELECT CONCAT(table_name,'.',column_name) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='customers' AND column_name IN ('price_list_id','npwp')) OR (table_name='sales_orders' AND column_name='quotation_id') OR (table_name='sales_order_items' AND column_name IN ('price_source','quotation_item_id')))");
        $prefix=$pdo->query("SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND constraint_name='chk_document_sequences_prefix' AND check_clause LIKE '%INV%'");
        $version=$pdo->query("SELECT COUNT(*) FROM schema_versions WHERE version='sales-documents-1'");
        return [
            'tables_missing'=>array_values(array_diff(SALES_DOCUMENT_TABLES,$present)),
            'columns_present'=>$columns===false?[]:array_map('strval',$columns->fetchAll(PDO::FETCH_COLUMN)),
            'sequence_prefixes_widened'=>$prefix!==false && (int)$prefix->fetchColumn()===1,
            'already_applied'=>$version!==false && (int)$version->fetchColumn()===1,
        ];
    };
    $before=$state();
    $shipments=(int)$pdo->query("SELECT COUNT(DISTINCT reference_id) FROM stock_ledger WHERE movement_type='Issue' AND reference_type='SO'")->fetchColumn();
    if ($dryRun) {
        // The preview groups the unlinked Issue rows exactly as the apply step will, also before the DDL exists.
        $preview=(new App\Repository\MySql\DeliveryNoteMigration($pdo))->dryRun()+($before['tables_missing']===[]?[]:['message'=>'The new tables do not exist yet; the run without --dry-run adds them (additive DDL) before creating delivery notes.']);
        echo json_encode(['migration'=>'20261012-sales-documents','destructive'=>false]+$before+['orders_with_shipments'=>$shipments,'delivery_notes'=>$preview],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(0);
    }
    $path=dirname(__DIR__).'/database/migrations/20261012-sales-documents.sql';
    $sql=is_file($path)?file_get_contents($path):false;
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
    $after=$state();
    $data=(new App\Repository\MySql\DeliveryNoteMigration($pdo))->apply();
    $checks=(new App\Repository\MySql\SalesDocumentChecks($pdo))->report();
    $lots=(new App\Repository\MySql\BatchLotChecks($pdo))->report();
    $ready=$after['tables_missing']===[] && $after['sequence_prefixes_widened'] && ($data['status']??'')==='ready' && array_sum($checks)===0 && array_sum($lots)===0;
    if ($ready && !$after['already_applied']) {
        $pdo->prepare('INSERT INTO schema_versions (version, description) VALUES (:version, :description) ON DUPLICATE KEY UPDATE description = VALUES(description)')
            ->execute(['version'=>'sales-documents-1','description'=>'Customer price lists, quotations, delivery notes and invoices with PPN (D-19, ADR-025)']);
    }
    echo json_encode(['migration'=>'20261012-sales-documents','destructive'=>false,'status'=>$ready?'ready':'failed','already_applied'=>$before['already_applied'],
        'tables_created'=>$before['tables_missing'],'delivery_notes'=>$data,'sales_document_checks'=>$checks,'lot_checks'=>$lots,
        'statistics'=>(new App\Repository\MySql\SalesDocumentChecks($pdo))->statistics()],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit($ready?0:2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.','detail'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
