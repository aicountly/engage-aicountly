<?php

namespace App\Controllers\Portal;

use App\Controllers\BaseApiController;
use App\Models\ApprovalRequestsModel;
use Config\Services;

/**
 * POST /api/v1/portal/bot/approval-callback — Console tells Engage that an
 * approval request has been decided.
 *
 * Body: { correlation_id, decision: approved|rejected, decided_by, notes }
 *
 * Console's Bot Orchestrator calls this from POST admin/bot-orchestrator/approvals/{id}/execute
 * with { approval_id, correlation_id, external_ref, action_type, action_payload, decided_by
 * (an e-mail), decided_at } and no `decision`: it only executes approved approvals, so a body
 * without `decision` is an approval.
 */
class ApprovalCallbackController extends BaseApiController
{
    public function store()
    {
        $body = $this->input();
        $correlationId = (string) ($body['correlation_id'] ?? '');
        $decision      = (string) ($body['decision']       ?? (isset($body['approval_id']) ? 'approved' : ''));
        if ($correlationId === '' || ! in_array($decision, ['approved', 'rejected'], true)) {
            return $this->fail('correlation_id and decision (approved|rejected) are required.', 400);
        }

        $m   = new ApprovalRequestsModel();
        $row = $m->where('correlation_id', $correlationId)->first();
        if (! $row) return $this->fail('Approval request not found.', 404);

        if ($decision === 'approved') {
            $updated = Services::approvalService()->approve(
                (int) $row['id'],
                is_numeric($body['decided_by'] ?? null) ? ((int) $body['decided_by'] ?: null) : null,
                $body['notes'] ?? (isset($body['decided_by']) && ! is_numeric($body['decided_by']) ? 'Approved in Console by ' . $body['decided_by'] : null),
            );
        } else {
            $updated = Services::approvalService()->reject(
                (int) $row['id'],
                (int) ($body['decided_by'] ?? 0) ?: null,
                $body['notes'] ?? null,
            );
        }
        $m->update((int) $row['id'], ['console_status' => $decision]);

        return $this->ok($updated);
    }
}
