<?php
declare(strict_types=1);

namespace App\Services\Tokens;

use App\Libraries\AicS2s\AicS2sSignature;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Collects the AI-balance warnings Pulse has worked out and delivers them.
 *
 * Pulse decides WHEN (it owns the balance and the burn rate); Engage decides HOW and
 * does it, because the message needs the holder's e-mail address and a Console
 * notification key and Engage holds both.
 *
 * Each alert is acknowledged individually. Delivered stays disarmed until a top-up;
 * not delivered is re-armed so the next sweep offers it again — a warning nobody
 * received must not be counted as one the customer has had.
 */
final class TokenAlertDelivery
{
    public const PATH = '/v1/inbound/engage/token-alerts';
    public const ACK_PATH = '/v1/inbound/engage/token-alerts/ack';

    /** Best channel first; the first that actually goes wins. */
    private const CHANNELS = [
        'credits.balance_low'       => ['email'],
        'credits.balance_critical'  => ['whatsapp', 'email'],
        'credits.balance_exhausted' => ['sms', 'email'],
        'credits.usage_spike'       => ['email'],
    ];

    public function __construct(
        private ?BaseConnection $db = null,
        private readonly ?Client $http = null,
        private readonly ?CustomerNotifier $notifier = null,
    ) {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /** @return array{collected:int, sent:int, skipped:int, configured:bool, error:?string} */
    public function run(int $limit = 200): array
    {
        $base = rtrim((string) env('PULSE_API_BASE_URL', ''), '/');
        $keyId = (string) env('ENGAGE_OUTBOUND_PULSE_KEY_ID', '');
        $secret = (string) env('ENGAGE_OUTBOUND_PULSE_SECRET', '');
        $env = (string) env('ENGAGE_S2S_ENVIRONMENT', 'production');
        $none = ['collected' => 0, 'sent' => 0, 'skipped' => 0, 'configured' => false, 'error' => null];

        if ($base === '' || $keyId === '' || strlen($secret) < 32 || ! CustomerNotifier::configured()) {
            return $none;
        }

        $query = 'limit=' . max(1, min(500, $limit));
        $alerts = $this->get($base, $keyId, $secret, $env, $query);
        if (! is_array($alerts)) {
            return ['collected' => 0, 'sent' => 0, 'skipped' => 0, 'configured' => true, 'error' => 'Pulse did not answer with a list of alerts.'];
        }

        $notifier = $this->notifier ?? new CustomerNotifier($this->db);
        $results = [];
        $sent = 0;
        foreach ($alerts as $a) {
            $holder = (string) ($a['holder_uuid'] ?? '');
            $trigger = (string) ($a['trigger_key'] ?? '');
            if ($holder === '' || ! isset(self::CHANNELS[$trigger])) {
                continue;
            }
            $contact = $notifier->contact($holder);
            $r = $notifier->sendFirstAvailable(
                $trigger,
                self::CHANNELS[$trigger],
                $contact,
                self::variables($trigger, $a, $contact),
                // One key per alert occasion: Pulse re-queues only after a top-up
                // re-arms it, so the queued_at makes each occasion distinct.
                'alert:' . $trigger . ':' . $holder . ':' . substr((string) ($a['queued_at'] ?? ''), 0, 19),
                'buddy'
            );
            $results[] = ['holder_uuid' => $holder, 'trigger_key' => $trigger, 'sent' => $r['sent'], 'error' => $r['reason'] ?? ''];
            $sent += $r['sent'] ? 1 : 0;
        }

        if ($results !== []) {
            $this->ack($base, $keyId, $secret, $env, $results);
        }

        return ['collected' => count($alerts), 'sent' => $sent, 'skipped' => count($results) - $sent, 'configured' => true, 'error' => null];
    }

    /**
     * The variables the templates declare. Everything is pre-formatted here rather
     * than in the body, so the wording can change without the numbers changing shape.
     *
     * @param array<string,mixed> $a
     * @param array{email:?string, name:?string, mobile:?string} $contact
     * @return array<string,scalar|null>
     */
    private static function variables(string $trigger, array $a, array $contact): array
    {
        $n = static fn ($v): string => number_format((float) $v);
        $days = (int) ($a['days_left'] ?? -1);
        $base = [
            // One AICOUNTLY sender id across the estate, so the body is the only place
            // the customer's own workspace can be named (Console DLT_POLICY.md).
            'company_name' => 'your Aicountly workspace',
            'holder_name'  => $contact['name'] ?? 'there',
            'buy_url'      => rtrim((string) env('MANAGE_APP_URL', 'https://manage.aicountly.com'), '/') . '/#/billing',
            'usage_url'    => rtrim((string) env('MANAGE_APP_URL', 'https://manage.aicountly.com'), '/') . '/#/commercial/ai',
        ];

        return match ($trigger) {
            'credits.balance_low', 'credits.balance_critical' => $base + [
                'tokens_left'  => $n($a['balance_at'] ?? 0),
                'tokens_limit' => $n($a['limit_at'] ?? 0),
                // "about 4" reads as an estimate, which it is; an unknown burn rate says
                // so rather than inventing a number.
                'days_left'    => $days >= 0 ? (string) $days : 'a few',
            ],
            'credits.balance_exhausted' => $base + [
                'allowance_note' => 'Any tokens included with your plan refill at the start of the next period.',
            ],
            'credits.usage_spike' => $base + [
                'tokens_today'   => $n($a['balance_at'] ?? 0),
                'tokens_average' => $n($a['limit_at'] ?? 0),
            ],
            default => $base,
        };
    }

    /** @return list<array<string,mixed>>|null */
    private function get(string $base, string $keyId, string $secret, string $env, string $query): ?array
    {
        $headers = AicS2sSignature::headers('engage', $keyId, $secret, $env, 'GET', self::PATH, $query, '', 'service');
        try {
            $res = ($this->http ?? new Client(['timeout' => 12]))->request('GET', $base . '/api' . self::PATH . '?' . $query, [
                'headers' => $headers, 'http_errors' => false,
            ]);
            $json = json_decode((string) $res->getBody(), true);
            $alerts = $json['data']['alerts'] ?? null;

            return is_array($alerts) ? array_values($alerts) : null;
        } catch (GuzzleException $e) {
            log_message('warning', 'token alerts: Pulse unreachable: {m}', ['m' => $e->getMessage()]);

            return null;
        }
    }

    /** @param list<array<string,mixed>> $results */
    private function ack(string $base, string $keyId, string $secret, string $env, array $results): void
    {
        $body = json_encode(['results' => $results], JSON_UNESCAPED_SLASHES);
        $headers = AicS2sSignature::headers('engage', $keyId, $secret, $env, 'POST', self::ACK_PATH, '', $body, 'service');
        try {
            ($this->http ?? new Client(['timeout' => 12]))->request('POST', $base . '/api' . self::ACK_PATH, [
                'headers' => $headers + ['Content-Type' => 'application/json'], 'body' => $body, 'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            // Unacknowledged alerts stay queued and are offered again next run. A
            // customer may get one duplicate; the idempotency key on the send makes
            // even that unlikely. Far better than losing the warning.
            log_message('warning', 'token alerts: could not acknowledge: {m}', ['m' => $e->getMessage()]);
        }
    }
}
