<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * PPN settings and invoice arithmetic (D-19, ADR-025). ASSUMPTIONS, to be verified by a tax adviser, not facts:
 * prices exclude PPN; PPN = rate % of a DPP of numerator/denominator of the net amount (defaults 12 % of 11/12 = an
 * effective 11 %); DPP and PPN are each rounded to whole Rupiah, half up, once per invoice; total = net + PPN.
 * Everything is computed in integer cents, so binary floats never decide an amount. The settings come from
 * VAT_RATE_PERCENT, VAT_DPP_NUMERATOR, VAT_DPP_DENOMINATOR and INVOICE_PAYMENT_TERMS_DAYS and are validated at boot.
 */
final class TaxSettings
{
    public const DEFAULT_RATE_PERCENT = 12;
    public const DEFAULT_DPP_NUMERATOR = 11;
    public const DEFAULT_DPP_DENOMINATOR = 12;
    public const DEFAULT_PAYMENT_TERMS_DAYS = 30;
    private const MAX_DENOMINATOR = 1000;

    public function __construct(
        private readonly int $ratePercent = self::DEFAULT_RATE_PERCENT,
        private readonly int $dppNumerator = self::DEFAULT_DPP_NUMERATOR,
        private readonly int $dppDenominator = self::DEFAULT_DPP_DENOMINATOR,
        private readonly int $paymentTermsDays = self::DEFAULT_PAYMENT_TERMS_DAYS,
    ) {
        if ($ratePercent < 0 || $ratePercent > 100) {
            throw new InvalidArgumentException('VAT_RATE_PERCENT must be a whole number between 0 and 100.');
        }
        if ($dppDenominator < 1 || $dppDenominator > self::MAX_DENOMINATOR || $dppNumerator < 1 || $dppNumerator > $dppDenominator) {
            throw new InvalidArgumentException('VAT_DPP_NUMERATOR and VAT_DPP_DENOMINATOR must be whole numbers with 1 <= numerator <= denominator <= ' . self::MAX_DENOMINATOR . '.');
        }
        if ($paymentTermsDays < 0 || $paymentTermsDays > 365) {
            throw new InvalidArgumentException('INVOICE_PAYMENT_TERMS_DAYS must be a whole number between 0 and 365.');
        }
    }

    /** Reads and validates the settings; an invalid value stops the application at boot. */
    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->int('VAT_RATE_PERCENT'),
            $config->int('VAT_DPP_NUMERATOR'),
            $config->int('VAT_DPP_DENOMINATOR'),
            $config->int('INVOICE_PAYMENT_TERMS_DAYS'),
        );
    }

    public function ratePercent(): int { return $this->ratePercent; }
    public function dppNumerator(): int { return $this->dppNumerator; }
    public function dppDenominator(): int { return $this->dppDenominator; }
    public function paymentTermsDays(): int { return $this->paymentTermsDays; }

    /** Effective rate on the net amount, e.g. "11,00%" for 12 % of 11/12 (display only). */
    public function effectiveRateLabel(): string
    {
        return number_format($this->ratePercent * $this->dppNumerator / $this->dppDenominator, 2, ',', '.') . '%';
    }

    /**
     * @return array{subtotal_cents: int, dpp_cents: int, vat_cents: int, total_cents: int}
     */
    public function totals(int $subtotalCents): array
    {
        return self::compute($subtotalCents, $this->ratePercent, $this->dppNumerator, $this->dppDenominator);
    }

    /**
     * The same formula the invoices table CHECKs: DPP = floor((cents × num + 50 × den) / (100 × den)) Rupiah (half up),
     * PPN = floor((DPP × rate + 50) / 100) Rupiah (half up), total = subtotal + PPN. Used with an invoice's snapshot too.
     *
     * @return array{subtotal_cents: int, dpp_cents: int, vat_cents: int, total_cents: int}
     */
    public static function compute(int $subtotalCents, int $ratePercent, int $numerator, int $denominator): array
    {
        if ($subtotalCents < 0) {
            throw new InvalidArgumentException('Invoice subtotal cannot be negative.');
        }
        $dppRupiah = intdiv($subtotalCents * $numerator + 50 * $denominator, 100 * $denominator);
        $vatRupiah = intdiv($dppRupiah * $ratePercent + 50, 100);

        return [
            'subtotal_cents' => $subtotalCents,
            'dpp_cents' => $dppRupiah * 100,
            'vat_cents' => $vatRupiah * 100,
            'total_cents' => $subtotalCents + $vatRupiah * 100,
        ];
    }

    /** Whole cents of a DECIMAL string or float amount (2 decimals). */
    public static function cents(string|float|int $amount): int
    {
        if (is_string($amount) && preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', trim($amount), $match) === 1) {
            $value = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '', 2, '0');

            return $match[1] === '-' ? -$value : $value;
        }

        return (int) round((float) $amount * 100);
    }

    /** "123456.70" for a cents value (plain decimal for SQL and CSV). */
    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
