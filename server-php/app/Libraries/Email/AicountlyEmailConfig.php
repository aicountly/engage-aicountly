<?php

declare(strict_types=1);

namespace App\Libraries\Email;

/**
 * Settings for the "AICOUNTLY Email" provider: AICOUNTLY's central delivery API
 * (email-aicountly docs/DELIVERY_API.md, POST /v1/service/deliveries).
 *
 * Off by default. ENGAGE_EMAIL_PROVIDER selects where EmailDispatcher sends:
 *   console (default; empty means the same) — the providers Console assigns to Engage (unchanged behaviour);
 *   aicountly_email                         — only the central delivery API, as Engage itself (X-Service-Key).
 *
 *   AICOUNTLY_EMAIL_API_BASE_URL   default https://email.apis.aicountly.com/v1 (sandbox https://email.gh.aicountly.com/api/v1)
 *   AICOUNTLY_EMAIL_SERVICE_KEY    Engage's key in Email's SERVICE_KEYS (engage:<key>); Email's DELIVERY_PRODUCTS must list
 *                                  engage:transactional,business
 *   AICOUNTLY_EMAIL_WEBHOOK_SECRET the secret PUT /v1/service/delivery-webhook showed once (signed delivery events)
 *   AICOUNTLY_EMAIL_MAX_ATTEMPTS   tries per message on 429 / 503 / no answer, same Idempotency-Key (default 3, 1–5)
 *   AICOUNTLY_EMAIL_MAX_RETRY_WAIT longest wait between tries, seconds (default 5); a longer Retry-After stops retrying
 *   AICOUNTLY_EMAIL_TIMEOUT        request timeout, seconds (default 10)
 *
 * No value read here is ever returned by status() or logged.
 */
final class AicountlyEmailConfig
{
    public const PROVIDER_CODE    = 'aicountly_email';
    public const DEFAULT_BASE_URL = 'https://email.apis.aicountly.com/v1';

    /** @var callable(string):string */
    private $env;

    /** @param (callable(string):string)|null $env */
    public function __construct(?callable $env = null)
    {
        $this->env = $env ?? static function (string $key): string {
            $v = function_exists('env') ? env($key, '') : ($_ENV[$key] ?? getenv($key));

            return is_string($v) ? trim($v) : '';
        };
    }

    /** ENGAGE_EMAIL_PROVIDER=aicountly_email (also "aicountly"). Anything else keeps Console's providers. */
    public function selected(): bool
    {
        return in_array(strtolower(($this->env)('ENGAGE_EMAIL_PROVIDER')), ['aicountly_email', 'aicountly'], true);
    }

    public function baseUrl(): string
    {
        return rtrim(($this->env)('AICOUNTLY_EMAIL_API_BASE_URL') ?: self::DEFAULT_BASE_URL, '/');
    }

    public function serviceKey(): string
    {
        return ($this->env)('AICOUNTLY_EMAIL_SERVICE_KEY');
    }

    public function webhookSecret(): string
    {
        return ($this->env)('AICOUNTLY_EMAIL_WEBHOOK_SECRET');
    }

    public function maxAttempts(): int
    {
        $n = (int) (($this->env)('AICOUNTLY_EMAIL_MAX_ATTEMPTS') ?: 3);

        return max(1, min(5, $n));
    }

    public function maxRetryWait(): int
    {
        $v = ($this->env)('AICOUNTLY_EMAIL_MAX_RETRY_WAIT');

        return $v === '' ? 5 : max(0, min(30, (int) $v));
    }

    public function timeout(): int
    {
        return max(2, min(60, (int) (($this->env)('AICOUNTLY_EMAIL_TIMEOUT') ?: 10)));
    }

    /** Why the provider cannot be used, or null when it can. */
    public function problem(): ?string
    {
        if ($this->serviceKey() === '') {
            return 'AICOUNTLY_EMAIL_SERVICE_KEY is not set';
        }
        if (preg_match('#^https://[^\s/]+#', $this->baseUrl()) !== 1) {
            return 'AICOUNTLY_EMAIL_API_BASE_URL must be an https URL';
        }

        return null;
    }

    /**
     * The provider entry EmailDispatcher sends through (same shape as a Console registry entry).
     *
     * @return array{code:string, type:string, priority:int, config:array<string,scalar|null>, secret:string, source:string}
     */
    public function provider(): array
    {
        return [
            'code'     => self::PROVIDER_CODE,
            'type'     => self::PROVIDER_CODE,
            'priority' => 1,
            'config'   => [
                'base_url'       => $this->baseUrl(),
                'max_attempts'   => $this->maxAttempts(),
                'max_retry_wait' => $this->maxRetryWait(),
                'timeout'        => $this->timeout(),
            ],
            'secret'   => $this->serviceKey(),
            'source'   => self::PROVIDER_CODE,
        ];
    }
}
