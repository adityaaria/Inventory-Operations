<?php
declare(strict_types=1);
// Applies database/migrations/20261010-order-number-sequences.sql (D-11, ADR-017). Additive and idempotent: it
// creates document_sequences when missing and raises counters to the highest existing PO/SO-YYMM-NNNN number.
// Existing order numbers, stock, ledger and seed data are never touched; counters are never lowered.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $path=dirname(__DIR__).'/database/migrations/20261010-order-number-sequences.sql';
    if (!is_file($path)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $sql=file_get_contents($path);
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
    // Every exact-pattern number must sit at or below its counter, otherwise a new number could collide.
    $behind=$pdo->query("SELECT COUNT(*) FROM (SELECT 'PO' p, SUBSTRING(order_number,4,4) per, LEAST(CAST(SUBSTRING(order_number,9) AS UNSIGNED),4294967295) n FROM purchase_orders WHERE REGEXP_LIKE(order_number,'^PO-[0-9]{4}-[0-9]{4,10}$','i') UNION ALL SELECT 'SO', SUBSTRING(order_number,4,4), LEAST(CAST(SUBSTRING(order_number,9) AS UNSIGNED),4294967295) FROM sales_orders WHERE REGEXP_LIKE(order_number,'^SO-[0-9]{4}-[0-9]{4,10}$','i')) o LEFT JOIN document_sequences s ON s.prefix=o.p AND s.period=o.per WHERE s.last_number IS NULL OR o.n>s.last_number");
    if ($behind===false || (int)$behind->fetchColumn()!==0) { throw new \App\Exception\InfrastructureException('Sequence initialization incomplete.'); }
    $rows=$pdo->query('SELECT prefix, period, last_number FROM document_sequences ORDER BY prefix, period');
    if ($rows===false) { throw new \App\Exception\InfrastructureException('Sequence table missing after migration.'); }
    $sequences=[];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) { $sequences[$row['prefix'].'-'.$row['period']]=(int)$row['last_number']; }
    echo json_encode(['status'=>'ready','migration'=>'20261010-order-number-sequences','destructive'=>false,'sequences'=>$sequences],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.'],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
