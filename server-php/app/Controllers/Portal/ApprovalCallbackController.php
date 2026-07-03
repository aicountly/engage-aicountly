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
 */
class ApprovalCallbackController extends BaseApiController
{
    public function store()
    {
        $body = $this->input();
        $correlationId = (string) ($body['correlation_id'] ?? '');
        $decision      = (string) ($body['decision']       ?? '');
        if ($correlationId === '' || ! in_array($decision, ['approved', 'rejected'], true)) {
            return $this->fail('correlation_id and decision (approved|rejected) are required.', 400);
        }

        $m   = new ApprovalRequestsModel();
        $row = $m->where('correlation_id', $correlationId)->first();
        if (! $row) return $this->fail('Approval request not found.', 404);

        if ($decision === 'approved') {
            $updated = Services::approvalService()->approve(
                (int) $row['id'],
                (int) ($body['decided_by'] ?? 0) ?: null,
                $body['notes'] ?? null,
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
