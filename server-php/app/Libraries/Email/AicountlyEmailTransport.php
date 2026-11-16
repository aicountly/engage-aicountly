<?php

declare(strict_types=1);

namespace App\Libraries\Email;

use App\Services\Email\EmailDeliveryLedger;

/**
 * Provider of type "aicountly_email" → AICOUNTLY's central delivery API
 * (email-aicountly docs/DELIVERY_API.md):
 *
 *   POST {base}/service/deliveries
 *   X-Service-Key: <Engage's key>          Engage acts as itself (no X-Actor-Uuid)
 *   Idempotency-Key: <stable, 8–120>       the same key always means the same message
 *
 * Category (Engage may send transactional and business only):
 *   opts category, else "business" when opts cmp_id and from_domain are given, else "transactional".
 *   transactional  From is no-reply@aicountly.io (set by the Email API; from_email is ignored)
 *   business       From is from_local_part@from_domain, from_domain one of cmp_id's verified domains
 *
 * Idempotency-Key: opts idempotency_key ("engage." + the key, hashed when it does not fit the API's
 * alphabet/length), else a hash of the message itself — so a retry of the same send can never send twice.
 * A resend (opts resend) without a caller key gets a fresh key: a person asked for another copy.
 *
 * Answers: 202 / 200 replayed → accepted (id kept). 429, 503, 500/502/504 and no answer → retried here
 * with the SAME key, up to max_attempts, waiting Retry-After (or 1 s, 2 s, …) when it is no longer than
 * max_retry_wait. 400 / 401 / 403 / 404 / 409 / 413 / 422 → not retried (fix the input or configuration).
 * Executable attachments, more than 50 recipients or 10 attachments are refused here, before any request.
 *
 * Message headers (e.g. List-Unsubscribe) are not sent: the API sets the headers for each category.
 */
final class AicountlyEmailTransport
{
    public const CATEGORIES        = ['transactional', 'business'];
    public const RETRYABLE_STATUS  = [0, 429, 500, 502, 503, 504];
    public const MAX_RECIPIENTS    = 50;
    public const MAX_ATTACHMENTS   = 10;
    public const BLOCKED_EXTENSIONS = ['exe', 'scr', 'com', 'pif', 'cpl', 'msi', 'jar', 'bat', 'cmd', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'hta', 'lnk', 'reg', 'iso', 'img'];

    /** @var callable(string $url, list<string> $headers, string $body, int $timeout): array{status:int, body:string, headers:array<string,string>, error:string} */
    private $http;

    /** @var callable(int $seconds): void */
    private $sleep;

    /** @var (callable(array<string,mixed>): void)|null */
    private $ledger;

    /**
     * @param callable|null $http   test seam (url, headers, body, timeout) → {status, body, headers, error}
     * @param callable|null $sleep  test seam (seconds) → void
     * @param callable|null $ledger records an accepted message (best effort); default EmailDeliveryLedger::recordQuietly
     */
    public function __construct(?callable $http = null, ?callable $sleep = null, ?callable $ledger = null)
    {
        $this->http   = $http ?? [self::class, 'httpPost'];
        $this->sleep  = $sleep ?? static function (int $s): void {
            if ($s > 0) {
                sleep($s);
            }
        };
        $this->ledger = $ledger ?? [EmailDeliveryLedger::class, 'recordQuietly'];
    }

    /**
     * @param array{code:string, config:array<string,mixed>, secret:string} $provider
     * @param array<string,mixed> $msg EmailDispatcher message (to, subject, html, text, from_name, reply_to, delivery{…})
     * @return array{ok:bool, id:?string, error:?string, mode:string, url:string, status:?string, replayed:bool,
     *               retryable:bool, http_status:int, attempts:int, idempotency_key:string, category:?string}
     */
    public function send(array $provider, array $msg): array
    {
        $cfg = is_array($provider['config'] ?? null) ? $provider['config'] : [];
        $url = rtrim(trim((string) ($cfg['base_url'] ?? '')) ?: AicountlyEmailConfig::DEFAULT_BASE_URL, '/') . '/service/deliveries';
        $key = (string) ($provider['secret'] ?? '');
        $d   = is_array($msg['delivery'] ?? null) ? $msg['delivery'] : [];

        $result = ['ok' => false, 'id' => null, 'error' => null, 'mode' => AicountlyEmailConfig::PROVIDER_CODE, 'url' => $url, 'status' => null,
            'replayed' => false, 'retryable' => false, 'http_status' => 0, 'attempts' => 0, 'idempotency_key' => '', 'category' => null];

        if ($key === '') {
            return ['error' => 'AICOUNTLY_EMAIL_SERVICE_KEY is not set'] + $result;
        }
        $built = self::payload($msg);
        if (isset($built['error'])) {
            return ['error' => $built['error']] + $result;
        }
        $payload = $built['payload'];
        $idem    = self::idempotencyKey($payload, $d);
        $result['idempotency_key'] = $idem;
        $result['category']        = $payload['category'];

        $body    = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Service-Key: ' . $key,
            'Idempotency-Key: ' . $idem,
            'X-Source: engage.aicountly.org',
        ];
        $max     = max(1, min(5, (int) ($cfg['max_attempts'] ?? 3)));
        $maxWait = max(0, (int) ($cfg['max_retry_wait'] ?? 5));
        $timeout = max(2, (int) ($cfg['timeout'] ?? 10));

        for ($attempt = 1; $attempt <= $max; $attempt++) {
            try {
                $res = ($this->http)($url, $headers, $body, $timeout);
            } catch (\Throwable $e) {
                $res = ['status' => 0, 'body' => '', 'headers' => [], 'error' => $e->getMessage()];
            }
            $status = (int) ($res['status'] ?? 0);
            $json   = json_decode((string) ($res['body'] ?? ''), true);
            $json   = is_array($json) ? $json : [];
            $result['attempts']    = $attempt;
            $result['http_status'] = $status;

            $id = $json['data']['id'] ?? null;
            if (($status === 202 || $status === 200 || $status === 201) && is_scalar($id) && (string) $id !== '') {
                $result = ['ok' => true, 'id' => (string) $id, 'error' => null, 'retryable' => false,
                    'status' => isset($json['data']['status']) ? (string) $json['data']['status'] : null,
                    'replayed' => !empty($json['replayed'])] + $result;
                $this->record($result, $payload);

                return $result;
            }

            $result['error']     = self::describeError($status, $json, (string) ($res['error'] ?? ''));
            $result['retryable'] = in_array($status, self::RETRYABLE_STATUS, true);
            if (!$result['retryable'] || $attempt >= $max) {
                break;
            }
            $wait = self::retryAfter($res['headers'] ?? []) ?? (1 << ($attempt - 1));
            if ($wait > $maxWait) {
                $result['error'] .= " (Retry-After {$wait}s is longer than this request waits; retry later with the same Idempotency-Key)";
                break;
            }
            ($this->sleep)($wait);
        }

        return $result;
    }

    /**
     * The JSON body for POST /v1/service/deliveries, or an error for input the API would refuse with 422/403.
     *
     * @param array<string,mixed> $msg
     * @return array{payload?:array<string,mixed>, error?:string}
     */
    public static function payload(array $msg): array
    {
        $d = is_array($msg['delivery'] ?? null) ? $msg['delivery'] : [];

        $to = [];
        foreach (is_array($msg['to'] ?? null) ? $msg['to'] : explode(',', (string) ($msg['to'] ?? '')) as $addr) {
            $addr = trim(is_array($addr) ? (string) ($addr['email'] ?? '') : (string) $addr);
            if ($addr !== '') {
                $to[] = $addr;
            }
        }
        if ($to === []) {
            return ['error' => 'no recipient'];
        }
        if (count($to) > self::MAX_RECIPIENTS) {
            return ['error' => 'more than ' . self::MAX_RECIPIENTS . ' recipients in one message'];
        }

        $cmpId      = isset($d['cmp_id']) && (int) $d['cmp_id'] > 0 ? (int) $d['cmp_id'] : null;
        $fromDomain = strtolower(trim((string) ($d['from_domain'] ?? '')));
        $category   = strtolower(trim((string) ($d['category'] ?? '')));
        if ($category === '') {
            $category = $cmpId !== null && $fromDomain !== '' ? 'business' : 'transactional';
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            return ['error' => "category {$category} is not sent by Engage (transactional or business only)"];
        }

        $payload = [
            'category'  => $category,
            'to'        => $to,
            'subject'   => (string) ($msg['subject'] ?? ''),
            'body_text' => (string) ($msg['text'] ?? ''),
        ];
        $html = (string) ($msg['html'] ?? '');
        if (trim($html) !== '') {
            $payload['body_html'] = $html;
        }
        $fromName = trim((string) ($msg['from_name'] ?? ''));
        if ($fromName !== '') {
            $payload['from_name'] = $fromName;
        }
        $replyTo = trim((string) ($msg['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
            $payload['reply_to'] = $replyTo;
        }
        $templateRef = trim((string) ($d['template_ref'] ?? ''));
        if ($templateRef !== '') {
            $payload['template_ref'] = substr($templateRef, 0, 120);
        }
        if ($cmpId !== null) {
            $payload['cmp_id'] = $cmpId;
        }

        if ($category === 'business') {
            if ($cmpId === null) {
                return ['error' => 'business mail needs cmp_id (the company it is sent for)'];
            }
            if (preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $fromDomain) !== 1) {
                return ['error' => "business mail needs from_domain, one of the company's verified domains"];
            }
            $payload['from_domain'] = $fromDomain;
            $local = strtolower(trim((string) ($d['from_local_part'] ?? '')));
            if ($local !== '') {
                if (preg_match('/^[a-z0-9._-]{1,40}$/', $local) !== 1) {
                    return ['error' => 'from_local_part may use a-z 0-9 . _ - (1–40 characters)'];
                }
                $payload['from_local_part'] = $local;
            }
        }

        $sendAt = trim((string) ($d['send_at'] ?? ''));
        if ($sendAt !== '') {
            $payload['send_at'] = $sendAt;
        }

        $attachments = is_array($d['attachments'] ?? null) ? array_values($d['attachments']) : [];
        if (count($attachments) > self::MAX_ATTACHMENTS) {
            return ['error' => 'more than ' . self::MAX_ATTACHMENTS . ' attachments'];
        }
        foreach ($attachments as $a) {
            $name = basename(trim((string) (is_array($a) ? ($a['filename'] ?? '') : '')));
            if ($name === '') {
                return ['error' => 'each attachment needs a filename'];
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                return ['error' => "attachments of type .{$ext} are not sent (executable)"];
            }
            $b64 = isset($a['content_base64']) ? (string) $a['content_base64'] : base64_encode((string) ($a['content'] ?? ''));
            $payload['attachments'][] = [
                'filename'       => $name,
                'content_type'   => trim((string) ($a['content_type'] ?? '')) ?: 'application/octet-stream',
                'content_base64' => $b64,
            ];
        }

        return ['payload' => $payload];
    }

    /**
     * Stable Idempotency-Key (8–120 of A-Z a-z 0-9 . _ : -, the alphabet the Email API keeps).
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $d delivery options (idempotency_key, resend)
     */
    public static function idempotencyKey(array $payload, array $d): string
    {
        $given = trim((string) ($d['idempotency_key'] ?? ''));
        if ($given !== '') {
            $key = 'engage.' . $given;

            return preg_match('/^[A-Za-z0-9._:\-]{8,120}$/', $key) === 1 ? $key : 'engage.k.' . hash('sha256', $given);
        }
        $basis = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!empty($d['resend'])) {
            return 'engage.resend.' . hash('sha256', $basis . '|' . bin2hex(random_bytes(16)));
        }

        return 'engage.m.' . $basis;
    }

    /** @param array<string,mixed> $json */
    private static function describeError(int $status, array $json, string $transportError): string
    {
        if ($status === 0) {
            return 'no answer from the AICOUNTLY Email API' . ($transportError !== '' ? ': ' . $transportError : '');
        }
        $err   = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code  = (string) ($err['code'] ?? '');
        $text  = (string) ($err['message'] ?? $json['message'] ?? '');
        $field = is_array($err['details'] ?? null) && isset($err['details']['field']) ? (string) $err['details']['field'] : '';
        $hint  = match (true) {
            $status === 409 => ' — Idempotency-Key reused for a different message',
            $status === 403 => ' — Engage is not permitted for this category in Email\'s DELIVERY_PRODUCTS, or the service key is wrong',
            $status === 401 => ' — AICOUNTLY_EMAIL_SERVICE_KEY was refused',
            default         => '',
        };

        return trim('HTTP ' . $status . ($code !== '' ? ' ' . $code : '') . ($text !== '' ? ': ' . $text : '') . ($field !== '' ? " (field {$field})" : '')) . $hint;
    }

    /** @param array<string,string> $headers lower-case names */
    private static function retryAfter(array $headers): ?int
    {
        $v = null;
        foreach ($headers as $k => $val) {
            if (strtolower((string) $k) === 'retry-after') {
                $v = trim((string) $val);
            }
        }
        if ($v === null || $v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            return (int) $v;
        }
        $t = strtotime($v);

        return $t === false ? null : max(0, $t - time());
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $payload
     */
    private function record(array $result, array $payload): void
    {
        if ($this->ledger === null) {
            return;
        }
        try {
            ($this->ledger)([
                'idempotency_key' => $result['idempotency_key'],
                'delivery_id'     => $result['id'],
                'category'        => $payload['category'],
                'cmp_id'          => $payload['cmp_id'] ?? null,
                'template_ref'    => $payload['template_ref'] ?? null,
                'recipients'      => $payload['to'],
                'status'          => $result['status'] ?? 'queued',
                'replayed'        => $result['replayed'],
            ]);
        } catch (\Throwable $e) {
            // Bookkeeping never turns an accepted message into a failure.
            if (function_exists('log_message')) {
                log_message('warning', '[AicountlyEmailTransport] delivery not recorded: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:string, headers:array<string,string>, error:string}
     */
    public static function httpPost(string $url, array $headers, string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'curl extension missing'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'curl_init failed'];
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        return ['status' => $raw === false ? 0 : $status, 'body' => is_string($raw) ? $raw : '', 'headers' => $respHeaders, 'error' => $err];
    }
}
