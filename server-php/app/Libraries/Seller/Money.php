<?php
declare(strict_types=1);

namespace App\Libraries\Seller;

use InvalidArgumentException;

/**
 * INR amounts as integer paise internally and NUMERIC(14,2) strings ("1234.50") at the edges.
 * No floats anywhere in commission arithmetic.
 */
final class Money
{
    public const MAX_PAISE = 99_999_999_999_999; // NUMERIC(14,2)

    /** "1234.5" | "1234.50" | 1234 → 123450. Throws on anything else (negative, >2 decimals, junk). */
    public static function toPaise(mixed $amount): int
    {
        if (is_int($amount)) {
            $amount = (string) $amount;
        }
        if (is_float($amount)) {
            $amount = number_format($amount, 2, '.', '');
        }
        if (! is_string($amount) || ! preg_match('/\A(\d{1,12})(?:\.(\d{1,2}))?\z/', trim($amount), $m)) {
            throw new InvalidArgumentException('Amount must be a non-negative number with at most 2 decimals.');
        }
        $paise = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        if ($paise > self::MAX_PAISE) {
            throw new InvalidArgumentException('Amount is too large.');
        }

        return $paise;
    }

    public static function format(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $paise = abs($paise);

        return $sign . intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Percent ("7.5", up to 4 decimals, 0–100) → integer in 1/10000 of a percent. */
    public static function percentE4(mixed $pct): int
    {
        if (is_int($pct)) {
            $pct = (string) $pct;
        }
        if (is_float($pct)) {
            $pct = rtrim(rtrim(number_format($pct, 4, '.', ''), '0'), '.');
        }
        if (! is_string($pct) || ! preg_match('/\A(\d{1,3})(?:\.(\d{1,4}))?\z/', trim($pct), $m)) {
            throw new InvalidArgumentException('Percent must be a number with at most 4 decimals.');
        }
        $v = (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '0', 4, '0');
        if ($v > 100 * 10000) {
            throw new InvalidArgumentException('Percent must be between 0 and 100.');
        }

        return $v;
    }

    /** basis × percent, rounded half-up to the paisa. */
    public static function applyPercent(int $basisPaise, int $percentE4): int
    {
        return intdiv($basisPaise * $percentE4 + 500_000, 1_000_000);
    }

    public static function formatPercentE4(int $e4): string
    {
        return intdiv($e4, 10000) . '.' . str_pad((string) ($e4 % 10000), 4, '0', STR_PAD_LEFT);
    }
}
