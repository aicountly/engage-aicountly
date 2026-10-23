<?php
declare(strict_types=1);

namespace App\Services\Coupons;

/**
 * Coupon codes (docs/COUPONS.md §3, contract §4.1).
 *
 *   alphabet      ABCDEFGHJKLMNPQRSTUVWXYZ23456789 — 32 symbols, no 0/O/1/I (generated codes only)
 *   normalise     upper case, spaces and hyphens removed → codes are case-insensitive ("diwali-26" = "DIWALI26")
 *   shared        staff-typed, 4–20 characters A–Z / 0–9 (a marketing word may contain I or 0), or generated (8 chars)
 *   single-use    optional prefix (0–8 alphabet characters) + 10 random characters (CSPRNG) + 2 check characters
 *
 * Check characters: S = Σ (i+1)·v(c_i) mod 1021 over the prefix and the random part (v = position in the alphabet);
 * the two characters are alphabet[S div 32], alphabet[S mod 32]. 1021 is prime and every weight is < 1021, so any
 * single wrong character and any swap of two neighbouring characters changes S — a mistyped code is detected
 * before it is looked up. Search space of the random part: 32^10 ≈ 1.1e15.
 */
final class CodeGenerator
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const RANDOM_LENGTH = 10;
    public const CHECK_LENGTH = 2;
    public const MAX_PREFIX = 8;
    public const SHARED_GENERATED_LENGTH = 8;
    private const MOD = 1021;

    /** Upper case, spaces / hyphens / underscores removed. */
    public static function normalise(mixed $code): string
    {
        if (! is_string($code)) {
            return '';
        }

        return strtoupper((string) preg_replace('/[\s\-_]+/', '', $code));
    }

    /** A staff-typed shared code: 4–20 of A–Z / 0–9 after normalising. */
    public static function isValidShared(string $normalised): bool
    {
        return preg_match('/\A[A-Z0-9]{4,20}\z/', $normalised) === 1;
    }

    /** Shape a typed code may have at all (anything else is refused without a database lookup). */
    public static function isPlausible(string $normalised): bool
    {
        return preg_match('/\A[A-Z0-9]{4,32}\z/', $normalised) === 1;
    }

    public static function isValidPrefix(string $prefix): bool
    {
        return $prefix === '' || (strlen($prefix) <= self::MAX_PREFIX && strspn($prefix, self::ALPHABET) === strlen($prefix));
    }

    public static function randomPart(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }

    /** Two check characters for $body (prefix + random part, alphabet characters only). */
    public static function checkChars(string $body): string
    {
        $s = 0;
        $n = strlen($body);
        for ($i = 0; $i < $n; $i++) {
            $v = strpos(self::ALPHABET, $body[$i]);
            if ($v === false) {
                throw new \InvalidArgumentException('Only alphabet characters can carry a check.');
            }
            $s = ($s + ($i + 1) * $v) % self::MOD;
        }

        return self::ALPHABET[intdiv($s, 32)] . self::ALPHABET[$s % 32];
    }

    /** A new single-use code (prefix + 10 random + 2 check). */
    public static function singleUse(string $prefix = ''): string
    {
        $body = $prefix . self::randomPart(self::RANDOM_LENGTH);

        return $body . self::checkChars($body);
    }

    public static function sharedGenerated(): string
    {
        return self::randomPart(self::SHARED_GENERATED_LENGTH);
    }

    /** True when $code (normalised) is prefix + 10 + 2 with correct check characters. */
    public static function hasValidCheck(string $code, string $prefix = ''): bool
    {
        $len = strlen($prefix) + self::RANDOM_LENGTH + self::CHECK_LENGTH;
        if (strlen($code) !== $len || ($prefix !== '' && ! str_starts_with($code, $prefix)) || strspn($code, self::ALPHABET) !== $len) {
            return false;
        }
        $body = substr($code, 0, $len - self::CHECK_LENGTH);

        return hash_equals(self::checkChars($body), substr($code, -self::CHECK_LENGTH));
    }

    /** "ABCD-EFGH-JKLM" for display / CSV (groups of four). */
    public static function display(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    /** sha256 of the normalised code — what attempt logs keep instead of the code. */
    public static function fingerprint(string $normalised): string
    {
        return hash('sha256', $normalised);
    }
}
