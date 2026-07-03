<?php

namespace App\Services;

use App\Models\AuditLogsModel;
use Config\Services;

/**
 * Append-only audit log writer. Every write action in Engage calls this.
 *
 * Also fans out to Console for cross-portal audit visibility (best-effort;
 * failures are logged but do not break the local audit write).
 */
class AuditService
{
    private AuditLogsModel $model;

    public function __construct()
    {
        $this->model = new AuditLogsModel();
    }

    public function log(string $event, array $opts = []): void
    {
        try {
            $req = service('request');
            $row = [
                'event'        => $event,
                'actor_id'     => $opts['actor_id']     ?? ($req->engageUser['id']    ?? null),
                'actor_email'  => $opts['actor_email']  ?? ($req->engageUser['email'] ?? null),
                'actor_role'   => $opts['actor_role']   ?? (($req->engageUser['roles'][0] ?? null)),
                'subject_kind' => $opts['subject_kind'] ?? null,
                'subject_id'   => isset($opts['subject_id']) ? (string) $opts['subject_id'] : null,
                'ip_address'   => $req->getIPAddress(),
                'user_agent'   => substr((string) $req->getUserAgent(), 0, 510),
                'metadata'     => isset($opts['metadata']) ? json_encode($opts['metadata']) : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ];

            $this->model->insert($row);
        } catch (\Throwable $e) {
            log_message('error', 'Audit log failed for event ' . $event . ': ' . $e->getMessage());
        }

        try {
            if (! empty($opts['fanout_console']) || $this->shouldFanout($event)) {
                Services::consoleClient()->sendAudit($event, $opts);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Audit console fan-out failed: ' . $e->getMessage());
        }
    }

    private function shouldFanout(string $event): bool
    {
        $prefixes = ['bot.', 'approval.', 'lead.converted', 'discount.applied', 'credit.large', 'auto_mode.'];
        foreach ($prefixes as $p) {
            if (str_starts_with($event, $p)) {
                return true;
            }
        }
        return false;
    }
}
