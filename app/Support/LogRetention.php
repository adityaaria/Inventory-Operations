<?php
declare(strict_types=1);
namespace App\Support;
use App\Exception\InfrastructureException;
final class LogRetention
{
    public function __construct(private readonly string $path, private readonly int $maxBytes = 10485760, private readonly int $maxFiles = 5)
    {
        if ($maxBytes < 1 || $maxFiles < 1 || $maxFiles > 100) { throw new \InvalidArgumentException('Invalid log retention limits.'); }
    }
    public function append(string $line): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) { throw new InfrastructureException('Cannot create log directory.'); }
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false) { throw new InfrastructureException('Cannot open log lock.'); }
        chmod($this->path . '.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) { throw new InfrastructureException('Cannot lock log.'); }
            clearstatcache(true, $this->path);
            $size = is_file($this->path) ? filesize($this->path) : 0;
            if ($size !== false && $size > 0 && $size + strlen($line) > $this->maxBytes) {
                $this->rotate();
            }
            if (file_put_contents($this->path, $line, FILE_APPEND) === false) { throw new InfrastructureException('Cannot append log.'); }
            chmod($this->path, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Called with the lock held: drops the oldest file, shifts .1..n-1 up by one and moves the current log to .1. */
    private function rotate(): void
    {
        $oldest = $this->path . '.' . $this->maxFiles;
        if (is_file($oldest) && !unlink($oldest)) { throw new InfrastructureException('Cannot remove expired log.'); }
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $source = $this->path . '.' . $i;
            if (is_file($source) && !rename($source, $this->path . '.' . ($i + 1))) { throw new InfrastructureException('Cannot rotate log.'); }
        }
        if (!rename($this->path, $this->path . '.1')) { throw new InfrastructureException('Cannot rotate current log.'); }
    }
}
