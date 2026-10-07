<?php
declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

final class TestDatabase
{
    public static function connect(): PDO
    {
        $name = getenv('DB_DATABASE') ?: '';
        if (!preg_match('/^[a-zA-Z0-9_]+_test$/D', $name)) {
            throw new RuntimeException('Integration tests require a dedicated DB_DATABASE ending in _test. Use docker compose --profile quality run --rm test.');
        }
        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', $name),
            getenv('DB_USERNAME') ?: 'inventory_app',
            getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    public static function reset(): void
    {
        $pdo = self::connect();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema-and-seed.sql');
        if (!is_string($schema)) throw new RuntimeException('Test seed is unavailable.');
        $pdo->exec($schema);
    }
}
