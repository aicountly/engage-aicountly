<?php
declare(strict_types=1);

/**
 * HttpApiTransport — the Itwalk / Infobip-style transactional e-mail HTTP API, as one self-contained
 * file (no framework, no Composer, no namespace; needs the curl extension unless an HTTP function is
 * injected). This is the logic Console's Mailer and Flow have always used for EMAIL_API_KEY /
 * EMAIL_API_URL / EMAIL_API_FORMAT, lifted out so a provider from Console's email registry can use it
 * (docs/EMAIL_PROVIDERS.md). Products may copy it verbatim.
 *
 * Request: POST to api_url with `Authorization: App <key>`, fields from, to, replyTo, subject, html,
 * text (and track=false for multipart). api_format decides the encoding, exactly as before:
 *   multipart       multipart/form-data to api_url
 *   json            JSON to api_url, with …/email/1/send rewritten to …/email/3/send
 *   auto            …/email/1/send (Itwalk e.api.itwalk.in, which answers JSON with 415): multipart only;
 *                   any other URL: JSON first, then multipart
 *   anything else   (e.g. json_multipart, or the historical "brevo"): JSON first, then multipart
 *
 * send() answers ['ok', 'message_id', 'error', 'code' (HTTP status), 'transient', 'response', 'mode'
 * ('json' | 'multipart'), 'url', 'accepted' (messages the API accepted)]. ok is true only when the
 * API accepted the message (2xx and no rejection in the body). The key never appears in the answer.
 */
final class HttpApiTransport
{
    public const DEFAULT_API_URL = 'https://e.api.itwalk.in/email/1/send';
    public const FORMATS = ['auto', 'json', 'multipart', 'json_multipart'];

    /** @var callable(string $url, string $mode, list<string> $headers, string|array $body, int $timeout): array{response: string, http_code: int, curl_error: string} */
    private $http;

    /**
     * @param callable|null $http injectable HTTP POST for tests; default curl
     */
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $format = 'auto',
        ?callable $http = null,
        private readonly int $timeout = 30,
    ) {
        $this->http = $http ?? self::curlPost(...);
    }

    /**
     * @param array{from_email: string, from_name?: string, to: string|list<string|array{email: string, name?: string}>,
     *              reply_to?: string, subject: string, html?: string, text?: string} $message
     * @return array{ok: bool, message_id: ?string, error: ?string, code: int, transient: bool, response: string, mode: string, url: string, accepted: int}
     */
    public function send(array $message): array
    {
        $fail = fn (string $error, int $code = 0, bool $transient = false, string $mode = '', string $url = ''): array => [
            'ok' => false, 'message_id' => null, 'error' => $this->redact($error), 'code' => $code, 'transient' => $transient,
            'response' => '', 'mode' => $mode, 'url' => $url !== '' ? $url : $this->apiUrl, 'accepted' => 0,
        ];

        if (trim($this->apiKey) === '') {
            return $fail('no API key is configured');
        }
        $apiUrl = trim($this->apiUrl) !== '' ? trim($this->apiUrl) : self::DEFAULT_API_URL;
        $recipient = self::recipients($message['to'] ?? '');
        if ($recipient === '') {
            return $fail('invalid recipient e-mail address');
        }
        $senderEmail = trim((string) ($message['from_email'] ?? ''));
        if ($senderEmail === '' || filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
            return $fail('invalid sender e-mail address');
        }
        $senderName = trim(preg_replace('/[\r\n]+/', ' ', (string) ($message['from_name'] ?? '')) ?? '');
        $replyTo = trim((string) ($message['reply_to'] ?? ''));
        $html = (string) ($message['html'] ?? '');
        $text = (string) ($message['text'] ?? '');
        if ($text === '' && $html !== '') {
            $text = self::htmlToPlainText($html);
        }

        $payload = [
            'from'    => $senderName !== '' ? "{$senderName} <{$senderEmail}>" : $senderEmail,
            'to'      => $recipient,
            'replyTo' => $replyTo !== '' ? $replyTo : $senderEmail,
            'subject' => preg_replace('/[\r\n]+/', ' ', (string) ($message['subject'] ?? '')) ?? '',
            'html'    => $html,
            'text'    => $text,
        ];

        $last = $fail('no attempt was made');
        foreach (self::attempts($apiUrl, $this->format) as $attempt) {
            $headers = ['Accept: application/json', 'Authorization: App ' . $this->apiKey];
            if ($attempt['mode'] === 'json') {
                $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($body === false) {
                    $last = $fail('the message could not be encoded as JSON', 0, false, 'json', $attempt['url']);
                    continue;
                }
                $headers[] = 'Content-Type: application/json';
            } else {
                $body = $payload + ['track' => 'false'];
            }

            $result = ($this->http)($attempt['url'], $attempt['mode'], $headers, $body, $this->timeout);
            $httpCode = (int) ($result['http_code'] ?? 0);
            $response = (string) ($result['response'] ?? '');
            $curlError = (string) ($result['curl_error'] ?? '');
            if ($curlError !== '') {
                $last = $fail('curl: ' . $curlError, $httpCode, true, $attempt['mode'], $attempt['url']);
                continue;
            }

            $parsed = self::parseResponse($response, $httpCode);
            if ($parsed['ok']) {
                $answer = json_decode($response, true);
                $accepted = is_array($answer) && is_array($answer['messages'] ?? null) ? count($answer['messages']) : 0;

                return [
                    'ok'         => true,
                    'message_id' => $parsed['message_id'] !== '' ? $parsed['message_id'] : null,
                    'error'      => null,
                    'code'       => $httpCode,
                    'transient'  => false,
                    'response'   => $this->redact(substr($response, 0, 800)),
                    'mode'       => $attempt['mode'],
                    'url'        => $attempt['url'],
                    'accepted'   => $accepted > 0 ? $accepted : 1,
                ];
            }
            $last = [
                'ok' => false, 'message_id' => null, 'error' => $this->redact($parsed['reason']), 'code' => $httpCode,
                'transient' => $httpCode === 0 || $httpCode === 429 || $httpCode >= 500,
                'response' => $this->redact(substr($response, 0, 800)), 'mode' => $attempt['mode'], 'url' => $attempt['url'], 'accepted' => 0,
            ];
        }

        return $last;
    }

    /** @return list<array{url: string, mode: string}> */
    public static function attempts(string $apiUrl, string $format): array
    {
        $format = strtolower(trim($format));
        $usesV1 = preg_match('#/email/1/send$#', $apiUrl) === 1;
        if ($format === 'auto' && $usesV1) {
            // Itwalk e.api.itwalk.in/email/1/send accepts multipart only (JSON → HTTP 415).
            return [['url' => $apiUrl, 'mode' => 'multipart']];
        }
        $jsonUrl = $usesV1 ? (preg_replace('#/email/1/send$#', '/email/3/send', $apiUrl) ?? $apiUrl) : $apiUrl;

        return match ($format) {
            'json'      => [['url' => $jsonUrl, 'mode' => 'json']],
            'multipart' => [['url' => $apiUrl, 'mode' => 'multipart']],
            default     => [
                ['url' => $jsonUrl, 'mode' => 'json'],
                ['url' => $apiUrl, 'mode' => 'multipart'],
            ],
        };
    }

    /** @return array{ok: bool, reason: string, message_id: string} */
    public static function parseResponse(string $response, int $httpCode): array
    {
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'reason' => 'HTTP ' . $httpCode, 'message_id' => ''];
        }
        if ($response === '') {
            return ['ok' => false, 'reason' => 'Empty response body from email gateway', 'message_id' => ''];
        }
        $json = json_decode($response, true);
        if (!is_array($json)) {
            $trimmed = strtolower(trim($response));
            if ($trimmed !== '' && !str_contains($trimmed, 'error') && !str_contains($trimmed, 'fail')) {
                return ['ok' => true, 'reason' => 'non_json_response', 'message_id' => ''];
            }

            return ['ok' => false, 'reason' => 'Unexpected non-JSON response from email gateway', 'message_id' => ''];
        }
        if (isset($json['requestError']) && is_array($json['requestError'])) {
            return ['ok' => false, 'reason' => (string) ($json['requestError']['serviceException']['text'] ?? $json['requestError']['message'] ?? 'request rejected'), 'message_id' => ''];
        }
        if (!empty($json['error']) || !empty($json['errors'])) {
            $text = is_string($json['error'] ?? null) ? (string) $json['error'] : (string) json_encode($json['errors'] ?? $json['error']);

            return ['ok' => false, 'reason' => $text !== '' ? $text : 'gateway error', 'message_id' => ''];
        }

        $messageId = trim((string) ($json['bulkId'] ?? $json['messageId'] ?? ''));
        $messages = is_array($json['messages'] ?? null) ? $json['messages'] : [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            if ($messageId === '') {
                $messageId = (string) ($message['messageId'] ?? $message['id'] ?? '');
            }
            $status = is_array($message['status'] ?? null) ? $message['status'] : [];
            $groupName = strtoupper((string) ($status['groupName'] ?? ''));
            $statusName = strtoupper((string) ($status['name'] ?? ''));
            $description = (string) ($status['description'] ?? '');
            if (str_contains($groupName, 'REJECT') || str_contains($statusName, 'REJECT') || str_contains($description, 'rejected')) {
                return ['ok' => false, 'reason' => $description !== '' ? $description : ($statusName !== '' ? $statusName : 'message rejected'), 'message_id' => $messageId];
            }
        }
        if ($messageId !== '' || $messages !== []) {
            return ['ok' => true, 'reason' => 'accepted', 'message_id' => $messageId];
        }

        return ['ok' => false, 'reason' => 'Gateway returned no message id (send may not have been queued)', 'message_id' => ''];
    }

    public static function htmlToPlainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /** @param string|list<string|array{email: string, name?: string}> $to comma-joined valid addresses, '' if any is invalid */
    private static function recipients(mixed $to): string
    {
        $list = is_array($to) ? $to : explode(',', (string) $to);
        $emails = [];
        foreach ($list as $entry) {
            $email = trim(is_array($entry) ? (string) ($entry['email'] ?? '') : (string) $entry);
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return '';
            }
            $emails[] = $email;
        }

        return implode(',', $emails);
    }

    private function redact(string $text): string
    {
        return $this->apiKey !== '' ? str_replace($this->apiKey, '****', $text) : $text;
    }

    /**
     * @param list<string> $headers
     * @param string|array<string, string> $body
     * @return array{response: string, http_code: int, curl_error: string}
     */
    private static function curlPost(string $url, string $mode, array $headers, string|array $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['response' => '', 'http_code' => 0, 'curl_error' => 'PHP curl extension is not enabled'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['response' => '', 'http_code' => 0, 'curl_error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['response' => is_string($response) ? $response : '', 'http_code' => $httpCode, 'curl_error' => $error];
    }
}
