<?php

namespace App\Commands;

use App\Libraries\AicS2s\ManageClient;
use App\Libraries\CronHeartbeat;
use App\Libraries\Licensing\LicensingSupport as S;
use App\Services\Licensing\EntitlementProjector;
use App\Libraries\Licensing\CommandOptions;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * `php spark engage:entitlements-reconcile [--holder=<uuid>] [--limit=N]` — REPORT-ONLY drift between Engage and
 * Manage (contract §3.3; daily in bin/ops/jobs.txt). For each holder Engage has pushed to, reads Manage's stored
 * state (GET {MANAGE_API_BASE_URL}/v1/inbound/engage/entitlements/{holder_uuid}, AIC-S2S read) and compares it, per
 * app, with Engage's current projection: missing in Manage, status / plan / licence / end date differ, or Manage's
 * seq behind Engage's last delivered seq. Never writes to Manage or to licences — fix drift by re-queueing
 * (Licensing → Manage sync → Re-queue, or engage:entitlements-reproject). Reports counts to Console as
 * engage.entitlements_reconcile. Unconfigured = dormant (exit 0).
 */
class EntitlementsReconcile extends BaseCommand
{
    use CommandOptions;

    protected $group       = 'Engage';
    protected $name        = 'engage:entitlements-reconcile';
    protected $description = 'Report drift between Engage licences and Manage plan entitlements (read-only).';
    protected $usage       = 'engage:entitlements-reconcile [--holder=<uuid>] [--limit=N]';

    public function run(array $params)
    {
        $client = ManageClient::forReconcile(); // Manage's read-scope key (its push key is POST-only)
        if (! $client->isConfigured()) {
            CLI::write('Entitlement reconcile: ' . $client->status());

            return EXIT_SUCCESS;
        }
        $db = Database::connect();
        $holder = self::opt($params, 'holder');
        $limit = max(1, (int) (self::opt($params, 'limit') ?? 5000));
        $holders = is_string($holder) && $holder !== '' ? [$holder]
            : array_column($db->query("SELECT DISTINCT holder_uuid FROM engage_entitlement_outbox WHERE holder_uuid IS NOT NULL AND status = 'delivered' ORDER BY holder_uuid LIMIT {$limit}")->getResultArray(), 'holder_uuid');
        $projector = new EntitlementProjector($db);
        $counts = ['holders' => 0, 'apps' => 0, 'drift' => 0, 'errors' => 0];
        foreach ($holders as $h) {
            $counts['holders']++;
            $res = $client->get(ManageClient::ENTITLEMENTS_PATH . '/' . rawurlencode($h));
            if ($res['status'] < 200 || $res['status'] >= 300) {
                $counts['errors']++;
                CLI::write("  {$h}: Manage answered " . ($res['status'] ?: $res['error']));

                continue;
            }
            $manage = self::byApp($res['json']['data'] ?? []);
            $licences = $projector->licencesOf($h, null);
            foreach ($projector->appKeysOfSubject($h, null) as $app) {
                $counts['apps']++;
                $mine = $projector->state($h, null, $app, $licences);
                $theirs = $manage[$app] ?? null;
                $lastSeq = (int) ($db->query("SELECT MAX(seq) AS s FROM engage_entitlement_outbox WHERE subject_key = ? AND app_key = ? AND status = 'delivered'",
                    [EntitlementProjector::subjectKey($h, null), $app])->getRow()->s ?? 0);
                foreach (self::diff($mine, $theirs, $lastSeq) as $d) {
                    $counts['drift']++;
                    CLI::write("  drift {$h} {$app}: {$d}");
                }
            }
        }
        CLI::write(sprintf('Entitlement reconcile: %d holder(s), %d app state(s), %d drift, %d error(s). Report only — nothing was changed.',
            $counts['holders'], $counts['apps'], $counts['drift'], $counts['errors']));
        CronHeartbeat::send('engage.entitlements_reconcile', 'ok', '', $counts);

        return EXIT_SUCCESS;
    }

    /** Manage's answer as [app_key => row]: a list of rows, {items|apps|entitlements: [...]}, or a map keyed by app. */
    public static function byApp(mixed $data): array
    {
        if (is_array($data) && ! array_is_list($data)) {
            foreach (['items', 'apps', 'entitlements'] as $k) {
                if (isset($data[$k]) && is_array($data[$k]) && array_is_list($data[$k])) {
                    $data = $data[$k];
                    break;
                }
            }
        }
        $out = [];
        if (is_array($data) && array_is_list($data)) {
            foreach ($data as $r) {
                if (is_array($r) && isset($r['app_key'])) {
                    $out[(string) $r['app_key']] = $r;
                }
            }
        } elseif (is_array($data)) {
            foreach ($data as $k => $r) {
                if (is_array($r)) {
                    $out[(string) $k] = $r + ['app_key' => (string) $k];
                }
            }
        }

        return $out;
    }

    /** @return list<string> human-readable differences (empty = in step) */
    public static function diff(array $mine, ?array $theirs, int $lastDeliveredSeq): array
    {
        if ($theirs === null) {
            return $mine['status'] === 'none' ? [] : ["missing in Manage (Engage: {$mine['status']})"];
        }
        $d = [];
        if (($theirs['status'] ?? null) !== $mine['status']) {
            $d[] = 'status Engage ' . $mine['status'] . ' / Manage ' . ($theirs['status'] ?? 'null');
        }
        $mp = $mine['plan']['code'] ?? null;
        $tp = is_array($theirs['plan'] ?? null) ? ($theirs['plan']['code'] ?? null) : null;
        if ($mp !== $tp) {
            $d[] = 'plan Engage ' . ($mp ?? 'none') . ' / Manage ' . ($tp ?? 'none');
        }
        if (($theirs['licence_ref'] ?? null) !== $mine['licence_ref']) {
            $d[] = 'licence Engage ' . ($mine['licence_ref'] ?? 'none') . ' / Manage ' . ($theirs['licence_ref'] ?? 'none');
        }
        $te = isset($theirs['ends_at']) && $theirs['ends_at'] !== null ? S::iso(S::parseInstant($theirs['ends_at'])) : null;
        if ($te !== $mine['ends_at']) {
            $d[] = 'ends_at Engage ' . ($mine['ends_at'] ?? 'none') . ' / Manage ' . ($te ?? 'none');
        }
        if (isset($theirs['seq']) && (int) $theirs['seq'] < $lastDeliveredSeq) {
            $d[] = "Manage seq {$theirs['seq']} behind Engage's delivered {$lastDeliveredSeq}";
        }

        return $d;
    }
}
