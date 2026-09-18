<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    public function string(string $key): string
    {
        $value = $this->value($key);

        if (!is_scalar($value)) {
            throw new \InvalidArgumentException("Configuration value must be scalar: {$key}");
        }

        return (string) $value;
    }

    public function int(string $key): int
    {
        $value = $this->string($key);

        if (!preg_match('/^-?\d+$/', $value)) {
            throw new \InvalidArgumentException("Configuration value must be an integer: {$key}");
        }

        return (int) $value;
    }

    public function bool(string $key): bool
    {
        $value = strtolower($this->string($key));

        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new \InvalidArgumentException("Configuration value must be boolean: {$key}"),
        };
    }

    private function value(string $key): mixed
    {
        if (!array_key_exists($key, $this->values) || $this->values[$key] === '') {
            throw new \InvalidArgumentException("Missing configuration value: {$key}");
        }

        return $this->values[$key];
    }
}
