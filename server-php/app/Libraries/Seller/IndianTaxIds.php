<?php
declare(strict_types=1);

namespace App\Libraries\Seller;

/**
 * Format checks for Indian identifiers used in seller KYC. Format only — no lookup against
 * the Income Tax / GST portals (a KYC reviewer verifies them against the uploaded documents).
 */
final class IndianTaxIds
{
    /** GST state codes (01–38) plus 97 "other territory". */
    private const GST_STATE_CODES = [
        '01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20',
        '21','22','23','24','25','26','27','28','29','30','31','32','33','34','35','36','37','38','97',
    ];

    /** PAN 4th character (holder type) allowed per entity type. */
    private const PAN_HOLDER = [
        'individual'     => ['P'],
        'proprietorship' => ['P'],
        'partnership'    => ['F'],
        'llp'            => ['F'],
        'company'        => ['C'],
    ];

    public const ENTITY_TYPES = ['individual', 'proprietorship', 'partnership', 'llp', 'company'];

    public static function normalise(?string $v): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', (string) $v));
    }

    public static function isPan(string $pan): bool
    {
        return (bool) preg_match('/\A[A-Z]{5}[0-9]{4}[A-Z]\z/', $pan);
    }

    /** Error message, or null when the PAN fits the entity type. */
    public static function panEntityMismatch(string $pan, string $entityType): ?string
    {
        $allowed = self::PAN_HOLDER[$entityType] ?? null;
        if ($allowed === null || ! self::isPan($pan)) {
            return null;
        }

        return in_array($pan[3], $allowed, true) ? null
            : sprintf('This PAN (4th character %s) does not belong to a %s; expected %s.', $pan[3], $entityType, implode('/', $allowed));
    }

    public static function isGstin(string $gstin): bool
    {
        if (! preg_match('/\A[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]\z/', $gstin)) {
            return false;
        }
        if (! in_array(substr($gstin, 0, 2), self::GST_STATE_CODES, true)) {
            return false;
        }

        return self::gstinChecksum(substr($gstin, 0, 14)) === $gstin[14];
    }

    public static function gstStateCode(string $gstin): string
    {
        return substr($gstin, 0, 2);
    }

    public static function isValidStateCode(string $code): bool
    {
        return in_array($code, self::GST_STATE_CODES, true);
    }

    /** GSTIN check character (base-36 Luhn mod N variant used by GSTN). */
    public static function gstinChecksum(string $first14): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $v = strpos($chars, $first14[$i]);
            if ($v === false) {
                return '?';
            }
            $p = $v * (($i % 2) + 1);
            $sum += intdiv($p, 36) + ($p % 36);
        }

        return $chars[(36 - ($sum % 36)) % 36];
    }

    public static function isIfsc(string $ifsc): bool
    {
        return (bool) preg_match('/\A[A-Z]{4}0[A-Z0-9]{6}\z/', $ifsc);
    }

    public static function isBankAccount(string $acct): bool
    {
        return (bool) preg_match('/\A[0-9]{9,18}\z/', $acct);
    }

    public static function isPincode(string $pin): bool
    {
        return (bool) preg_match('/\A[1-9][0-9]{5}\z/', $pin);
    }
}
