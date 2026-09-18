<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class DatabaseFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    public function create(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->string('DB_HOST'),
            $this->config->int('DB_PORT'),
            $this->config->string('DB_DATABASE'),
        );

        return new PDO($dsn, $this->config->string('DB_USERNAME'), $this->config->string('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
