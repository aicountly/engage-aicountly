<?php
declare(strict_types=1);

namespace App\Libraries\AicS2s;

/**
 * AIC-S2S v1 request signing (commerce contract v1 §0.1) — the same scheme on every Aicountly server-to-server hop.
 *
 *   canonical = METHOD "\n" PATH "\n" CANONICAL_QUERY "\n" TIMESTAMP "\n" NONCE "\n" hex(sha256(raw body or ""))
 *   X-AIC-Signature: v1=<hex HMAC-SHA256(secret, canonical)>
 *
 * PATH is the path after /api (e.g. /v1/manage/companies/42/licences). CANONICAL_QUERY: every k=v pair decoded,
 * re-encoded per RFC 3986 (rawurlencode), sorted by key then value (byte order), joined with "&"; "" when there is none.
 * Used by the inbound filter (AicS2sFilter) and usable by any outbound client.
 */
final class AicS2sSignature
{
    public const VERSION_PREFIX = 'v1=';

    public static function canonicalQuery(string $rawQuery): string
    {
        $rawQuery = ltrim($rawQuery, '?');
        if ($rawQuery === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $rawQuery) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            $k = $eq === false ? $part : substr($part, 0, $eq);
            $v = $eq === false ? '' : substr($part, $eq + 1);
            $pairs[] = [rawurlencode(urldecode($k)), rawurlencode(urldecode($v))];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $pairs));
    }

    public static function canonical(string $method, string $path, string $rawQuery, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n" . self::normalisePath($path) . "\n" . self::canonicalQuery($rawQuery) . "\n"
            . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    /** "/v1/…" — a leading "api/" (base URL misconfigured) is dropped so PATH is always "after /api". */
    public static function normalisePath(string $path): string
    {
        $p = '/' . ltrim($path, '/');
        if (str_starts_with($p, '/api/')) {
            $p = substr($p, 4);
        }

        return $p;
    }

    public static function sign(string $secret, string $canonical): string
    {
        return self::VERSION_PREFIX . hash_hmac('sha256', $canonical, $secret);
    }

    public static function verify(string $secret, string $canonical, string $header): bool
    {
        $header = strtolower(trim($header));
        if (! str_starts_with($header, self::VERSION_PREFIX)) {
            return false;
        }

        // Constant-time compare of the full "v1=<hex>" string.
        return hash_equals(self::sign($secret, $canonical), $header);
    }

    /**
     * Every header a caller sends (for outbound clients, tests and the worked example in docs/LICENSING.md).
     *
     * @return array<string,string>
     */
    public static function headers(
        string $client,
        string $keyId,
        string $secret,
        string $environment,
        string $method,
        string $path,
        string $rawQuery = '',
        string $body = '',
        string $actor = 'service',
        ?int $timestamp = null,
        ?string $nonce = null,
        ?string $requestId = null
    ): array {
        $ts = (string) ($timestamp ?? time());
        $nonce ??= self::uuid4();
        $canonical = self::canonical($method, $path, $rawQuery, $ts, $nonce, $body);

        return [
            'X-AIC-Client'      => $client,
            'X-AIC-Key-Id'      => $keyId,
            'X-AIC-Timestamp'   => $ts,
            'X-AIC-Nonce'       => $nonce,
            'X-AIC-Environment' => $environment,
            'X-AIC-Signature'   => self::sign($secret, $canonical),
            'X-AIC-Actor'       => $actor,
            'X-AIC-Request-Id'  => $requestId ?? self::uuid4(),
            'X-Saas-Origin'     => $client,
        ];
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }
}
