<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Money helpers. Amounts are stored as integer minor units; never floats.
 */
final class Money
{
    /** ISO 4217 currencies the UI offers. Others are rejected rather than guessed. */
    public const CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'INR', 'MXN', 'BRL',
        'SEK', 'NOK', 'DKK', 'NZD', 'SGD', 'HKD', 'KRW', 'THB', 'TRY', 'ZAR', 'AED', 'MAD',
    ];

    private const ZERO_DECIMAL = ['JPY', 'KRW'];

    public static function exponent(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** Parse a user decimal string ("1,234.50" or "1234.5") to minor units. */
    public static function toMinor(string|int|float $amount, string $currency): int
    {
        $clean = str_replace([',', ' '], '', trim((string) $amount));
        if (! preg_match('/^\d+(\.\d+)?$/', $clean)) {
            throw new InvalidArgumentException('Invalid amount.');
        }

        return BigDecimal::of($clean)
            ->multipliedBy(BigDecimal::of(10)->power(self::exponent($currency)))
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();
    }

    public static function toDecimalString(int $minor, string $currency): string
    {
        $exp = self::exponent($currency);

        return (string) BigDecimal::of($minor)->dividedBy(BigDecimal::of(10)->power($exp), $exp, RoundingMode::HALF_UP);
    }

    /**
     * Convert minor units of $from into minor units of $to using an explicit,
     * user-supplied rate (1 $from = $rate $to). No implicit rates exist.
     */
    public static function convertMinor(int $minor, string $from, string $to, ?string $rate): int
    {
        if (strtoupper($from) === strtoupper($to)) {
            return $minor;
        }
        if ($rate === null || $rate === '' || BigDecimal::of($rate)->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('A positive manual exchange rate is required.');
        }
        $major = BigDecimal::of($minor)->dividedBy(BigDecimal::of(10)->power(self::exponent($from)), 10, RoundingMode::HALF_UP);

        return $major->multipliedBy($rate)
            ->multipliedBy(BigDecimal::of(10)->power(self::exponent($to)))
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();
    }
}
