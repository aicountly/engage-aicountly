<?php
declare(strict_types=1);

namespace App\Services;

use App\Libraries\Seller\Money;
use App\Models\LeadsModel;
use Config\Services;

/**
 * What happens after a lead changes stage, wherever the change came from (staff move-stage / PUT,
 * Sales Bot): the Reach outcome event is queued and, on conversion, the lead's confirmed payments are
 * evaluated for seller commission. Never throws to the caller.
 */
class LeadLifecycleService
{
    public function stageChanged(int $leadId, ?string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        try {
            $lead = (new LeadsModel())->find($leadId);
            if (! $lead) {
                return;
            }
            $value = null;
            if ($to === 'converted') {
                (new LeadsModel())->update($leadId, ['converted_at' => \App\Libraries\Seller\Clock::now()]);
                $lead['converted_at'] = \App\Libraries\Seller\Clock::now();
                $sum = Services::sellerAccounts()->db()->query(
                    "SELECT SUM(amount) AS s FROM engage_lead_payments WHERE lead_id = ? AND status = 'confirmed'", [$leadId]
                )->getRow()->s ?? null;
                $value = $sum !== null ? Money::format(Money::toPaise((string) $sum)) : null;
            }
            Services::reachOutcomes()->stageChanged($lead, $to, $value);
            if ($to === 'converted') {
                Services::commissions()->accrueForLead($leadId);
            }
        } catch (\Throwable $e) {
            log_message('error', "Lead {$leadId} stage-change follow-up failed: " . $e->getMessage());
        }
    }
}
