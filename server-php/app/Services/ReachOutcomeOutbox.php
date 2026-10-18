<?php
declare(strict_types=1);

namespace App\Services;

use App\Libraries\Seller\Clock;
use App\Libraries\Seller\Money;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Engage → Reach lead outcomes (contract "Reach ⇄ Engage"):
 *
 *   POST {REACH_API_BASE_URL}/v1/inbound/engage/lead-outcomes
 *   X-Engage-Token: {REACH_OUTCOME_TOKEN}            (Reach checks it against REACH_INBOUND_ENGAGE_TOKEN)
 *   {"events":[{event_id, lead_code, reach_lead_id|null, email_sha256|null, stage, sales_status, product,
 *               value_inr|null, occurred_at, seller_code|null, campaign_code|null, source_portal}]}
 *
 * Sent for leads that came from Reach (reach_lead_id set, source_type reach_campaign, or source_portal
 * reach.aicountly.org) — other leads mean nothing to Reach and are not shared with it.
 * Stages: qualified, demo (pipeline demo_required), proposal_sent, converted, lost — plus
 * "payment_confirmed" when a sales manager confirms a payment (value_inr = that payment).
 *
 * Same outbox pattern as ConsoleAuditOutbox: queued in engage_reach_outcome_outbox, delivered by
 * `php spark engage:reach-outcomes-deliver` (every 5 minutes) in batches of 50, back-off 2, 4, 8 … minutes
 * (at most 6 h), dead after 12 attempts. Reach is idempotent on event_id, so a retry never doubles an outcome.
 * Events are queued even while Reach is not configured, so nothing is lost; they wait as 'pending'.
 */
class ReachOutcomeOutbox
{
    public const PATH = '/v1/inbound/engage/lead-outcomes';
    public const MAX_ATTEMPTS = 12;
    public const BATCH = 50;
    public const STAGE_MAP = [
        'qualified'     => 'qualified',
        'demo_required' => 'demo',
        'proposal_sent' => 'proposal_sent',
        'converted'     => 'converted',
        'lost'          => 'lost',
    ];

    private string $baseUrl;
    private string $token;
    private ?BaseConnection $db;
    /** @var callable(string, list<string>, string): array{status:int, body:string|false|null, error?:string} */
    private $transport;

    public function __construct(?callable $transport = null, ?string $baseUrl = null, ?string $token = null, ?BaseConnection $db = null)
    {
        $this->baseUrl   = rtrim($baseUrl ?? trim((string) env('REACH_API_BASE_URL', '')), '/');
        $this->token     = $token ?? trim((string) env('REACH_OUTCOME_TOKEN', ''));
        $this->db        = $db;
        $this->transport = $transport ?? [self::class, 'curlPost'];
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && strlen($this->token) >= 32;
    }

    public function status(): string
    {
        if ($this->isConfigured()) {
            return 'ok';
        }

        return $this->baseUrl === '' ? 'REACH_API_BASE_URL not set — lead outcomes are queued but not sent'
            : 'REACH_OUTCOME_TOKEN missing or shorter than 32 characters — lead outcomes are queued but not sent';
    }

    public static function isReachLead(array $lead): bool
    {
        return ! empty($lead['reach_lead_id']) || ($lead['source_type'] ?? '') === 'reach_campaign'
            || str_ends_with((string) ($lead['source_portal'] ?? ''), 'reach.aicountly.org');
    }

    /** Queue a stage-change outcome. Returns the event_id, or null when nothing is sent for it. */
    public function stageChanged(array $lead, string $engageStage, ?string $valueInr = null): ?string
    {
        $stage = self::STAGE_MAP[$engageStage] ?? null;
        if ($stage === null || ! self::isReachLead($lead)) {
            return null;
        }

        return $this->enqueue($lead, $stage, 'eng-' . $lead['id'] . '-' . $stage . '-' . bin2hex(random_bytes(8)), $valueInr);
    }

    /** Queue a payment-confirmed outcome (natural key: the payment). */
    public function paymentConfirmed(array $lead, array $payment): ?string
    {
        if (! self::isReachLead($lead)) {
            return null;
        }

        return $this->enqueue($lead, 'payment_confirmed', 'eng-pay-' . $payment['id'], Money::format(Money::toPaise((string) $payment['amount'])));
    }

    private function enqueue(array $lead, string $stage, string $eventId, ?string $valueInr): ?string
    {
        try {
            $db = $this->db();
            $sellerCode = null;
            if (! empty($lead['seller_id'])) {
                $sellerCode = $db->table('engage_sellers')->select('seller_code')->where('id', (int) $lead['seller_id'])->get()->getRow()->seller_code ?? null;
            }
            $email = strtolower(trim((string) ($lead['email'] ?? '')));
            $event = [
                'event_id'      => $eventId,
                'lead_code'     => (string) $lead['lead_code'],
                'reach_lead_id' => ! empty($lead['reach_lead_id']) ? (int) $lead['reach_lead_id'] : null,
                'email_sha256'  => $email !== '' ? hash('sha256', $email) : null,
                'stage'         => $stage,
                'sales_status'  => (string) ($lead['sales_status'] ?? 'open'),
                'product'       => $lead['interested_product'] ?? null,
                'value_inr'     => $valueInr,
                'occurred_at'   => gmdate('Y-m-d\TH:i:s\Z'),
                'seller_code'   => $sellerCode,
                'campaign_code' => ($lead['source_type'] ?? '') === 'reach_campaign' ? ($lead['source_campaign'] ?? null) : null,
                'source_portal' => 'engage.aicountly.org',
            ];
            $db->query(
                'INSERT INTO engage_reach_outcome_outbox (event_id, lead_id, stage, event) VALUES (?, ?, ?, CAST(? AS JSONB)) ON CONFLICT (event_id) DO NOTHING',
                [$eventId, (int) $lead['id'], $stage, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]
            );

            return $eventId;
        } catch (\Throwable $e) {
            log_message('error', "Reach outcome {$stage} for lead {$lead['id']} not queued: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Deliver due events.
     *
     * @return array{batches:int, delivered:int, rejected:int, retry:int, dead:int}
     */
    public function deliverDue(int $maxBatches = 20): array
    {
        $out = ['batches' => 0, 'delivered' => 0, 'rejected' => 0, 'retry' => 0, 'dead' => 0];
        for ($b = 0; $b < $maxBatches; $b++) {
            $rows = $this->db()->query(
                "UPDATE engage_reach_outcome_outbox SET next_attempt_at = NOW() + INTERVAL '10 minutes'
                  WHERE id IN (SELECT id FROM engage_reach_outcome_outbox
                                WHERE status = 'pending' AND next_attempt_at <= NOW()
                                ORDER BY id LIMIT " . self::BATCH . ' FOR UPDATE SKIP LOCKED)
                  RETURNING id, event_id, attempts, event'
            )->getResultArray();
            if ($rows === []) {
                break;
            }
            usort($rows, static fn (array $x, array $y): int => (int) $x['id'] <=> (int) $y['id']);
            $events = array_map(static fn (array $r): array => json_decode((string) $r['event'], true) ?: ['event_id' => $r['event_id']], $rows);
            $counts = $this->apply($rows, $this->send($events));
            $out['batches']++;
            foreach ($counts as $k => $n) {
                $out[$k] += $n;
            }
            if ($counts['delivered'] + $counts['rejected'] === 0) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array{status:int, results:?array, accepted_all:bool, rejected_all:bool, error:string}
     */
    public function send(array $events): array
    {
        $body = (string) json_encode(['events' => array_values($events)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        try {
            $res = ($this->transport)($this->baseUrl . self::PATH, [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Engage-Token: ' . $this->token,
                'Idempotency-Key: ' . hash('sha256', implode(',', array_column($events, 'event_id'))),
                'X-Source: engage.aicountly.org',
            ], $body);
        } catch (\Throwable $e) {
            $res = ['status' => 0, 'body' => null, 'error' => $e->getMessage()];
        }
        $status = (int) ($res['status'] ?? 0);
        $raw = is_string($res['body'] ?? null) ? $res['body'] : null;
        $json = $raw !== null ? json_decode($raw, true) : null;
        $results = is_array($json['data']['results'] ?? null) ? $json['data']['results'] : (is_array($json['results'] ?? null) ? $json['results'] : null);
        $ok2xx = $status >= 200 && $status < 300 && (! is_array($json) || ($json['ok'] ?? true) !== false);
        $permanent = in_array($status, [400, 422], true);
        $error = (string) ($res['error'] ?? '');
        if ($error === '' && ! $ok2xx) {
            $error = 'http_status=' . $status . ($raw !== null && $raw !== '' ? ' ' . substr($raw, 0, 200) : '');
        }

        return [
            'status'       => $status,
            'results'      => $results,
            'accepted_all' => $ok2xx && $results === null,
            'rejected_all' => $permanent && $results === null,
            'error'        => $error,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{delivered:int, rejected:int, retry:int, dead:int}
     */
    public function apply(array $rows, array $res): array
    {
        $counts = ['delivered' => 0, 'rejected' => 0, 'retry' => 0, 'dead' => 0];
        $byId = [];
        foreach ($res['results'] ?? [] as $i => $r) {
            if (is_array($r)) {
                $key = isset($r['event_id']) ? (string) $r['event_id'] : (isset($r['index']) ? '#' . (int) $r['index'] : '#' . $i);
                $byId[$key] = $r;
            }
        }
        foreach (array_values($rows) as $i => $row) {
            $r = $byId[(string) $row['event_id']] ?? $byId['#' . $i] ?? null;
            $outcome = $res['accepted_all'] ? 'stored' : ($res['rejected_all'] ? 'rejected' : (is_array($r) ? (string) ($r['status'] ?? '') : ''));
            if (in_array($outcome, ['stored', 'duplicate', 'accepted', 'ok'], true)) {
                $this->db()->query(
                    "UPDATE engage_reach_outcome_outbox SET status = 'delivered', delivered_at = NOW(), attempts = attempts + 1,
                            last_http_status = ?, last_error = NULL WHERE id = ?",
                    [$res['status'], $row['id']]
                );
                $counts['delivered']++;

                continue;
            }
            if ($outcome === 'rejected') {
                $err = 'rejected: ' . (string) (is_array($r) ? ($r['error'] ?? 'unknown') : $res['error']);
                $this->db()->query(
                    "UPDATE engage_reach_outcome_outbox SET status = 'rejected', attempts = attempts + 1, last_http_status = ?, last_error = ? WHERE id = ?",
                    [$res['status'], substr($err, 0, 1000), $row['id']]
                );
                log_message('error', "Reach outcome {$row['event_id']} {$err} (not retried)");
                $counts['rejected']++;

                continue;
            }
            $attempts = (int) $row['attempts'] + 1;
            $dead = $attempts >= self::MAX_ATTEMPTS;
            $delay = min(360, 2 ** min($attempts, 9));
            $err = $res['results'] === null ? $res['error'] : 'no result for this event';
            $this->db()->query(
                'UPDATE engage_reach_outcome_outbox SET status = ?, attempts = ?, last_http_status = ?, last_error = ?,
                        next_attempt_at = NOW() + make_interval(mins => ?) WHERE id = ?',
                [$dead ? 'dead' : 'pending', $attempts, $res['status'], substr($err, 0, 1000), $delay, $row['id']]
            );
            log_message($dead ? 'critical' : 'warning', sprintf('Reach outcome %s failed (status %d): %s — %s', $row['event_id'], $res['status'], substr($err, 0, 200),
                $dead ? "dead after {$attempts} attempts" : "attempt {$attempts}, retry in {$delay} min"));
            $counts[$dead ? 'dead' : 'retry']++;
        }

        return $counts;
    }

    /** @return array<string,int> counts per status */
    public function summary(): array
    {
        $out = ['pending' => 0, 'delivered' => 0, 'rejected' => 0, 'dead' => 0];
        foreach ($this->db()->query('SELECT status, COUNT(*) AS n FROM engage_reach_outcome_outbox GROUP BY status')->getResultArray() as $r) {
            $out[$r['status']] = (int) $r['n'];
        }

        return $out;
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /** @return array{status:int, body:string|false, error:string} */
    private static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'body' => $raw, 'error' => $err];
    }
}
