<?php
declare(strict_types=1);
namespace App\Services\Licensing;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/** Only the Email backend, after dual identity proof, may call this service. */
final class EmailAccountLinks
{
    public function __construct(private ?BaseConnection $db = null) { $this->db ??= Database::connect(); }

    /** Caller holds a transaction. Email purchases cannot recreate a child billing identity. */
    public function canonicalHolder(string $holder, string $product): string
    {
        if ($product !== 'email' || $holder === '') return $holder;
        $this->db->query('SELECT pg_advisory_xact_lock(79432, 2)');
        $row = $this->db->query('SELECT main_subject FROM engage_email_account_links WHERE source_subject = ?', [$holder])->getRowArray();
        return $row['main_subject'] ?? $holder;
    }

    public function process(array $in, bool $commit): array
    {
        foreach (['link_id','source_subject','source_realm','main_subject'] as $key) {
            if (!is_string($in[$key] ?? null)) return $this->failure(422, 'Invalid account link.');
        }
        if (!preg_match('/\A[a-f0-9]{32}\z/', $in['link_id'])
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $in['source_subject'])
            || !preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $in['main_subject'])
            || !in_array($in['source_realm'], ['personal','business'], true)
            || ($in['source_realm'] === 'personal') !== str_starts_with($in['source_subject'], 'mid_')
            || $in['source_subject'] === $in['main_subject']) return $this->failure(422, 'Invalid account identities.');
        $db = $this->db;
        $db->transBegin();
        try {
            // Shared by every consolidation; prevents A→B and B→A racing.
            $db->query('SELECT pg_advisory_xact_lock(79432, 2)');
            // Excludes licence writes while preview/version checks and the transfer execute.
            $db->query('LOCK TABLE engage_licences IN SHARE ROW EXCLUSIVE MODE');
            $existing = $db->query('SELECT * FROM engage_email_account_links WHERE link_id = ? OR source_subject = ?', [$in['link_id'], $in['source_subject']])->getRowArray();
            if ($existing) {
                if ($existing['link_id'] !== $in['link_id'] || $existing['main_subject'] !== $in['main_subject'] || $existing['source_subject'] !== $in['source_subject'] || $existing['source_realm'] !== $in['source_realm']) {
                    $db->transRollback(); return $this->failure(409, 'This account has already been linked.');
                }
                $db->transCommit();
                return ['status' => 200, 'data' => json_decode($existing['receipt'], true)];
            }
            $chain = $db->query('SELECT link_id FROM engage_email_account_links WHERE source_subject = ? OR main_subject = ? LIMIT 1', [$in['main_subject'], $in['source_subject']])->getRowArray();
            if ($chain) { $db->transRollback(); return $this->failure(409, 'Account-link chains and cycles are not allowed.'); }
            $rows = $db->query("SELECT l.*, to_jsonb(app_keys) AS app_keys_json FROM engage_licences l
                WHERE holder_uuid IN (?, ?) AND product_code = 'email' ORDER BY id FOR UPDATE", [$in['source_subject'], $in['main_subject']])->getResultArray();
            $items = [];
            $versions = [];
            foreach ($rows as $row) {
                $keys = json_decode($row['app_keys_json'], true);
                if ($keys !== ['email']) { $db->transRollback(); return $this->failure(409, 'A bundled licence requires a separate Email allocation before linking. No licences were moved.'); }
                $versions[] = [$row['id'], $row['version'], $row['holder_uuid'], $row['status']];
                if ($row['holder_uuid'] === $in['source_subject']) $items[] = ['licence_ref' => $row['licence_ref'], 'status' => $row['status'], 'scope' => $row['scope'], 'cmp_id' => $row['cmp_id'], 'expires_at' => $row['ends_at'], 'basic' => $this->isTrue($row['is_basic'])];
            }
            $digest = hash('sha256', json_encode([$in['link_id'], $in['source_subject'], $in['main_subject'], $versions]));
            $data = ['link_id' => $in['link_id'], 'main_subject' => $in['main_subject'], 'digest' => $digest, 'licences' => $items,
                'note' => 'Email licences move to the main account. Duplicate free plans do not stack. Company scope, expiry dates and invoice history remain attached to each licence.'];
            if (!$commit) { $db->transCommit(); return ['status' => 200, 'data' => $data]; }
            if (!is_string($in['digest'] ?? null) || !hash_equals($digest, $in['digest'])) { $db->transRollback(); return $this->failure(409, 'Licences changed. Review a new transfer preview.'); }
            $hasBasic = false;
            foreach ($rows as $r) {
                if ($r['holder_uuid'] === $in['main_subject'] && $this->isTrue($r['is_basic']) && in_array($r['status'], ['issued','active','suspended','superseded'], true)) $hasBasic = true;
            }
            $companies = [];
            foreach ($rows as $r) {
                if ($r['holder_uuid'] !== $in['source_subject']) continue;
                $status = $r['status'];
                if ($this->isTrue($r['is_basic']) && in_array($status, ['issued','active','suspended','superseded'], true)) {
                    if ($hasBasic) $status = 'revoked';
                    $hasBasic = true;
                }
                $db->query("UPDATE engage_licences SET holder_uuid = ?, status = ?, revoked_at = CASE WHEN ? = 'revoked' THEN COALESCE(revoked_at, NOW()) ELSE revoked_at END, version = version + 1, updated_at = NOW() WHERE id = ?", [$in['main_subject'], $status, $status, $r['id']]);
                $changes = ['holder_uuid' => ['from' => $in['source_subject'], 'to' => $in['main_subject']], 'link_id' => $in['link_id'], 'status' => ['from' => $r['status'], 'to' => $status]];
                $db->query("INSERT INTO engage_licence_events (event_id, licence_id, licence_ref, cmp_id, holder_uuid, seq, type, actor_kind, actor_id, actor_display, summary, reason, changes, licence_version)
                    VALUES (?, ?, ?, ?, ?, 0, 'holder_changed', 'system', NULL, 'Verified Email account linking', 'Email licence consolidated under the main account', ?, ?::jsonb, ?)",
                    ['lev_' . bin2hex(random_bytes(12)), $r['id'], $r['licence_ref'], $r['cmp_id'], $in['main_subject'], 'Both identities verified; explicit transfer consent', json_encode($changes), (int) $r['version'] + 1]);
                if ($r['scope'] === 'company' && $r['cmp_id'] !== null) $companies[(int) $r['cmp_id']] = true;
            }
            (new LicenceService($db))->reconcileBasic($in['main_subject'], 'email', ['kind' => 'system', 'id' => null, 'display' => 'Email account linking']);
            $data['status'] = 'active';
            $db->query('INSERT INTO engage_email_account_links (link_id, source_subject, source_realm, main_subject, receipt) VALUES (?, ?, ?, ?, ?::jsonb)',
                [$in['link_id'], $in['source_subject'], $in['source_realm'], $in['main_subject'], json_encode($data)]);
            $outbox = new EntitlementOutbox($db);
            foreach ([$in['source_subject'], $in['main_subject']] as $holder) $outbox->enqueue($holder, null, ['email'], 'email.account_linked', $in['link_id']);
            foreach (array_keys($companies) as $cmp) $outbox->enqueue(null, $cmp, ['email'], 'email.account_linked', $in['link_id']);
            if (!$db->transStatus()) throw new \RuntimeException('Transfer transaction failed');
            $db->transCommit();
            return ['status' => 200, 'data' => $data];
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Email account consolidation failed: {exception}', ['exception' => $e]);
            return $this->failure(503, 'The transfer was not confirmed. Retry the same request.');
        }
    }
    private function isTrue(mixed $v): bool { return in_array($v, [true, 1, '1', 't', 'true'], true); }
    private function failure(int $status, string $message): array { return ['status' => $status, 'message' => $message]; }
}
