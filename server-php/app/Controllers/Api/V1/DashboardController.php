<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ApprovalRequestsModel;
use App\Models\BotReportsModel;
use App\Models\CreditLedgerModel;
use App\Models\FollowUpsModel;
use App\Models\LeadsModel;
use App\Models\PipelineStagesModel;
use App\Models\ProposalsModel;
use App\Models\RenewalsModel;

class DashboardController extends BaseApiController
{
    public function summary()
    {
        $leads = new LeadsModel();
        $totals = [
            'leads_total'      => $leads->countAllResults(false),
            'leads_open'       => $leads->whereNotIn('stage', ['converted', 'lost', 'not_relevant'])->countAllResults(),
            'leads_won_7d'     => $leads->where('stage', 'converted')->where('updated_at >=', date('Y-m-d H:i:s', strtotime('-7 days')))->countAllResults(),
            'leads_lost_7d'    => $leads->where('stage', 'lost')->where('updated_at >=', date('Y-m-d H:i:s', strtotime('-7 days')))->countAllResults(),
        ];

        $stages = (new PipelineStagesModel())->ordered();
        $stageCounts = [];
        foreach ($stages as $s) {
            $stageCounts[] = [
                'code'  => $s['code'],
                'name'  => $s['name'],
                'count' => (new LeadsModel())->where('stage', $s['code'])->countAllResults(),
                'colour'=> $s['colour'],
            ];
        }

        $proposals = new ProposalsModel();
        $pipelineValue = (float) $proposals
            ->selectSum('net_amount')
            ->whereIn('status', ['draft', 'sent', 'negotiation'])
            ->first()['net_amount'];

        $followUps = new FollowUpsModel();
        $upcoming = count($followUps->upcoming(7));
        $overdue  = count($followUps->overdue());

        $renewals = new RenewalsModel();
        $renewals30 = count($renewals->dueWithin(30));

        $bot = new BotReportsModel();
        $reports24h = $bot->where('created_at >=', date('Y-m-d H:i:s', strtotime('-24 hours')))->countAllResults();
        $reports7d  = $bot->where('created_at >=', date('Y-m-d H:i:s', strtotime('-7 days')))->countAllResults();

        $approvals = (new ApprovalRequestsModel())->where('status', 'pending')->countAllResults();

        $creditPending = (new CreditLedgerModel())->where('status', 'pending_approval')->countAllResults();

        return $this->ok([
            'totals'         => $totals,
            'stage_counts'   => $stageCounts,
            'pipeline_value' => $pipelineValue,
            'follow_ups'     => ['upcoming_7d' => $upcoming, 'overdue' => $overdue],
            'renewals_30d'   => $renewals30,
            'bot_reports'    => ['last_24h' => $reports24h, 'last_7d' => $reports7d],
            'approvals_pending' => $approvals,
            'credit_pending_approval' => $creditPending,
        ]);
    }
}
