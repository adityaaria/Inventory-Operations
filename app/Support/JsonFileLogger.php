<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

final class JsonFileLogger
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $line = json_encode([
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(Throwable $throwable, array $context = []): void
    {
        $this->log('error', $throwable->getMessage(), $context + [
            'exception' => $throwable::class,
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
        ]);
    }
}
