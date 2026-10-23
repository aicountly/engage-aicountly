<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\AicS2s\AicS2sSignature;
use App\Libraries\AicS2s\ManageClient;
use App\Libraries\Licensing\ManageAppRegistry;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Engage → Manage entitlement push (contract §3.3):
 *
 *   POST {MANAGE_API_BASE_URL}/v1/inbound/engage/entitlements      AIC-S2S v1, X-AIC-Client: engage
 *   {event_id, holder_uuid, app_key, seq, plan{code,label,offer_code,offer_version,licence_type_code,lifecycle_kind,
 *    is_default}|null, status, licence_ref, starts_at, ends_at, seats_total, entitlements[], occurred_at}
 *
 * enqueue() runs INSIDE the licence write's transaction (LicenceService, ProvisioningService): for every app key the
 * change touches it projects the subject's full state (EntitlementProjector) and, when it differs from the last
 * state queued for that (subject, app), inserts an outbox row with the next per-subject seq (counter row locked in
 * the same transaction — so seq order is commit order). A rolled-back licence write leaves no outbox row.
 *
 * deliverDue() (php spark engage:entitlements-push, every minute) sends the oldest pending row of each subject only
 * (strict seq order per holder); a pending row with a newer pending state of the same (subject, app) is `skipped`.
 *   2xx                          delivered (Manage's applied / unchanged / stale are all success)
 *   400, 404, 409, 410, 422      rejected — not retried; shown on /health and Licensing → Manage sync
 *   0, 401, 403, 408, 429, 5xx   retried: 2, 4, 8 … minutes (at most 6 h); dead after 12 attempts
 */
class EntitlementOutbox
{
    public const MAX_ATTEMPTS = 12;
    public const TERMINAL = [400, 404, 409, 410, 422];

    private ManageClient $client;

    public function __construct(private ?BaseConnection $db = null, ?ManageClient $client = null)
    {
        $this->client = $client ?? new ManageClient();
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    public function client(): ManageClient
    {
        return $this->client;
    }

    // ------------------------------------------------------------------ enqueue (inside the caller's transaction)

    /**
     * Queue the current state of (subject, app) for each app key. Returns the outbox rows written.
     *
     * @param list<string> $appKeys
     * @return list<array{event_id:string, seq:int, app_key:string}>
     */
    public function enqueue(?string $holderUuid, ?int $cmpId, array $appKeys, string $reason, ?string $licenceRef = null, bool $force = false): array
    {
        $holderUuid = $holderUuid !== null && $holderUuid !== '' ? $holderUuid : null;
        if ($holderUuid === null && ($cmpId === null || $cmpId < 1)) {
            return [];
        }
        $keys = [];
        foreach ($appKeys as $k) {
            $k = ManageAppRegistry::fold((string) $k);
            if ($k !== '' && ! in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }
        if ($keys === []) {
            return [];
        }
        sort($keys);
        $projector = new EntitlementProjector($this->db());
        $licences = $projector->licencesOf($holderUuid, $cmpId);
        $subject = EntitlementProjector::subjectKey($holderUuid, $cmpId);
        $written = [];
        foreach ($keys as $app) {
            $state = $projector->state($holderUuid, $cmpId, $app, $licences);
            $hash = hash('sha256', (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (! $force) {
                $last = $this->db()->query(
                    'SELECT state_hash, status FROM engage_entitlement_outbox WHERE subject_key = ? AND app_key = ? ORDER BY seq DESC LIMIT 1',
                    [$subject, $app]
                )->getRowArray();
                if ($last && $last['state_hash'] === $hash && in_array($last['status'], ['pending', 'delivered'], true)) {
                    continue;
                }
                if (! $last && $state['status'] === 'none') {
                    continue; // never told Manage anything about this app: nothing to withdraw
                }
            }
            $seq = (int) $this->db()->query(
                'INSERT INTO engage_entitlement_seq (subject_key, last_seq) VALUES (?, 1)
                 ON CONFLICT (subject_key) DO UPDATE SET last_seq = engage_entitlement_seq.last_seq + 1 RETURNING last_seq',
                [$subject]
            )->getRow()->last_seq;
            $eventId = AicS2sSignature::uuid4();
            $payload = ['event_id' => $eventId] + array_slice($state, 0, 2, true) + ['seq' => $seq] + array_slice($state, 2, null, true)
                + ['occurred_at' => gmdate('Y-m-d\TH:i:s\Z')];
            $this->db()->query(
                'INSERT INTO engage_entitlement_outbox (event_id, subject_key, holder_uuid, cmp_id, app_key, seq, reason, licence_ref, payload, state_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::json, ?)',
                [$eventId, $subject, $holderUuid, $holderUuid === null ? (int) $cmpId : null, $app, $seq, substr($reason, 0, 48),
                    $state['licence_ref'] ?? $licenceRef, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $hash]
            );
            $written[] = ['event_id' => $eventId, 'seq' => $seq, 'app_key' => $app];
        }

        return $written;
    }

    // ------------------------------------------------------------------ delivery

    /** @return array{sent:int, delivered:int, rejected:int, retry:int, dead:int, skipped:int} */
    public function deliverDue(int $maxRows = 500): array
    {
        $out = ['sent' => 0, 'delivered' => 0, 'rejected' => 0, 'retry' => 0, 'dead' => 0, 'skipped' => 0];
        if (! $this->client->isConfigured()) {
            return $out;
        }
        $seen = 0;
        while ($seen < $maxRows) {
            // Heads only: the lowest pending seq of each subject, when it is due. Claimed for 10 minutes so a
            // concurrent run (flock aside) never sends the same row twice.
            $rows = $this->db()->query(
                "UPDATE engage_entitlement_outbox SET next_attempt_at = NOW() + INTERVAL '10 minutes'
                  WHERE id IN (SELECT o.id FROM engage_entitlement_outbox o
                                WHERE o.status = 'pending' AND o.next_attempt_at <= NOW()
                                  AND NOT EXISTS (SELECT 1 FROM engage_entitlement_outbox p
                                                   WHERE p.subject_key = o.subject_key AND p.status = 'pending' AND p.seq < o.seq)
                                ORDER BY o.id LIMIT 50 FOR UPDATE SKIP LOCKED)
                  RETURNING id, event_id, subject_key, app_key, seq, attempts, payload"
            )->getResultArray();
            if ($rows === []) {
                break;
            }
            usort($rows, static fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);
            foreach ($rows as $row) {
                $seen++;
                $newer = $this->db()->query(
                    "SELECT 1 FROM engage_entitlement_outbox WHERE subject_key = ? AND app_key = ? AND status = 'pending' AND seq > ? LIMIT 1",
                    [$row['subject_key'], $row['app_key'], (int) $row['seq']]
                )->getRowArray();
                if ($newer) {
                    $this->db()->query("UPDATE engage_entitlement_outbox SET status = 'skipped', last_error = 'superseded by a newer state' WHERE id = ?", [(int) $row['id']]);
                    $out['skipped']++;

                    continue;
                }
                $out['sent']++;
                $out[$this->deliverRow($row)]++;
            }
        }

        return $out;
    }

    /** @return 'delivered'|'rejected'|'retry'|'dead' */
    private function deliverRow(array $row): string
    {
        $payload = json_decode((string) $row['payload'], true) ?: [];
        $res = $this->client->post(ManageClient::ENTITLEMENTS_PATH, $payload, (string) $row['event_id']);
        $status = $res['status'];
        $ok = $status >= 200 && $status < 300 && (! is_array($res['json']) || ($res['json']['success'] ?? true) !== false);
        if ($ok) {
            $this->db()->query(
                "UPDATE engage_entitlement_outbox SET status = 'delivered', delivered_at = NOW(), attempts = attempts + 1, last_http_status = ?, last_error = NULL WHERE id = ?",
                [$status, (int) $row['id']]
            );

            return 'delivered';
        }
        $code = is_array($res['json']['error'] ?? null) ? (string) ($res['json']['error']['code'] ?? '') : '';
        $err = $res['error'] !== '' ? $res['error'] : ('http_status=' . $status . ($code !== '' ? ' ' . $code : ($res['raw'] ? ' ' . substr($res['raw'], 0, 200) : '')));
        if (in_array($status, self::TERMINAL, true)) {
            $this->db()->query(
                "UPDATE engage_entitlement_outbox SET status = 'rejected', attempts = attempts + 1, last_http_status = ?, last_error = ? WHERE id = ?",
                [$status, substr($err, 0, 1000), (int) $row['id']]
            );
            log_message('critical', "Entitlement push {$row['event_id']} ({$row['subject_key']} {$row['app_key']} seq {$row['seq']}) rejected by Manage: " . substr($err, 0, 200));

            return 'rejected';
        }
        $attempts = (int) $row['attempts'] + 1;
        $dead = $attempts >= self::MAX_ATTEMPTS;
        $delay = min(360, 2 ** min($attempts, 9));
        $this->db()->query(
            'UPDATE engage_entitlement_outbox SET status = ?, attempts = ?, last_http_status = ?, last_error = ?, next_attempt_at = NOW() + make_interval(mins => ?) WHERE id = ?',
            [$dead ? 'dead' : 'pending', $attempts, $status, substr($err, 0, 1000), $delay, (int) $row['id']]
        );
        log_message($dead ? 'critical' : 'warning', sprintf('Entitlement push %s failed (status %d): %s — %s', $row['event_id'], $status, substr($err, 0, 200),
            $dead ? "dead after {$attempts} attempts" : "attempt {$attempts}, retry in {$delay} min"));

        return $dead ? 'dead' : 'retry';
    }

    // ------------------------------------------------------------------ staff / health

    /** Re-queue the CURRENT state of a row's (subject, app) as a new event (the old row stays as it was). */
    public function requeue(int $id): ?array
    {
        $row = $this->db()->query('SELECT * FROM engage_entitlement_outbox WHERE id = ?', [$id])->getRowArray();
        if (! $row) {
            return null;
        }
        $db = $this->db();

        return \App\Libraries\Licensing\LicensingSupport::strictTx($db, function () use ($db, $row) {
            $db->transBegin();
            try {
                $db->query('SELECT pg_advisory_xact_lock(hashtext(?))', ['provision-holder:' . ($row['holder_uuid'] ?? $row['subject_key'])]);
                $w = $this->enqueue($row['holder_uuid'], $row['cmp_id'] !== null ? (int) $row['cmp_id'] : null, [$row['app_key']], 'requeue', $row['licence_ref'], true);
                $db->transCommit();
            } catch (\Throwable $e) {
                $db->transRollback();
                throw $e;
            }

            return $w[0] ?? null;
        });
    }

    /** @return array<string,int> */
    public function summary(): array
    {
        $out = ['pending' => 0, 'delivered' => 0, 'rejected' => 0, 'dead' => 0, 'skipped' => 0];
        foreach ($this->db()->query('SELECT status, COUNT(*) AS n FROM engage_entitlement_outbox GROUP BY status')->getResultArray() as $r) {
            $out[$r['status']] = (int) $r['n'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function list(array $q = []): array
    {
        $where = [];
        $binds = [];
        if (! empty($q['status']) && in_array($q['status'], ['pending', 'delivered', 'rejected', 'dead', 'skipped'], true)) {
            $where[] = 'status = ?';
            $binds[] = $q['status'];
        }
        if (! empty($q['holder_uuid'])) {
            $where[] = 'holder_uuid = ?';
            $binds[] = (string) $q['holder_uuid'];
        }
        if (! empty($q['licence_ref'])) {
            $where[] = 'licence_ref = ?';
            $binds[] = (string) $q['licence_ref'];
        }
        $limit = isset($q['limit']) && ctype_digit((string) $q['limit']) ? max(1, min(500, (int) $q['limit'])) : 100;
        $rows = $this->db()->query('SELECT * FROM engage_entitlement_outbox' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT {$limit}", $binds)->getResultArray();

        return array_map(static fn (array $r) => [
            'id'               => (int) $r['id'],
            'event_id'         => $r['event_id'],
            'holder_uuid'      => $r['holder_uuid'],
            'cmp_id'           => $r['cmp_id'] !== null ? (int) $r['cmp_id'] : null,
            'app_key'          => $r['app_key'],
            'seq'              => (int) $r['seq'],
            'reason'           => $r['reason'],
            'licence_ref'      => $r['licence_ref'],
            'status'           => $r['status'],
            'attempts'         => (int) $r['attempts'],
            'next_attempt_at'  => \App\Libraries\Licensing\LicensingSupport::iso($r['next_attempt_at']),
            'last_http_status' => $r['last_http_status'] !== null ? (int) $r['last_http_status'] : null,
            'last_error'       => $r['last_error'],
            'created_at'       => \App\Libraries\Licensing\LicensingSupport::iso($r['created_at']),
            'delivered_at'     => \App\Libraries\Licensing\LicensingSupport::iso($r['delivered_at']),
            'payload'          => json_decode((string) $r['payload'], true),
        ], $rows);
    }

    /** /health line: dead-letter visibility. */
    public function status(): string
    {
        try {
            $s = $this->summary();
        } catch (\Throwable $e) {
            return 'unavailable (table missing? run php spark migrate)';
        }
        $parts = [];
        if ($s['dead'] > 0 || $s['rejected'] > 0) {
            $parts[] = "{$s['dead']} dead, {$s['rejected']} rejected — see Licensing → Manage sync";
        }
        $parts[] = "{$s['pending']} pending";
        $prefix = $s['dead'] > 0 || $s['rejected'] > 0 ? 'attention: ' : 'ok: ';

        return $prefix . implode('; ', $parts) . ($this->client->isConfigured() ? '' : ' — ' . $this->client->status());
    }
}
