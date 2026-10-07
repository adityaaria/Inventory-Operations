<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$config=require dirname(__DIR__).'/config/bootstrap.php';
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $pdo->exec("ALTER TABLE stock_ledger MODIFY movement_type ENUM('Receipt','Issue','Adjustment') NOT NULL");
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_ledger' AND column_name='quantity_delta'");$s->execute();
    if((int)$s->fetchColumn()===0) { $pdo->exec('ALTER TABLE stock_ledger ADD COLUMN quantity_delta BIGINT NULL'); }
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='stock_ledger' AND constraint_name='chk_stock_ledger_direction'");$s->execute();
    if((int)$s->fetchColumn()===0) { $pdo->exec("ALTER TABLE stock_ledger ADD CONSTRAINT chk_stock_ledger_direction CHECK ((movement_type='Adjustment' AND quantity_delta IS NOT NULL AND ABS(quantity_delta)=quantity) OR (movement_type<>'Adjustment' AND quantity_delta IS NULL))"); }
    $path=dirname(__DIR__).'/database/migrations/20261007-business-operations.sql';if(!is_file($path)) { throw new RuntimeException('Migration missing'); }$sql=file_get_contents($path);if(!is_string($sql)) { throw new RuntimeException('Migration missing'); }$pdo->exec($sql);
    echo json_encode(['status'=>'ready','migration'=>'20261007-business-operations','seed_replayed'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,json_encode(['status'=>'failed','reason'=>'Business migration failed; inspect deployment configuration.'],JSON_THROW_ON_ERROR).PHP_EOL);exit(1);}
