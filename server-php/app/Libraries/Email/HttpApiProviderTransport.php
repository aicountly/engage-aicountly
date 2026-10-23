<?php

declare(strict_types=1);

namespace App\Libraries\Email;

require_once dirname(__DIR__) . '/EmailTransport/HttpApiTransport.php';

/**
 * Registry provider of type "http_api" (config api_url, api_format; secret = API key) → Console's
 * HttpApiTransport (app/Libraries/EmailTransport/HttpApiTransport.php — copied verbatim from
 * console-react-app; re-copy, never edit). Keeps EMAIL_API_FORMAT behaviour: auto + /email/1/send =
 * multipart only; auto + other URL = JSON then multipart; json / multipart = that mode only.
 */
final class HttpApiProviderTransport
{
    /** @var callable|null */
    private $http;

    /** @param callable|null $http test seam: (url, mode, headers, body, timeout) → {response, http_code, curl_error} */
    public function __construct(?callable $http = null)
    {
        $this->http = $http;
    }

    /**
     * @param array{code:string, config:array<string,mixed>, secret:string} $provider
     * @param array<string,mixed> $msg
     * @return array{ok:bool, id:?string, error:?string, mode:string, url:string}
     */
    public function send(array $provider, array $msg): array
    {
        $cfg       = is_array($provider['config'] ?? null) ? $provider['config'] : [];
        $transport = new \HttpApiTransport(
            trim((string) ($cfg['api_url'] ?? '')) ?: \HttpApiTransport::DEFAULT_API_URL,
            (string) ($provider['secret'] ?? ''),
            trim((string) ($cfg['api_format'] ?? '')) ?: 'auto',
            $this->http,
        );
        $r = $transport->send($msg);

        return ['ok' => (bool) $r['ok'], 'id' => $r['message_id'] ?? null, 'error' => $r['error'] ?? null, 'mode' => (string) ($r['mode'] ?? ''), 'url' => (string) ($r['url'] ?? '')];
    }
}
