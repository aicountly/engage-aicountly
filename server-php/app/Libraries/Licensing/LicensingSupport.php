<?php
declare(strict_types=1);

namespace App\Libraries\Licensing;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Small helpers shared by the licensing services: instants (dates are Indian calendar days), terms,
 * PostgreSQL text[] literals and the contract §0.3 cursor.
 */
final class LicensingSupport
{
    public const TZ = 'Asia/Kolkata';

    /**
     * "YYYY-MM-DD" = 00:00 IST that day; anything else must be an ISO-8601 instant with an offset.
     * Returns a UTC "Y-m-d H:i:s+00" string, or null when unparsable.
     */
    public static function parseInstant(mixed $v): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        try {
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $v)) {
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone(self::TZ));
                if ($d === false || $d->format('Y-m-d') !== $v) {
                    return null;
                }
            } elseif (preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+\-]\d{2}(:?\d{2})?)\z/i', $v)) {
                $d = new DateTimeImmutable($v);
            } else {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return self::db($d);
    }

    public static function db(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . '+00';
    }

    public static function toDate(string $dbValue): DateTimeImmutable
    {
        return (new DateTimeImmutable($dbValue))->setTimezone(new DateTimeZone(self::TZ));
    }

    /** start + months + days on the Indian calendar; null when the term is open-ended (0 + 0). */
    public static function addTerm(string $startDb, int $months, int $days): ?string
    {
        if ($months <= 0 && $days <= 0) {
            return null;
        }
        $d = self::toDate($startDb);
        if ($months > 0) {
            $d = $d->add(new DateInterval('P' . $months . 'M'));
        }
        if ($days > 0) {
            $d = $d->add(new DateInterval('P' . $days . 'D'));
        }

        return self::db($d);
    }

    /** dd/mm/yyyy in IST (customer-safe summaries). */
    public static function displayDate(?string $dbValue): string
    {
        return $dbValue ? self::toDate($dbValue)->format('d/m/Y') : '—';
    }

    public static function iso(?string $dbValue): ?string
    {
        if ($dbValue === null || $dbValue === '') {
            return null;
        }

        return (new DateTimeImmutable($dbValue))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** Generic cycle text from the term ("12 months", "30 days", "1 month 15 days"); null when open-ended. */
    public static function cycleLabel(int $months, int $days): ?string
    {
        $parts = [];
        if ($months > 0) {
            $parts[] = $months . ' month' . ($months === 1 ? '' : 's');
        }
        if ($days > 0) {
            $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** @param list<string> $values */
    public static function pgArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn ($v) => '"' . addcslashes((string) $v, '"\\') . '"', array_values($values))) . '}';
    }

    /** @return list<string> */
    public static function fromJsonArray(mixed $json): array
    {
        if (is_array($json)) {
            return array_values(array_map('strval', $json));
        }
        $d = json_decode((string) $json, true);

        return is_array($d) ? array_values(array_map('strval', $d)) : [];
    }

    /** @return array<mixed> */
    public static function json(mixed $v, array $default = []): array
    {
        if (is_array($v)) {
            return $v;
        }
        if ($v === null || $v === '') {
            return $default;
        }
        $d = json_decode((string) $v, true);

        return is_array($d) ? $d : $default;
    }

    // ---------------------------------------------------------------- cursor (contract §0.3)

    /** base64url(JSON{"d":"<timestamp>","id":<id>}) */
    public static function encodeCursor(string $d, int $id): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['d' => $d, 'id' => $id])), '+/', '-_'), '=');
    }

    /** @return array{d:string, id:int}|null  null = not a valid cursor */
    public static function decodeCursor(string $cursor): ?array
    {
        if ($cursor === '' || strlen($cursor) > 256 || ! preg_match('/\A[A-Za-z0-9_\-]+\z/', $cursor)) {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $j = $raw === false ? null : json_decode($raw, true);
        if (! is_array($j) || ! is_string($j['d'] ?? null) || ! is_int($j['id'] ?? null) || $j['id'] < 1) {
            return null;
        }
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $j['d'])) {
            return null;
        }

        return ['d' => $j['d'], 'id' => $j['id']];
    }

    /** SQL expression giving a column's full-precision UTC timestamp for the cursor. */
    public static function cursorExpr(string $column): string
    {
        return "to_char({$column} AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')";
    }

    /**
     * Run $fn with CodeIgniter's transaction exceptions on: since CI 4.5 a failing query INSIDE a transaction returns
     * false instead of throwing, so a failed insert would let the caller "commit" (PostgreSQL then rolls the aborted
     * transaction back) and report success. Licensing writes must fail loudly; the flag is restored afterwards, and the
     * connection's transaction status is reset — in CodeIgniter's strict mode a caught failure would otherwise make
     * the NEXT transStart()/transComplete() on the shared connection roll back silently.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function strictTx(\CodeIgniter\Database\BaseConnection $db, callable $fn): mixed
    {
        $db->transException(true);
        try {
            return $fn();
        } finally {
            $db->transException(false);
            $db->resetTransStatus();
        }
    }
}
