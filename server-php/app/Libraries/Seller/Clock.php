<?php
declare(strict_types=1);

namespace App\Libraries\Seller;

/** UTC timestamps for TIMESTAMPTZ columns (explicit offset, so the DB session time zone never matters). */
final class Clock
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s') . '+00';
    }

    public static function at(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts) . '+00';
    }

    public static function iso(?string $dbValue): ?string
    {
        if ($dbValue === null || $dbValue === '') {
            return null;
        }
        $ts = strtotime($dbValue);

        return $ts === false ? $dbValue : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
