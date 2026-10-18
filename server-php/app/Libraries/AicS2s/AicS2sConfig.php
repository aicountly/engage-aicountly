<?php
declare(strict_types=1);

namespace App\Libraries\AicS2s;

/**
 * Inbound AIC-S2S v1 configuration for one calling client, read from env (names only in .env.example):
 *
 *   ENGAGE_S2S_ENVIRONMENT                     production | sandbox | local — must equal the caller's X-AIC-Environment
 *   ENGAGE_INBOUND_<CLIENT>_KEY_IDS            comma-separated live key ids (two may be live during a rotation)
 *   ENGAGE_INBOUND_<CLIENT>_SECRET_<KEYID>     the shared secret for that key id, at least 32 bytes
 *   ENGAGE_INBOUND_<CLIENT>_SCOPE_<KEYID>      read (GET routes only; the default) | issue (POST routes only)
 *
 * <CLIENT> / <KEYID> are upper-cased with every character outside A-Z 0-9 turned into "_"
 * (key id manage-read-2026-10 → ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_READ_2026_10).
 * A key id whose secret is missing or shorter than 32 bytes is not live. No live key, or no / an unknown
 * environment → the client is not configured and every request answers 503 inbound_not_configured.
 */
final class AicS2sConfig
{
    public const ENVIRONMENTS = ['production', 'sandbox', 'local'];
    public const SCOPES = ['read', 'issue'];
    public const MIN_SECRET_BYTES = 32;

    /** @param array<string, array{secret:string, scope:string}> $keys */
    private function __construct(
        public readonly string $client,
        public readonly ?string $environment,
        public readonly array $keys
    ) {
    }

    public static function forClient(string $client): self
    {
        $c = self::envName($client);
        $environment = strtolower(trim((string) env('ENGAGE_S2S_ENVIRONMENT', '')));
        $keys = [];
        foreach (explode(',', (string) env("ENGAGE_INBOUND_{$c}_KEY_IDS", '')) as $id) {
            $id = trim($id);
            if ($id === '' || ! preg_match('/\A[A-Za-z0-9._\-]{1,64}\z/', $id)) {
                continue;
            }
            $k = self::envName($id);
            $secret = (string) env("ENGAGE_INBOUND_{$c}_SECRET_{$k}", '');
            if (strlen($secret) < self::MIN_SECRET_BYTES) {
                continue;
            }
            $scope = strtolower(trim((string) env("ENGAGE_INBOUND_{$c}_SCOPE_{$k}", 'read')));
            if (! in_array($scope, self::SCOPES, true)) {
                continue;
            }
            $keys[$id] = ['secret' => $secret, 'scope' => $scope];
        }

        return new self(strtolower($client), in_array($environment, self::ENVIRONMENTS, true) ? $environment : null, $keys);
    }

    public static function envName(string $s): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '_', strtoupper($s));
    }

    public function isConfigured(): bool
    {
        return $this->environment !== null && $this->keys !== [];
    }

    /** One line for /health (never a secret). */
    public function status(): string
    {
        if ($this->isConfigured()) {
            return 'ok (' . $this->environment . ', ' . count($this->keys) . ' key' . (count($this->keys) === 1 ? '' : 's') . ')';
        }
        $c = self::envName($this->client);

        return "not configured (dormant) — set ENGAGE_S2S_ENVIRONMENT and ENGAGE_INBOUND_{$c}_KEY_IDS / _SECRET_<KEYID> (≥ 32 bytes); {$this->client} gets 503";
    }
}
