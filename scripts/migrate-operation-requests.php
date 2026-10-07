<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config=require dirname(__DIR__).'/config/bootstrap.php';
try {
    $pdo=(new App\Support\DatabaseFactory($config))->create();
    $path=dirname(__DIR__).'/database/migrations/20261007-operation-requests.sql';
    if (!is_file($path)) { throw new RuntimeException('Migration is unavailable.'); }
    $sql=file_get_contents($path);
    if (!is_string($sql)) { throw new RuntimeException('Migration is unavailable.'); }
    $pdo->exec($sql);
    echo json_encode(['status'=>'ready','migration'=>'20261007-operation-requests','destructive'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status'=>'failed','reason'=>'Migration failed; inspect deployment configuration.'],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
