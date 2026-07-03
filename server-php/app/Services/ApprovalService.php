<?php

namespace App\Services;

use App\Models\ApprovalRequestsModel;
use App\Models\SettingsModel;
use Config\Services;

/**
 * Approval workflow router.
 *
 * High-risk actions (sending external message, applying discount, marking
 * converted, creating subscription/license proposal, large credit adjustment,
 * auto-mode config changes) go through here.
 *
 * Confirm Mode: everything requires approval.
 * Auto Mode: low-risk actions listed in `allowed_auto_actions` may bypass.
 */
class ApprovalService
{
    private ApprovalRequestsModel $model;

    public function __construct()
    {
        $this->model = new ApprovalRequestsModel();
    }

    /**
     * Request an approval. Returns approval_request.id when a request is
     * actually created, or null when the request auto-approved under Auto
     * Mode.
     */
    public function request(array $opts): ?int
    {
        $req = service('request');
        $correlationId = $opts['correlation_id'] ?? ($opts['kind'] ?? 'req') . '-' . bin2hex(random_bytes(6));

        $mode          = (new SettingsModel())->getSetting('bot_mode', 'confirm');
        $allowedAuto   = (array) (new SettingsModel())->getSetting('allowed_auto_actions', []);
        $riskLevel     = (string) ($opts['risk_level'] ?? 'medium');
        $action        = (string) ($opts['action'] ?? '');
        $requesterKind = (string) ($opts['requester_kind'] ?? 'user');

        $isAutoEligible = $mode === 'auto'
            && $riskLevel === 'low'
            && ($action === '' || in_array($action, $allowedAuto, true));

        $row = [
            'correlation_id'  => $correlationId,
            'kind'            => (string) ($opts['kind'] ?? 'generic'),
            'subject_kind'    => $opts['subject_kind']   ?? null,
            'subject_id'      => isset($opts['subject_id']) ? (string) $opts['subject_id'] : null,
            'risk_level'      => $riskLevel,
            'status'          => $isAutoEligible ? 'auto_approved' : 'pending',
            'payload'         => isset($opts['payload']) ? json_encode($opts['payload']) : null,
            'requested_by'    => $opts['requested_by'] ?? ($req->engageUser['id'] ?? null),
            'requester_kind'  => $requesterKind,
        ];

        $id = (int) $this->model->insert($row, true);

        Services::auditService()->log('approval.requested', [
            'subject_kind' => 'approval_request',
            'subject_id'   => $id,
            'metadata'     => [
                'kind'       => $row['kind'],
                'risk_level' => $riskLevel,
                'auto'       => $isAutoEligible,
            ],
            'fanout_console' => true,
        ]);

        if ($isAutoEligible) {
            // Auto-approved under Auto Mode. Caller may proceed.
            return $id;
        }

        try {
            $consoleId = Services::consoleClient()->requestApproval([
                'correlation_id' => $correlationId,
                'kind'           => $row['kind'],
                'subject_kind'   => $row['subject_kind'],
                'subject_id'     => $row['subject_id'],
                'risk_level'     => $riskLevel,
                'payload'        => $opts['payload'] ?? null,
            ]);
            if ($consoleId !== null) {
                $this->model->update($id, [
                    'console_approval_id' => $consoleId,
                    'console_status'      => 'pending',
                ]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Console approval fan-out failed: ' . $e->getMessage());
        }

        return $id;
    }

    public function approve(int $id, ?int $userId = null, ?string $notes = null): array
    {
        $row = $this->model->find($id);
        if (! $row) return [];
        $this->model->update($id, [
            'status'         => 'approved',
            'decided_by'     => $userId,
            'decided_at'     => date('Y-m-d H:i:s'),
            'decision_notes' => $notes,
        ]);
        Services::auditService()->log('approval.approved', [
            'subject_kind' => 'approval_request', 'subject_id' => $id,
            'metadata' => ['kind' => $row['kind']],
            'fanout_console' => true,
        ]);
        return $this->model->find($id) ?? [];
    }

    public function reject(int $id, ?int $userId = null, ?string $notes = null): array
    {
        $row = $this->model->find($id);
        if (! $row) return [];
        $this->model->update($id, [
            'status'         => 'rejected',
            'decided_by'     => $userId,
            'decided_at'     => date('Y-m-d H:i:s'),
            'decision_notes' => $notes,
        ]);
        Services::auditService()->log('approval.rejected', [
            'subject_kind' => 'approval_request', 'subject_id' => $id,
            'metadata' => ['kind' => $row['kind']],
            'fanout_console' => true,
        ]);
        return $this->model->find($id) ?? [];
    }

    public function executed(int $id, array $result = []): array
    {
        $this->model->update($id, [
            'status'            => 'executed',
            'executed_at'       => date('Y-m-d H:i:s'),
            'execution_result'  => json_encode($result),
        ]);

        try {
            Services::consoleClient()->reportExecution([
                'approval_id'    => (int) $id,
                'correlation_id' => $this->model->find($id)['correlation_id'] ?? null,
                'result'         => $result,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Console execution report failed: ' . $e->getMessage());
        }

        return $this->model->find($id) ?? [];
    }

    /**
     * Given an action code, check whether it can bypass approval under Auto
     * Mode. Used by SalesBotService when routing bot actions.
     */
    public function autoModeAllows(string $action): bool
    {
        $mode = (new SettingsModel())->getSetting('bot_mode', 'confirm');
        if ($mode !== 'auto') return false;
        $allowed = (array) (new SettingsModel())->getSetting('allowed_auto_actions', []);
        return in_array($action, $allowed, true);
    }
}
