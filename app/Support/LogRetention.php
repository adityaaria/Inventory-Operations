<?php
declare(strict_types=1);
namespace App\Support;
use RuntimeException;
final class LogRetention
{
    public function __construct(private readonly string $path, private readonly int $maxBytes = 10485760, private readonly int $maxFiles = 5)
    {
        if ($maxBytes < 1 || $maxFiles < 1 || $maxFiles > 100) { throw new \InvalidArgumentException('Invalid log retention limits.'); }
    }
    public function append(string $line): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) { throw new RuntimeException('Cannot create log directory.'); }
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false) { throw new RuntimeException('Cannot open log lock.'); }
        chmod($this->path . '.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('Cannot lock log.'); }
            clearstatcache(true, $this->path);
            $size = is_file($this->path) ? filesize($this->path) : 0;
            if ($size !== false && $size > 0 && $size + strlen($line) > $this->maxBytes) {
                $oldest = $this->path . '.' . $this->maxFiles;
                if (is_file($oldest) && !unlink($oldest)) { throw new RuntimeException('Cannot remove expired log.'); }
                for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
                    $source = $this->path . '.' . $i;
                    if (is_file($source) && !rename($source, $this->path . '.' . ($i + 1))) { throw new RuntimeException('Cannot rotate log.'); }
                }
                if (!rename($this->path, $this->path . '.1')) { throw new RuntimeException('Cannot rotate current log.'); }
            }
            if (file_put_contents($this->path, $line, FILE_APPEND) === false) { throw new RuntimeException('Cannot append log.'); }
            chmod($this->path, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
