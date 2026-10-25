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

    /** Reads the process environment; every key has a safe local default except credentials. */
    public static function fromEnvironment(): self
    {
        return new self([
            'APP_NAME' => $_ENV['APP_NAME'] ?? getenv('APP_NAME') ?: 'Inventory & Order Management',
            'APP_ENV' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local',
            'APP_DEBUG' => $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'false',
            'APP_PORT' => $_ENV['APP_PORT'] ?? getenv('APP_PORT') ?: '8080',
            'SESSION_IDLE_SECONDS' => $_ENV['SESSION_IDLE_SECONDS'] ?? (getenv('SESSION_IDLE_SECONDS') === false ? '1800' : getenv('SESSION_IDLE_SECONDS')),
            'SESSION_ABSOLUTE_SECONDS' => $_ENV['SESSION_ABSOLUTE_SECONDS'] ?? (getenv('SESSION_ABSOLUTE_SECONDS') === false ? '28800' : getenv('SESSION_ABSOLUTE_SECONDS')),
            'SESSION_ROTATION_SECONDS' => $_ENV['SESSION_ROTATION_SECONDS'] ?? (getenv('SESSION_ROTATION_SECONDS') === false ? '900' : getenv('SESSION_ROTATION_SECONDS')),
            'SESSION_COOKIE_SECURE' => $_ENV['SESSION_COOKIE_SECURE'] ?? (getenv('SESSION_COOKIE_SECURE') === false ? 'auto' : getenv('SESSION_COOKIE_SECURE')),
            'SESSION_SAVE_PATH' => $_ENV['SESSION_SAVE_PATH'] ?? (getenv('SESSION_SAVE_PATH') === false ? sys_get_temp_dir() : getenv('SESSION_SAVE_PATH')),
            // D-13 / ADR-019: largest discount below the master selling price that Sales may give (Admin has no cap).
            'SALES_MAX_DISCOUNT_PERCENT' => $_ENV['SALES_MAX_DISCOUNT_PERCENT'] ?? (getenv('SALES_MAX_DISCOUNT_PERCENT') === false ? '10' : getenv('SALES_MAX_DISCOUNT_PERCENT')),
            // D-17 / ADR-023: business time zone of the expiry rule and the near-expiry window in days (1-365).
            'APP_TIMEZONE' => $_ENV['APP_TIMEZONE'] ?? (getenv('APP_TIMEZONE') === false ? BusinessDate::DEFAULT_TIMEZONE : getenv('APP_TIMEZONE')),
            'NEAR_EXPIRY_DAYS' => $_ENV['NEAR_EXPIRY_DAYS'] ?? (getenv('NEAR_EXPIRY_DAYS') === false ? '30' : getenv('NEAR_EXPIRY_DAYS')),
            // D-18 / ADR-024: a count line needs no recount while |variance| <= max(units, expected x percent / 100).
            'COUNT_RECOUNT_TOLERANCE_PERCENT' => $_ENV['COUNT_RECOUNT_TOLERANCE_PERCENT'] ?? (getenv('COUNT_RECOUNT_TOLERANCE_PERCENT') === false ? '2' : getenv('COUNT_RECOUNT_TOLERANCE_PERCENT')),
            'COUNT_RECOUNT_TOLERANCE_UNITS' => $_ENV['COUNT_RECOUNT_TOLERANCE_UNITS'] ?? (getenv('COUNT_RECOUNT_TOLERANCE_UNITS') === false ? '0' : getenv('COUNT_RECOUNT_TOLERANCE_UNITS')),
            // D-19 / ADR-025: PPN assumptions (to be verified by a tax adviser) and invoice payment terms; validated at boot.
            'VAT_RATE_PERCENT' => $_ENV['VAT_RATE_PERCENT'] ?? (getenv('VAT_RATE_PERCENT') === false ? '12' : getenv('VAT_RATE_PERCENT')),
            'VAT_DPP_NUMERATOR' => $_ENV['VAT_DPP_NUMERATOR'] ?? (getenv('VAT_DPP_NUMERATOR') === false ? '11' : getenv('VAT_DPP_NUMERATOR')),
            'VAT_DPP_DENOMINATOR' => $_ENV['VAT_DPP_DENOMINATOR'] ?? (getenv('VAT_DPP_DENOMINATOR') === false ? '12' : getenv('VAT_DPP_DENOMINATOR')),
            'INVOICE_PAYMENT_TERMS_DAYS' => $_ENV['INVOICE_PAYMENT_TERMS_DAYS'] ?? (getenv('INVOICE_PAYMENT_TERMS_DAYS') === false ? '30' : getenv('INVOICE_PAYMENT_TERMS_DAYS')),
            'DB_HOST' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'db',
            'DB_PORT' => $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306',
            'DB_DATABASE' => $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '',
            'DB_USERNAME' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: '',
            'DB_PASSWORD' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
        ]);
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
