<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Engage's central audit to Console (console-react-app AUTH_INTEGRATION.md "Central audit"):
 *
 *   POST {CONSOLE_API_URL}/portal/audit
 *   Authorization: Bearer {CONSOLE_API_TOKEN}       (Engage's own Console key, capability audit.write)
 *   {"events": [{action, external_id, app_code: "engage", entity_type, entity_id, actor_email,
 *                occurred_at, ip, user_agent, metadata}]}
 *
 * Engage used to post its audit fan-out to Console's portal/bot-audit as {portal, event, …};
 * Console's ingest requires event_type, so every one was refused (422). Now each event is queued
 * in engage_console_audit_outbox (migration 2026-10-14-000081), sent at once, and retried with
 * back-off by `php spark engage:console-audit-deliver` until Console answers stored / duplicate
 * (delivered, event body cleared), rejected (kept, not retried), or 12 attempts fail (dead).
 * Console de-duplicates on external_id ('engage:audit_logs:<id>'), so a retry never doubles an
 * event. Every failure is logged; nothing here throws to the caller.
 */
class ConsoleAuditOutbox
{
    public const APP_CODE     = 'engage';
    public const SOURCE       = 'engage.aicountly.org';
    public const PATH         = '/portal/audit';
    public const MAX_ATTEMPTS = 12;
    public const BATCH        = 100;

    private string $baseUrl;
    private string $token;
    private ?BaseConnection $db;

    /** @var callable(string, list<string>, string): array{status:int, body:string|false|null, error?:string} */
    private $transport;

    public function __construct(?callable $transport = null, ?string $baseUrl = null, ?string $token = null, ?BaseConnection $db = null)
    {
        $base = $baseUrl ?? (trim((string) env('CONSOLE_API_URL', '')) ?: trim((string) env('CONSOLE_API_BASE_URL', '')));
        $this->baseUrl   = rtrim($base, '/');
        $this->token     = $token ?? trim((string) env('CONSOLE_API_TOKEN', ''));
        $this->db        = $db;
        $this->transport = $transport ?? [self::class, 'curlPost'];
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * Queue one event and try to deliver it now. 'delivered' | 'pending' | 'rejected' | 'dead' |
     * 'duplicate' (already queued) | 'not_configured' | 'error'.
     *
     * @param array<string, mixed> $row the engage_audit_logs row AuditService wrote
     */
    public function record(string $event, array $row, ?string $externalId = null): string
    {
        try {
            if (! $this->isConfigured()) {
                log_message('warning', "Console audit {$event} not sent: CONSOLE_API_URL / CONSOLE_API_TOKEN not set.");

                return 'not_configured';
            }
            $externalId ??= 'engage:event:' . bin2hex(random_bytes(12));
            $payload = self::toEvent($event, $row, $externalId);

            $queued = $this->db()->query(
                'INSERT INTO engage_console_audit_outbox (external_id, event) VALUES (?, CAST(? AS JSONB))
                 ON CONFLICT (external_id) DO NOTHING RETURNING id, external_id, attempts',
                [$externalId, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]
            )->getRowArray();
            if ($queued === null) {
                return 'duplicate';
            }

            $counts = $this->apply([$queued], $this->send([$payload]));
            foreach (['delivered', 'rejected', 'dead'] as $s) {
                if ($counts[$s] > 0) {
                    return $s;
                }
            }

            return 'pending';
        } catch (\Throwable $e) {
            log_message('error', "Console audit {$event} failed: " . $e->getMessage());

            return 'error';
        }
    }

    /**
     * Deliver what is due (php spark engage:console-audit-deliver).
     *
     * @return array{batches:int, delivered:int, rejected:int, retry:int, dead:int}
     */
    public function deliverDue(int $maxBatches = 20): array
    {
        $out = ['batches' => 0, 'delivered' => 0, 'rejected' => 0, 'retry' => 0, 'dead' => 0];
        for ($b = 0; $b < $maxBatches; $b++) {
            $rows = $this->db()->query(
                "UPDATE engage_console_audit_outbox SET next_attempt_at = NOW() + INTERVAL '10 minutes'
                  WHERE id IN (SELECT id FROM engage_console_audit_outbox
                                WHERE status = 'pending' AND next_attempt_at <= NOW()
                                ORDER BY id LIMIT " . self::BATCH . ' FOR UPDATE SKIP LOCKED)
                  RETURNING id, external_id, attempts, event'
            )->getResultArray();
            if ($rows === []) {
                break;
            }
            usort($rows, static fn (array $x, array $y): int => (int) $x['id'] <=> (int) $y['id']);
            $events = array_map(static fn (array $r): array => json_decode((string) $r['event'], true) ?: ['external_id' => $r['external_id']], $rows);
            $counts = $this->apply($rows, $this->send($events));
            $out['batches']++;
            foreach ($counts as $k => $n) {
                $out[$k] += $n;
            }
            if ($counts['delivered'] + $counts['rejected'] === 0) {
                break; // Console is not taking anything now; the next run retries.
            }
        }

        return $out;
    }

    /**
     * An engage_audit_logs row as a Console audit event (subject → entity; actor_role,
     * actor_id and the row's metadata in metadata).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toEvent(string $event, array $row, string $externalId): array
    {
        $meta = ['source' => self::SOURCE];
        foreach (['actor_id', 'actor_role'] as $k) {
            if (($row[$k] ?? null) !== null) {
                $meta[$k] = $row[$k];
            }
        }
        if (($row['metadata'] ?? null) !== null) {
            $meta['metadata'] = $row['metadata'];
        }
        $occurred = isset($row['created_at']) && strtotime((string) $row['created_at']) !== false
            ? gmdate('c', (int) strtotime((string) $row['created_at'])) : gmdate('c');

        return [
            'action'      => $event,
            'external_id' => $externalId,
            'app_code'    => self::APP_CODE,
            'entity_type' => isset($row['subject_kind']) ? (string) $row['subject_kind'] : null,
            'entity_id'   => isset($row['subject_id']) ? (string) $row['subject_id'] : null,
            'actor_email' => isset($row['actor_email']) ? (string) $row['actor_email'] : null,
            'occurred_at' => $occurred,
            'ip'          => isset($row['ip_address']) ? (string) $row['ip_address'] : null,
            'user_agent'  => isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            'metadata'    => $meta,
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{status:int, results:list<array<string,mixed>>|null, error:string}
     */
    public function send(array $events): array
    {
        $body = (string) json_encode(['events' => array_values($events)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        try {
            $res = ($this->transport)($this->baseUrl . self::PATH, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
                'X-Source: ' . self::SOURCE,
            ], $body);
        } catch (\Throwable $e) {
            $res = ['status' => 0, 'body' => null, 'error' => $e->getMessage()];
        }
        $status = (int) ($res['status'] ?? 0);
        $raw = is_string($res['body'] ?? null) ? $res['body'] : null;
        $json = $raw !== null ? json_decode($raw, true) : null;
        $results = $status === 200 && is_array($json['data']['results'] ?? null) ? $json['data']['results'] : null;
        $error = (string) ($res['error'] ?? '');
        if ($results === null && $error === '') {
            $error = 'http_status=' . $status . ($raw !== null && $raw !== '' ? ' ' . substr($raw, 0, 200) : '');
        }

        return ['status' => $status, 'results' => $results, 'error' => $error];
    }

    /**
     * Record Console's answer on the outbox rows, in the order they were sent.
     *
     * @param list<array<string, mixed>> $rows id, external_id, attempts
     * @return array{delivered:int, rejected:int, retry:int, dead:int}
     */
    public function apply(array $rows, array $res): array
    {
        $counts = ['delivered' => 0, 'rejected' => 0, 'retry' => 0, 'dead' => 0];
        $byIndex = [];
        foreach ($res['results'] ?? [] as $r) {
            if (isset($r['index'])) {
                $byIndex[(int) $r['index']] = $r;
            }
        }

        foreach (array_values($rows) as $i => $row) {
            $r = $byIndex[$i] ?? null;
            $outcome = is_array($r) ? (string) ($r['status'] ?? '') : '';
            if ($outcome === 'stored' || $outcome === 'duplicate') {
                $this->db()->query(
                    "UPDATE engage_console_audit_outbox SET status = 'delivered', event = NULL, delivered_at = NOW(), attempts = attempts + 1,
                            last_http_status = ?, last_error = NULL, console_audit_id = ? WHERE id = ?",
                    [$res['status'], isset($r['id']) ? (int) $r['id'] : null, $row['id']]
                );
                $counts['delivered']++;

                continue;
            }
            if ($outcome === 'rejected') {
                $err = 'rejected: ' . (string) ($r['error'] ?? 'unknown');
                $this->db()->query(
                    "UPDATE engage_console_audit_outbox SET status = 'rejected', attempts = attempts + 1, last_http_status = ?, last_error = ? WHERE id = ?",
                    [$res['status'], $err, $row['id']]
                );
                log_message('error', "Console audit {$row['external_id']} {$err} (not retried)");
                $counts['rejected']++;

                continue;
            }

            $attempts = (int) $row['attempts'] + 1;
            $dead = $attempts >= self::MAX_ATTEMPTS;
            $delay = min(360, 2 ** min($attempts, 9));
            $err = $res['results'] === null ? $res['error'] : 'no result for this event';
            $this->db()->query(
                'UPDATE engage_console_audit_outbox SET status = ?, attempts = ?, last_http_status = ?, last_error = ?,
                        next_attempt_at = NOW() + make_interval(mins => ?) WHERE id = ?',
                [$dead ? 'dead' : 'pending', $attempts, $res['status'], substr($err, 0, 1000), $delay, $row['id']]
            );
            log_message($dead ? 'critical' : 'warning', sprintf('Console audit %s failed (status %d): %s — %s', $row['external_id'], $res['status'], substr($err, 0, 200),
                $dead ? "dead after {$attempts} attempts" : "attempt {$attempts}, retry in {$delay} min"));
            $counts[$dead ? 'dead' : 'retry']++;
        }

        return $counts;
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
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'body' => $raw, 'error' => $err];
    }
}
