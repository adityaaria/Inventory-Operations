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
        $line = json_encode([
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_THROW_ON_ERROR);

        (new LogRetention($this->path))->append($line . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(Throwable $throwable, array $context = []): void
    {
        $this->log('error', 'Unhandled exception.', $context + [
            'exception' => $throwable::class,
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
        ]);
    }
}
