<?php

declare(strict_types=1);

namespace App\Support;

/** Preserve attempted input without mutating entities; escape at the HTML boundary. */
final class FormState
{
    /** @param array<string, mixed> $input */
    public function __construct(private readonly array $input = [])
    {
    }

    public function value(string $name, mixed $fallback = ''): string
    {
        if (in_array($name, ['password', 'csrf_token'], true)) { return ''; }
        $value = array_key_exists($name, $this->input) ? $this->input[$name] : $fallback;
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    public function selected(string $name, mixed $value, bool $fallback = false): string
    {
        $matches = array_key_exists($name, $this->input) ? $this->value($name) === (string) $value : $fallback;
        return $matches ? 'selected' : '';
    }
}
