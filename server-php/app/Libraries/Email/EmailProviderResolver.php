<?php

declare(strict_types=1);

namespace App\Libraries\Email;

/**
 * Which email providers Engage sends through — read from Console's central registry.
 * Copied from reach-aicountly (app/Libraries/Email/EmailProviderResolver.php) with app_code engage; keep in step.
 *
 *   GET {CONSOLE_API_URL | CONSOLE_API_BASE_URL}/portal/email/providers?app_code=engage
 *   Authorization: Bearer {CONSOLE_API_TOKEN}          (Console caller key, capability email.credentials)
 *   → { app_code, ttl_seconds, providers: [ {code, type: http_api|smtp, priority, config:{…}, secret} … ] }
 *
 * Rules (EMAIL-CONTRACT):
 *   - the answer is cached for ttl_seconds (default 300, at most 3600) in this process and in an
 *     encrypted file (AES-256-GCM, key derived from CONSOLE_API_TOKEN, mode 0600) so each PHP request
 *     does not ask Console again;
 *   - Console unreachable / 5xx / unreadable answer: the cached list is used for up to 15 minutes past
 *     its TTL ("stale"); further lookups back off for 60 s so a Console outage does not slow every send;
 *   - Console refuses the key (401/403): the cache is dropped (a revoked key must not keep using
 *     Console's secrets);
 *   - no usable Console answer and no cache, or Console lists no provider for engage: no provider
 *     (source "none"). The caller decides the last resort — SellerMailer uses Engage's own ENGAGE_SMTP_*.
 *     Once Console answers with providers, its list is authoritative.
 *
 * Secrets stay in memory and the encrypted cache: summary()/describe() return provider codes only.
 * Cache directory: EMAIL_PROVIDER_CACHE_DIR, else WRITEPATH/cache/email-providers.
 */
final class EmailProviderResolver
{
    public const APP_CODE              = 'engage';
    public const DEFAULT_TTL           = 300;
    public const MAX_TTL               = 3600;
    public const MAX_STALE_SECONDS     = 900;
    public const FAILURE_BACKOFF       = 60;

    /** @var callable(string,string):array{status:int,body:string,error:string} */
    private $http;

    /** @var callable():int */
    private $clock;

    /** @var callable(string):string */
    private $env;

    private ?string $cacheDir;

    /** @var array<string, array{until:int, result:array<string,mixed>}> */
    private static array $memo = [];

    /**
     * @param (callable(string $url, string $token):array{status:int,body:string,error:string})|null $http
     * @param (callable():int)|null $clock
     * @param (callable(string):string)|null $env
     */
    public function __construct(?callable $http = null, ?string $cacheDir = null, ?callable $clock = null, ?callable $env = null)
    {
        $this->http     = $http ?? [self::class, 'httpGet'];
        $this->clock    = $clock ?? static fn (): int => time();
        $this->env      = $env ?? static function (string $key): string {
            $v = function_exists('env') ? env($key, '') : ($_ENV[$key] ?? getenv($key));

            return is_string($v) ? trim($v) : '';
        };
        $this->cacheDir = $cacheDir;
    }

    public static function clearMemo(): void
    {
        self::$memo = [];
    }

    /**
     * @return array{providers: list<array{code:string,type:string,priority:int,config:array<string,scalar|null>,secret:string,source:string}>,
     *               source: string, ttl_seconds: int, note: ?string}
     *         source: console | console_stale | none
     */
    public function resolve(): array
    {
        $base  = $this->consoleBase();
        $token = ($this->env)('CONSOLE_API_TOKEN');
        if ($base === '' || $token === '') {
            return $this->noneResult('Console is not configured (CONSOLE_API_URL / CONSOLE_API_TOKEN)');
        }

        $now = ($this->clock)();
        $key = hash('sha256', $base . '|' . $token);
        if (isset(self::$memo[$key]) && self::$memo[$key]['until'] > $now) {
            return self::$memo[$key]['result'];
        }

        $cache = $this->readCache($key, $token);
        if ($cache !== null && $now - $cache['fetched_at'] < $cache['ttl']) {
            return $this->remember($key, $cache['fetched_at'] + $cache['ttl'], $this->consoleResult($cache['providers'], 'console', $cache['ttl'], null));
        }

        if ($this->inBackoff($key, $now)) {
            return $this->remember($key, $now + 5, $this->afterFailure($cache, $now, 'Console lookup is backing off after a recent failure'));
        }

        $answer = $this->fetch($base, $token);
        if ($answer['ok']) {
            $this->writeCache($key, $token, ['fetched_at' => $now, 'ttl' => $answer['ttl'], 'providers' => $answer['providers']]);
            $this->clearBackoff($key);

            return $this->remember($key, $now + $answer['ttl'], $this->consoleResult($answer['providers'], 'console', $answer['ttl'], $answer['note']));
        }

        $this->setBackoff($key, $now);
        if ($answer['refused']) {
            $this->deleteCache($key);
            $this->log('Console refused the email provider lookup: ' . $answer['error']);

            return $this->remember($key, $now + self::FAILURE_BACKOFF, $this->noneResult('Console refused the provider lookup (' . $answer['error'] . ')'));
        }

        $this->log('Console email provider lookup failed: ' . $answer['error']);

        return $this->remember($key, $now + self::FAILURE_BACKOFF, $this->afterFailure($cache, $now, 'Console unreachable (' . $answer['error'] . ')'));
    }

    /**
     * Names only — safe for health pages, reports and logs.
     *
     * @return array{source:string, note:?string, line:string, providers:list<array{code:string,type:string,priority:int,role:string}>}
     */
    public function summary(): array
    {
        $r = $this->resolve();
        $list = [];
        foreach ($r['providers'] as $i => $p) {
            $list[] = ['code' => $p['code'], 'type' => $p['type'], 'priority' => $p['priority'], 'role' => $i === 0 ? 'primary' : 'fallback'];
        }

        return ['source' => $r['source'], 'note' => $r['note'], 'line' => self::describe($r), 'providers' => $list];
    }

    /** "email via itwalk_api, fallback aicountly_smtp" (+ where the list came from when not live Console). */
    public static function describe(array $resolved): string
    {
        $codes = array_map(static fn (array $p): string => (string) $p['code'], $resolved['providers'] ?? []);
        if ($codes === []) {
            return 'email: no provider configured' . (!empty($resolved['note']) ? ' — ' . $resolved['note'] : '');
        }
        $line = 'email via ' . $codes[0];
        if (count($codes) > 1) {
            $line .= ', fallback ' . implode(', ', array_slice($codes, 1));
        }

        return $line . match ($resolved['source'] ?? '') {
            'console_stale' => ' (cached Console list — Console unreachable)',
            default         => '',
        };
    }

    // ── results ──────────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $providers */
    private function consoleResult(array $providers, string $source, int $ttl, ?string $note): array
    {
        if ($providers === []) {
            return $this->noneResult($note ?? 'Console lists no email provider for ' . self::APP_CODE);
        }
        foreach ($providers as &$p) {
            $p['source'] = $source;
        }
        unset($p);

        return ['providers' => $providers, 'source' => $source, 'ttl_seconds' => $ttl, 'note' => $note];
    }

    /** @param array{fetched_at:int, ttl:int, providers:list<array<string,mixed>>}|null $cache */
    private function afterFailure(?array $cache, int $now, string $why): array
    {
        if ($cache !== null && $now - $cache['fetched_at'] <= $cache['ttl'] + self::MAX_STALE_SECONDS && $cache['providers'] !== []) {
            return $this->consoleResult($cache['providers'], 'console_stale', $cache['ttl'], $why);
        }

        return $this->noneResult($why);
    }

    private function noneResult(string $why): array
    {
        return ['providers' => [], 'source' => 'none', 'ttl_seconds' => 0, 'note' => $why];
    }

    private function remember(string $key, int $until, array $result): array
    {
        self::$memo[$key] = ['until' => $until, 'result' => $result];

        return $result;
    }

    // ── Console ──────────────────────────────────────────────────────────────

    private function consoleBase(): string
    {
        foreach (['CONSOLE_API_URL', 'CONSOLE_API_BASE_URL'] as $k) {
            $url = rtrim(($this->env)($k), '/');
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * @return array{ok:bool, refused:bool, error:string, ttl:int, note:?string, providers:list<array<string,mixed>>}
     */
    private function fetch(string $base, string $token): array
    {
        $fail = static fn (string $error, bool $refused = false): array => ['ok' => false, 'refused' => $refused, 'error' => $error, 'ttl' => 0, 'note' => null, 'providers' => []];

        try {
            $res = ($this->http)($base . '/portal/email/providers?app_code=' . self::APP_CODE, $token);
        } catch (\Throwable $e) {
            return $fail('request failed: ' . $e->getMessage());
        }
        $status = (int) ($res['status'] ?? 0);
        if ($status === 401 || $status === 403) {
            return $fail('HTTP ' . $status, true);
        }
        if ($status < 200 || $status >= 300) {
            return $fail($status === 0 ? 'no response' . (($res['error'] ?? '') !== '' ? ': ' . $res['error'] : '') : 'HTTP ' . $status);
        }

        $decoded = json_decode((string) ($res['body'] ?? ''), true);
        if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }
        if (!is_array($decoded) || !isset($decoded['providers']) || !is_array($decoded['providers'])) {
            return $fail('unreadable answer');
        }

        $ttl = (int) ($decoded['ttl_seconds'] ?? self::DEFAULT_TTL);
        $ttl = $ttl > 0 ? min($ttl, self::MAX_TTL) : self::DEFAULT_TTL;

        $providers = [];
        $skipped   = [];
        foreach (array_values($decoded['providers']) as $i => $p) {
            $norm = is_array($p) ? self::normalise($p, $i) : null;
            if ($norm === null) {
                $skipped[] = is_array($p) && is_string($p['code'] ?? null) ? $p['code'] : '#' . $i;
                continue;
            }
            $providers[] = $norm;
        }
        // Console orders by effective priority already; a stable sort keeps that order for ties.
        usort($providers, static fn (array $a, array $b): int => [$a['priority'], $a['_i']] <=> [$b['priority'], $b['_i']]);
        foreach ($providers as &$p) {
            unset($p['_i']);
        }
        unset($p);

        return [
            'ok'        => true,
            'refused'   => false,
            'error'     => '',
            'ttl'       => $ttl,
            'note'      => $skipped !== [] ? 'ignored unusable provider entries: ' . implode(', ', $skipped) : null,
            'providers' => $providers,
        ];
    }

    /** @param array<string,mixed> $p */
    private static function normalise(array $p, int $index): ?array
    {
        $code = trim((string) ($p['code'] ?? ''));
        $type = strtolower(trim((string) ($p['type'] ?? '')));
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $code) !== 1 || !in_array($type, ['http_api', 'smtp'], true)) {
            return null;
        }
        if (array_key_exists('enabled', $p) && $p['enabled'] === false) {
            return null;
        }
        $secret = is_string($p['secret'] ?? null) ? $p['secret'] : '';
        $config = [];
        foreach ((is_array($p['config'] ?? null) ? $p['config'] : []) as $k => $v) {
            if (is_string($k) && (is_scalar($v) || $v === null)) {
                $config[$k] = $v;
            }
        }
        if ($type === 'http_api' && $secret === '') {
            return null;
        }
        if ($type === 'smtp' && trim((string) ($config['host'] ?? '')) === '') {
            return null;
        }

        return [
            'code'     => $code,
            'type'     => $type,
            'priority' => (int) ($p['priority'] ?? 100),
            'config'   => $config,
            'secret'   => $secret,
            '_i'       => $index,
        ];
    }

    /** @return array{status:int, body:string, error:string} */
    public static function httpGet(string $url, string $token): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl extension missing'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'X-Source: engage.aicountly.org',
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        return ['status' => $raw === false ? 0 : $status, 'body' => is_string($raw) ? $raw : '', 'error' => $err];
    }

    // ── encrypted file cache ─────────────────────────────────────────────────

    private function dir(): ?string
    {
        $dir = $this->cacheDir ?? (($this->env)('EMAIL_PROVIDER_CACHE_DIR')
            ?: (defined('WRITEPATH') ? rtrim(WRITEPATH, '/') . '/cache/email-providers' : rtrim(sys_get_temp_dir(), '/') . '/aicountly-engage-email'));
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? rtrim($dir, '/') : null;
    }

    private function path(string $key, string $suffix): ?string
    {
        $dir = $this->dir();

        return $dir === null ? null : $dir . '/email_providers_' . substr($key, 0, 24) . $suffix;
    }

    /** @return array{fetched_at:int, ttl:int, providers:list<array<string,mixed>>}|null */
    private function readCache(string $key, string $token): ?array
    {
        $path = $this->path($key, '.cache');
        if ($path === null || !is_file($path) || !function_exists('openssl_decrypt')) {
            return null;
        }
        $raw = base64_decode((string) @file_get_contents($path), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::cacheKey($token), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $data  = is_string($plain) ? json_decode($plain, true) : null;
        if (!is_array($data) || !isset($data['fetched_at'], $data['ttl'], $data['providers']) || !is_array($data['providers'])) {
            return null;
        }

        return ['fetched_at' => (int) $data['fetched_at'], 'ttl' => (int) $data['ttl'], 'providers' => array_values($data['providers'])];
    }

    /** @param array{fetched_at:int, ttl:int, providers:list<array<string,mixed>>} $data */
    private function writeCache(string $key, string $token, array $data): void
    {
        $path = $this->path($key, '.cache');
        if ($path === null || !function_exists('openssl_encrypt')) {
            return;
        }
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt((string) json_encode($data, JSON_UNESCAPED_SLASHES), 'aes-256-gcm', self::cacheKey($token), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            return;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $old = umask(0077);
        try {
            if (@file_put_contents($tmp, base64_encode($iv . $tag . $cipher), LOCK_EX) !== false) {
                @chmod($tmp, 0600);
                @rename($tmp, $path);
            }
        } finally {
            umask($old);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function deleteCache(string $key): void
    {
        $path = $this->path($key, '.cache');
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private function inBackoff(string $key, int $now): bool
    {
        $path = $this->path($key, '.fail');

        return $path !== null && is_file($path) && (int) @file_get_contents($path) > $now;
    }

    private function setBackoff(string $key, int $now): void
    {
        $path = $this->path($key, '.fail');
        if ($path !== null) {
            @file_put_contents($path, (string) ($now + self::FAILURE_BACKOFF), LOCK_EX);
        }
    }

    private function clearBackoff(string $key): void
    {
        $path = $this->path($key, '.fail');
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private static function cacheKey(string $token): string
    {
        return hash('sha256', 'aicountly-email-providers-cache|' . $token, true);
    }

    private function log(string $message): void
    {
        if (function_exists('log_message')) {
            log_message('warning', '[EmailProviderResolver] ' . $message);

            return;
        }
        error_log('[EmailProviderResolver] ' . $message);
    }
}
