<?php
declare(strict_types=1);

namespace App\Libraries\AicS2s;

/**
 * Outbound AIC-S2S v1 client Engage → Manage (contract §3.3). Signs every request with
 * App\Libraries\AicS2s\AicS2sSignature (X-AIC-Client: engage), never logs the secret.
 *
 *   MANAGE_API_BASE_URL              Manage's API base INCLUDING /api, e.g. https://manage.aicountly.com/api
 *   ENGAGE_OUTBOUND_MANAGE_KEY_ID    the key id Manage issued to Engage, e.g. engage-push-2026-10
 *   ENGAGE_OUTBOUND_MANAGE_SECRET    its shared secret (at least 32 bytes)
 *   ENGAGE_S2S_ENVIRONMENT           production | sandbox | local (sent as X-AIC-Environment; the same variable
 *                                    the inbound filter checks)
 *
 * Unconfigured = dormant: nothing is sent and callers keep their outbox rows pending.
 * The canonical PATH is the path after /api (e.g. /v1/inbound/engage/entitlements).
 */
final class ManageClient
{
    public const ENTITLEMENTS_PATH = '/v1/inbound/engage/entitlements';

    /** @var callable(string, string, list<string>, string): array{status:int, body:string|false|null, error?:string} */
    private $transport;

    public function __construct(
        ?callable $transport = null,
        private ?string $baseUrl = null,
        private ?string $keyId = null,
        private ?string $secret = null,
        private ?string $environment = null
    ) {
        $this->baseUrl ??= rtrim(trim((string) env('MANAGE_API_BASE_URL', '')), '/');
        $this->keyId ??= trim((string) env('ENGAGE_OUTBOUND_MANAGE_KEY_ID', ''));
        $this->secret ??= (string) env('ENGAGE_OUTBOUND_MANAGE_SECRET', '');
        $this->environment ??= strtolower(trim((string) env('ENGAGE_S2S_ENVIRONMENT', '')));
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && preg_match('#\Ahttps?://#', (string) $this->baseUrl) === 1
            && $this->keyId !== '' && strlen((string) $this->secret) >= AicS2sConfig::MIN_SECRET_BYTES
            && in_array($this->environment, AicS2sConfig::ENVIRONMENTS, true);
    }

    /** One line for /health (never a secret). */
    public function status(): string
    {
        if ($this->isConfigured()) {
            return 'ok (' . $this->environment . ', key ' . $this->keyId . ')';
        }
        $missing = [];
        if ($this->baseUrl === '') {
            $missing[] = 'MANAGE_API_BASE_URL';
        }
        if ($this->keyId === '') {
            $missing[] = 'ENGAGE_OUTBOUND_MANAGE_KEY_ID';
        }
        if (strlen((string) $this->secret) < AicS2sConfig::MIN_SECRET_BYTES) {
            $missing[] = 'ENGAGE_OUTBOUND_MANAGE_SECRET (≥ 32 bytes)';
        }
        if (! in_array($this->environment, AicS2sConfig::ENVIRONMENTS, true)) {
            $missing[] = 'ENGAGE_S2S_ENVIRONMENT';
        }

        return 'not configured (dormant) — set ' . implode(', ', $missing) . '; entitlement changes are queued, not sent';
    }

    /** @return array{status:int, json:?array, raw:?string, error:string} */
    public function post(string $path, array $body, string $requestId = ''): array
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $this->call('POST', $path, '', $json, $requestId);
    }

    /** @return array{status:int, json:?array, raw:?string, error:string} */
    public function get(string $path, string $query = ''): array
    {
        return $this->call('GET', $path, $query, '', '');
    }

    /** @return array{status:int, json:?array, raw:?string, error:string} */
    private function call(string $method, string $path, string $query, string $body, string $requestId): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 0, 'json' => null, 'raw' => null, 'error' => 'not_configured'];
        }
        $path = AicS2sSignature::normalisePath($path);
        $h = AicS2sSignature::headers('engage', (string) $this->keyId, (string) $this->secret, (string) $this->environment, $method, $path, $query, $body, 'service', null, null, $requestId !== '' ? $requestId : null);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($h as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        $url = $this->baseUrl . $path . ($query !== '' ? '?' . $query : '');
        try {
            $res = ($this->transport)($method, $url, $headers, $body);
        } catch (\Throwable $e) {
            $res = ['status' => 0, 'body' => null, 'error' => $e->getMessage()];
        }
        $raw = is_string($res['body'] ?? null) ? $res['body'] : null;
        $decoded = $raw !== null && $raw !== '' ? json_decode($raw, true) : null;

        return [
            'status' => (int) ($res['status'] ?? 0),
            'json'   => is_array($decoded) ? $decoded : null,
            'raw'    => $raw,
            'error'  => (string) ($res['error'] ?? ''),
        ];
    }

    /** @return array{status:int, body:string|false, error:string} */
    private static function curl(string $method, string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'body' => $raw, 'error' => $err];
    }
}
