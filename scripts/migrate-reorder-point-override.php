<?php
declare(strict_types=1);
// Applies database/migrations/20261010-reorder-point-override.sql (D-10, ADR-016). Additive and idempotent: it adds a
// nullable column and CHECK only when missing; stock quantities, ledger and seed data are never touched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/vendor/autoload.php';
$config=App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $path=dirname(__DIR__).'/database/migrations/20261010-reorder-point-override.sql';
    if (!is_file($path)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $sql=file_get_contents($path);
    if (!is_string($sql)) { throw new \App\Exception\InfrastructureException('Migration is unavailable.'); }
    $pdo->exec($sql);
    $check=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_stocks' AND column_name='reorder_point_override'");
    $check->execute();
    if ((int)$check->fetchColumn()!==1) { throw new \App\Exception\InfrastructureException('Column missing after migration.'); }
    echo json_encode(['status'=>'ready','migration'=>'20261010-reorder-point-override','destructive'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.'],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
