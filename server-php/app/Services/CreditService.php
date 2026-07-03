<?php

namespace App\Services;

use App\Models\CreditLedgerModel;
use App\Models\SettingsModel;
use Config\Services;

/**
 * Post credit ledger entries. Large amounts (>= threshold) route through
 * ApprovalService instead of posting immediately.
 */
class CreditService
{
    private CreditLedgerModel $ledger;
    private SettingsModel $settings;

    public function __construct()
    {
        $this->ledger   = new CreditLedgerModel();
        $this->settings = new SettingsModel();
    }

    public function post(array $entry): array
    {
        $threshold = (float) ($this->settings->getSetting('credit_large_threshold', (int) env('ENGAGE_CREDIT_LARGE_THRESHOLD', 10000)));
        $amount    = (float) ($entry['amount'] ?? 0);
        $largeAmount = $amount >= $threshold && $threshold > 0;

        $row = array_merge([
            'party_type'       => 'internal',
            'party_reference'  => 'unassigned',
            'credit_type'      => 'adjustment',
            'direction'        => 'credit',
            'amount'           => 0,
            'points_amount'    => 0,
            'currency'         => 'INR',
            'source'           => 'user',
            'linked_kind'      => null,
            'linked_id'        => null,
            'status'           => $largeAmount ? 'pending_approval' : 'posted',
            'remarks'          => null,
            'creator_kind'     => 'user',
            'created_by'       => $entry['created_by'] ?? (service('request')->engageUser['id'] ?? null),
            'created_at'       => date('Y-m-d H:i:s'),
        ], $entry);

        if (isset($row['metadata']) && is_array($row['metadata'])) {
            $row['metadata'] = json_encode($row['metadata']);
        }

        $id = (int) $this->ledger->insert($row, true);

        if ($largeAmount) {
            $approvalId = Services::approvalService()->request([
                'kind'         => 'credit_adjustment',
                'subject_kind' => 'credit_ledger',
                'subject_id'   => (string) $id,
                'risk_level'   => 'high',
                'payload'      => [
                    'party_type'      => $row['party_type'],
                    'party_reference' => $row['party_reference'],
                    'amount'          => $amount,
                    'direction'       => $row['direction'],
                    'credit_type'     => $row['credit_type'],
                    'currency'        => $row['currency'],
                ],
            ]);
            if ($approvalId) {
                $this->ledger->update($id, ['approval_id' => $approvalId]);
            }

            Services::auditService()->log('credit.large', [
                'subject_kind' => 'credit_ledger',
                'subject_id'   => $id,
                'metadata'     => ['amount' => $amount, 'threshold' => $threshold],
            ]);
        }

        return $this->ledger->find($id) ?? [];
    }

    public function markPosted(int $id, ?int $approvedBy = null): void
    {
        $this->ledger->update($id, [
            'status'      => 'posted',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function reverse(int $id, ?string $reason = null): void
    {
        $this->ledger->update($id, [
            'status'  => 'reversed',
            'remarks' => $reason,
        ]);
    }
}
