<?php

namespace App\Services;

use App\Models\BotActionsModel;
use App\Models\BotQueueModel;
use App\Models\BotReportsModel;
use App\Models\CommunicationDraftsModel;
use App\Models\FollowUpsModel;
use App\Models\LeadActivitiesModel;
use App\Models\LeadsModel;
use App\Models\PipelineStagesModel;
use App\Models\ProductsModel;
use App\Models\RenewalsModel;
use App\Models\SettingsModel;
use Config\Services;
use Throwable;

/**
 * Engage Sales Bot — 14 capabilities. Every dispatch writes a full
 * engage_bot_reports row with the fields required by Task F:
 *   what it understood, data accessed, recommendation, action proposed/taken,
 *   approval status, message draft / proposal draft, next action, timestamp,
 *   errors.
 */
class SalesBotService
{
    private BotQueueModel $queue;
    private BotReportsModel $reports;
    private LeadsModel $leads;
    private FollowUpsModel $followUps;

    public function __construct()
    {
        $this->queue      = new BotQueueModel();
        $this->reports    = new BotReportsModel();
        $this->leads      = new LeadsModel();
        $this->followUps  = new FollowUpsModel();
    }

    /**
     * Dispatch a bot action. Returns:
     *   ['queue_id' => int, 'report' => array, 'approval_id' => int|null]
     */
    public function dispatch(string $action, array $payload = []): array
    {
        $mode = (string) (new SettingsModel())->getSetting('bot_mode', 'confirm');
        $actionDef = (new BotActionsModel())->where('code', $action)->first();
        if (! $actionDef) {
            return $this->writeErrorReport($action, $payload, $mode, 'Unknown bot action: ' . $action);
        }

        $queueId = (int) $this->queue->insert([
            'action'         => $action,
            'entity_kind'    => $payload['entity_kind'] ?? ($this->guessEntityKind($payload)),
            'entity_id'      => isset($payload['entity_id']) ? (string) $payload['entity_id'] : (isset($payload['lead_id']) ? (string) $payload['lead_id'] : null),
            'payload'        => json_encode($payload),
            'mode'           => $mode,
            'status'         => 'running',
            'attempts'       => 1,
            'run_at'         => date('Y-m-d H:i:s'),
            'started_at'     => date('Y-m-d H:i:s'),
            'requested_by'   => service('request')->engageUser['id'] ?? null,
            'requester_kind' => $payload['requester_kind'] ?? 'user',
        ], true);

        try {
            $ctx = $this->handle($action, $actionDef, $payload, $mode);
        } catch (Throwable $e) {
            $ctx = [
                'understanding' => 'Bot action failed unexpectedly.',
                'errors'        => [['message' => $e->getMessage()]],
                'action_taken'  => 'none',
                'approval_status'=> 'error',
            ];
            $this->queue->update($queueId, [
                'status'      => 'error',
                'finished_at' => date('Y-m-d H:i:s'),
                'last_error'  => substr($e->getMessage(), 0, 4000),
            ]);
        }

        $approvalId = $ctx['approval_id'] ?? null;

        $reportId = (int) $this->reports->insert([
            'queue_id'                 => $queueId,
            'action'                   => $action,
            'entity_kind'              => $payload['entity_kind'] ?? $this->guessEntityKind($payload),
            'entity_id'                => isset($payload['entity_id']) ? (string) $payload['entity_id'] : (isset($payload['lead_id']) ? (string) $payload['lead_id'] : null),
            'mode'                     => $mode,
            'understanding'            => (string) ($ctx['understanding'] ?? ''),
            'data_accessed'            => json_encode($ctx['data_accessed']   ?? []),
            'recommendation'           => $ctx['recommendation']   ?? null,
            'action_proposed'          => $ctx['action_proposed']  ?? null,
            'action_taken'             => $ctx['action_taken']     ?? null,
            'approval_status'          => $ctx['approval_status']  ?? 'not_required',
            'approval_id'              => $approvalId,
            'message_draft'            => $ctx['message_draft']    ?? null,
            'proposal_draft'           => isset($ctx['proposal_draft']) ? json_encode($ctx['proposal_draft']) : null,
            'evidence'                 => isset($ctx['evidence']) ? json_encode($ctx['evidence']) : null,
            'next_recommended_action'  => $ctx['next_recommended_action'] ?? null,
            'errors'                   => isset($ctx['errors']) ? json_encode($ctx['errors']) : null,
            'created_at'               => date('Y-m-d H:i:s'),
        ], true);

        $this->queue->update($queueId, [
            'status'      => $ctx['queue_status'] ?? ($approvalId ? 'awaiting_approval' : 'done'),
            'finished_at' => date('Y-m-d H:i:s'),
            'report_id'   => $reportId,
            'approval_id' => $approvalId,
        ]);

        Services::auditService()->log('bot.action', [
            'subject_kind'   => 'bot_report',
            'subject_id'     => $reportId,
            'metadata'       => [
                'action'          => $action,
                'mode'            => $mode,
                'approval_status' => $ctx['approval_status'] ?? 'not_required',
                'entity_id'       => $payload['lead_id'] ?? $payload['entity_id'] ?? null,
            ],
            'fanout_console' => true,
        ]);

        return [
            'queue_id'    => $queueId,
            'report_id'   => $reportId,
            'report'      => $this->reports->find($reportId),
            'approval_id' => $approvalId,
        ];
    }

    /**
     * Route the specific action to its handler.
     */
    private function handle(string $action, array $actionDef, array $payload, string $mode): array
    {
        return match ($action) {
            'qualify_lead'          => $this->qualifyLead($payload),
            'score_lead'            => $this->scoreLead($payload),
            'recommend_follow_up'   => $this->recommendFollowUp($payload),
            'draft_email'           => $this->draftEmail($payload, $actionDef, $mode),
            'draft_whatsapp'        => $this->draftWhatsApp($payload, $actionDef, $mode),
            'draft_proposal_summary'=> $this->draftProposalSummary($payload, $actionDef, $mode),
            'suggest_pricing'       => $this->suggestPricing($payload, $actionDef, $mode),
            'update_stage'          => $this->updateStage($payload),
            'schedule_follow_up'    => $this->scheduleFollowUp($payload),
            'identify_hot_leads'    => $this->identifyHotLeads($payload),
            'identify_stale_leads'  => $this->identifyStaleLeads($payload),
            'prepare_renewal'       => $this->prepareRenewal($payload, $actionDef, $mode),
            'convert_reach_lead'    => $this->convertReachLead($payload),
            'request_approval'      => $this->requestApprovalOnly($payload),
            default                 => throw new \RuntimeException('Unhandled action: ' . $action),
        };
    }

    // ---------- individual capability handlers ----------------------------

    private function qualifyLead(array $payload): array
    {
        $lead = $this->requireLead($payload);
        $score = Services::leadScoring()->score($lead);
        $verdict = $score['bucket'] === 'cold' ? 'not_qualified' : 'qualified';

        if ($verdict === 'qualified' && $lead['stage'] === 'new') {
            $this->leads->update((int) $lead['id'], ['stage' => 'qualified', 'lead_score' => $score['score']]);
            (new LeadActivitiesModel())->record((int) $lead['id'], 'bot_qualified', 'Bot qualified lead', null, [
                'author_kind' => 'bot', 'metadata' => $score,
            ]);
        }

        return [
            'understanding'  => "Evaluated lead #{$lead['id']} against source, product interest, and engagement signals.",
            'data_accessed'  => ['engage_leads.id=' . $lead['id'], 'engage_lead_sources', 'engage_settings.lead_score_thresholds'],
            'recommendation' => "Verdict: {$verdict} (bucket {$score['bucket']}, score {$score['score']}).",
            'action_taken'   => $verdict === 'qualified' && $lead['stage'] === 'new' ? 'Moved to qualified stage.' : 'No stage change.',
            'approval_status'=> 'not_required',
            'evidence'       => $score,
            'next_recommended_action' => $verdict === 'qualified' ? 'draft_email' : 'nurture (no immediate action)',
        ];
    }

    private function scoreLead(array $payload): array
    {
        $lead = $this->requireLead($payload);
        $score = Services::leadScoring()->score($lead);
        $this->leads->update((int) $lead['id'], [
            'lead_score'             => $score['score'],
            'conversion_probability' => $score['conversion'],
        ]);
        (new LeadActivitiesModel())->record((int) $lead['id'], 'bot_scored', 'Bot scored lead', null, [
            'author_kind' => 'bot', 'metadata' => $score,
        ]);

        return [
            'understanding'  => "Recomputed score for lead #{$lead['id']}.",
            'data_accessed'  => ['engage_leads.id=' . $lead['id'], 'engage_settings.lead_score_thresholds'],
            'recommendation' => "Score set to {$score['score']} ({$score['bucket']}), conversion {$score['conversion']}%.",
            'action_taken'   => 'Updated lead_score and conversion_probability.',
            'approval_status'=> 'not_required',
            'evidence'       => $score,
            'next_recommended_action' => $score['bucket'] === 'hot' ? 'recommend_follow_up' : 'identify_stale_leads',
        ];
    }

    private function recommendFollowUp(array $payload): array
    {
        $lead = $this->requireLead($payload);
        $days = 3;
        if (($lead['stage'] ?? '') === 'proposal_sent')      $days = 2;
        elseif (($lead['stage'] ?? '') === 'negotiation')    $days = 1;
        elseif (($lead['stage'] ?? '') === 'nurture')        $days = 14;
        $suggested = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        return [
            'understanding'  => "Chose follow-up interval based on stage {$lead['stage']}.",
            'data_accessed'  => ['engage_leads.id=' . $lead['id']],
            'recommendation' => "Schedule follow-up on {$suggested}.",
            'action_proposed'=> "Create follow-up due {$suggested}.",
            'action_taken'   => 'proposed only — call schedule_follow_up to commit.',
            'approval_status'=> 'not_required',
            'next_recommended_action' => 'schedule_follow_up',
        ];
    }

    private function draftEmail(array $payload, array $actionDef, string $mode): array
    {
        return $this->draftCommunication($payload, $actionDef, $mode, 'email', 'email_intro');
    }

    private function draftWhatsApp(array $payload, array $actionDef, string $mode): array
    {
        return $this->draftCommunication($payload, $actionDef, $mode, 'whatsapp', 'whatsapp_followup');
    }

    private function draftCommunication(array $payload, array $actionDef, string $mode, string $channel, string $kind): array
    {
        $lead = $this->requireLead($payload);
        $product = $lead['interested_product_id']
            ? (new ProductsModel())->find((int) $lead['interested_product_id'])
            : null;
        $draft = Services::llmClient()->draftMessage($kind, [
            'lead_name'    => $lead['name']         ?? '',
            'organization' => $lead['organization'] ?? '',
            'product_name' => $product['name']      ?? 'AICOUNTLY',
            'stage'        => $lead['stage']        ?? 'new',
        ]);

        // Create draft record + request approval.
        $draftId = (new CommunicationDraftsModel())->insert([
            'lead_id'      => (int) $lead['id'],
            'channel'      => $channel,
            'to_address'   => $channel === 'email' ? ($lead['email'] ?? null) : ($lead['whatsapp'] ?? $lead['mobile'] ?? null),
            'subject'      => $channel === 'email' ? ('Following up — ' . ($product['name'] ?? 'AICOUNTLY')) : null,
            'body'         => $draft['draft'],
            'status'       => 'draft',
            'creator_kind' => 'bot',
            'created_by'   => service('request')->engageUser['id'] ?? null,
            'metadata'     => json_encode(['draft_source' => $draft['source']]),
        ], true);

        $approvalId = null;
        $approvalStatus = 'not_required';
        if (! Services::approvalService()->autoModeAllows($actionDef['code'])) {
            $approvalId = Services::approvalService()->request([
                'kind'         => 'communication',
                'action'       => $actionDef['code'],
                'subject_kind' => 'communication_draft',
                'subject_id'   => (string) $draftId,
                'risk_level'   => $actionDef['risk_level'] ?? 'medium',
                'payload'      => ['channel' => $channel, 'lead_id' => (int) $lead['id']],
                'requester_kind' => 'bot',
            ]);
            $approvalStatus = $approvalId ? 'pending' : 'not_required';
            if ($approvalId) {
                (new CommunicationDraftsModel())->update($draftId, [
                    'approval_id' => $approvalId,
                    'status'      => 'awaiting_approval',
                ]);
            }
        }

        return [
            'understanding'  => "Drafted {$channel} for lead #{$lead['id']}.",
            'data_accessed'  => ['engage_leads.id=' . $lead['id'], 'engage_products'],
            'recommendation' => "Send {$channel} to " . ($lead['email'] ?? $lead['whatsapp'] ?? 'contact'),
            'action_proposed'=> "Send {$channel} draft #{$draftId}",
            'action_taken'   => 'Drafted; awaiting approval.',
            'approval_status'=> $approvalStatus,
            'approval_id'    => $approvalId,
            'message_draft'  => $draft['draft'],
            'evidence'       => ['draft_source' => $draft['source'], 'draft_id' => $draftId],
            'next_recommended_action' => 'wait for approval → send',
        ];
    }

    private function draftProposalSummary(array $payload, array $actionDef, string $mode): array
    {
        $lead = $this->requireLead($payload);
        $product = $lead['interested_product_id']
            ? (new ProductsModel())->find((int) $lead['interested_product_id'])
            : null;
        $draft = Services::llmClient()->draftMessage('proposal_summary', [
            'lead_name'    => $lead['name']         ?? '',
            'organization' => $lead['organization'] ?? '',
            'product_name' => $product['name']      ?? 'AICOUNTLY',
            'stage'        => $lead['stage']        ?? '',
        ]);

        $approvalId = null;
        $approvalStatus = 'not_required';
        if (! Services::approvalService()->autoModeAllows('draft_proposal_summary')) {
            $approvalId = Services::approvalService()->request([
                'kind'         => 'proposal',
                'action'       => 'draft_proposal_summary',
                'subject_kind' => 'lead',
                'subject_id'   => (string) $lead['id'],
                'risk_level'   => 'medium',
                'payload'      => ['lead_id' => (int) $lead['id'], 'product' => $product['code'] ?? null],
                'requester_kind' => 'bot',
            ]);
            $approvalStatus = $approvalId ? 'pending' : 'not_required';
        }

        return [
            'understanding'  => 'Drafted proposal summary from lead + product.',
            'data_accessed'  => ['engage_leads', 'engage_products'],
            'recommendation' => 'Attach summary to proposal, review with sales owner before sending.',
            'action_proposed'=> 'Create proposal from draft summary.',
            'action_taken'   => 'Drafted; awaiting approval.',
            'approval_status'=> $approvalStatus,
            'approval_id'    => $approvalId,
            'proposal_draft' => ['summary' => $draft['draft'], 'source' => $draft['source']],
            'message_draft'  => $draft['draft'],
            'next_recommended_action' => 'wait for approval → create proposal',
        ];
    }

    private function suggestPricing(array $payload, array $actionDef, string $mode): array
    {
        $lead = $this->requireLead($payload);
        $expectedUsers = (int) ($lead['expected_users'] ?? 0);
        $suggestedDiscount = 0;
        if ($expectedUsers >= 100)       $suggestedDiscount = 20;
        elseif ($expectedUsers >= 50)    $suggestedDiscount = 15;
        elseif ($expectedUsers >= 25)    $suggestedDiscount = 10;
        elseif ($expectedUsers >= 10)    $suggestedDiscount = 5;

        $approvalId = null;
        $approvalStatus = 'not_required';
        if ($suggestedDiscount > 0) {
            $approvalId = Services::approvalService()->request([
                'kind'         => 'discount',
                'action'       => 'suggest_pricing',
                'subject_kind' => 'lead',
                'subject_id'   => (string) $lead['id'],
                'risk_level'   => 'high',
                'payload'      => [
                    'lead_id'          => (int) $lead['id'],
                    'discount_percent' => $suggestedDiscount,
                    'reason'           => "Expected users {$expectedUsers}.",
                ],
                'requester_kind' => 'bot',
            ]);
            $approvalStatus = $approvalId ? 'pending' : 'not_required';
        }

        return [
            'understanding'  => 'Estimated volume tier from expected_users to propose discount.',
            'data_accessed'  => ['engage_leads.id=' . $lead['id']],
            'recommendation' => "Offer {$suggestedDiscount}% volume discount.",
            'action_proposed'=> "Route discount request for admin approval.",
            'action_taken'   => $suggestedDiscount > 0 ? 'Approval requested.' : 'No discount justified.',
            'approval_status'=> $approvalStatus,
            'approval_id'    => $approvalId,
            'evidence'       => ['expected_users' => $expectedUsers, 'suggested_discount' => $suggestedDiscount],
            'next_recommended_action' => 'wait for approval → apply discount',
        ];
    }

    private function updateStage(array $payload): array
    {
        $lead = $this->requireLead($payload);
        $to   = (string) ($payload['to_stage'] ?? '');
        $stages = (new PipelineStagesModel())->findAll();
        $valid = array_column($stages, 'code');
        if (! in_array($to, $valid, true)) {
            return [
                'understanding'  => 'Requested stage transition.',
                'data_accessed'  => ['engage_pipeline_stages'],
                'errors'         => [['message' => 'Invalid stage: ' . $to]],
                'approval_status'=> 'error',
            ];
        }

        // If it's a terminal or high-impact transition, ask for approval.
        $terminalConvert = $to === 'converted';
        $approvalId = null;
        $approvalStatus = 'not_required';
        if ($terminalConvert) {
            $approvalId = Services::approvalService()->request([
                'kind'         => 'lead_conversion',
                'action'       => 'update_stage',
                'subject_kind' => 'lead',
                'subject_id'   => (string) $lead['id'],
                'risk_level'   => 'high',
                'payload'      => ['to_stage' => $to],
                'requester_kind' => 'bot',
            ]);
            $approvalStatus = $approvalId ? 'pending' : 'not_required';
        } else {
            $this->leads->update((int) $lead['id'], ['stage' => $to]);
            (new LeadActivitiesModel())->record((int) $lead['id'], 'bot_stage_move', "Bot moved to {$to}", null, [
                'author_kind' => 'bot',
            ]);
        }

        return [
            'understanding'  => "Suggested stage {$to} for lead #{$lead['id']}.",
            'data_accessed'  => ['engage_leads', 'engage_pipeline_stages'],
            'recommendation' => "Move to {$to}.",
            'action_proposed'=> "Set stage = {$to}.",
            'action_taken'   => $terminalConvert ? 'Awaiting approval to convert.' : "Updated stage to {$to}.",
            'approval_status'=> $approvalStatus,
            'approval_id'    => $approvalId,
        ];
    }

    private function scheduleFollowUp(array $payload): array
    {
        $lead = $this->requireLead($payload);
        $dueAt = $payload['due_at'] ?? date('Y-m-d H:i:s', strtotime('+3 days'));
        $followUpId = $this->followUps->insert([
            'lead_id'          => (int) $lead['id'],
            'due_at'           => $dueAt,
            'kind'             => $payload['kind']    ?? 'call',
            'channel'          => $payload['channel'] ?? 'phone',
            'title'            => $payload['title']   ?? 'Bot follow-up',
            'body'             => $payload['body']    ?? null,
            'status'           => 'pending',
            'created_by_kind'  => 'bot',
        ], true);

        $this->leads->update((int) $lead['id'], ['next_follow_up_at' => $dueAt]);

        return [
            'understanding'  => "Scheduled follow-up for lead #{$lead['id']} at {$dueAt}.",
            'data_accessed'  => ['engage_leads'],
            'recommendation' => 'Contact lead per follow-up.',
            'action_taken'   => "Created engage_follow_ups #{$followUpId}.",
            'approval_status'=> 'not_required',
            'evidence'       => ['follow_up_id' => $followUpId, 'due_at' => $dueAt],
            'next_recommended_action' => 'draft_email or draft_whatsapp',
        ];
    }

    private function identifyHotLeads(array $payload): array
    {
        $thresholds = (array) (new SettingsModel())->getSetting('lead_score_thresholds', ['hot' => 75, 'warm' => 50, 'cold' => 25]);
        $threshold  = (int) ($thresholds['hot'] ?? 75);
        $hot = $this->leads
            ->select('id, lead_code, name, organization, lead_score, stage, next_follow_up_at')
            ->where('lead_score >=', $threshold)
            ->whereNotIn('stage', ['converted', 'lost', 'not_relevant'])
            ->orderBy('lead_score', 'DESC')
            ->findAll(100);
        return [
            'understanding'  => "Scanned open leads for score >= {$threshold}.",
            'data_accessed'  => ['engage_leads'],
            'recommendation' => 'Prioritise these leads for personal outreach.',
            'action_taken'   => 'Compiled hot lead list.',
            'approval_status'=> 'not_required',
            'evidence'       => ['count' => count($hot), 'leads' => $hot],
            'next_recommended_action' => 'draft_email for top 10',
        ];
    }

    private function identifyStaleLeads(array $payload): array
    {
        $days = (int) (new SettingsModel())->getSetting('stale_lead_days', 14);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stale = $this->leads
            ->select('id, lead_code, name, organization, stage, last_contacted_at')
            ->where('last_contacted_at <', $cutoff)
            ->whereNotIn('stage', ['converted', 'lost', 'not_relevant'])
            ->orderBy('last_contacted_at', 'ASC')
            ->findAll(200);
        return [
            'understanding'  => "Found leads with no contact in {$days}+ days.",
            'data_accessed'  => ['engage_leads.last_contacted_at'],
            'recommendation' => 'Move to nurture or send re-engagement message.',
            'action_taken'   => 'Compiled stale lead list.',
            'approval_status'=> 'not_required',
            'evidence'       => ['count' => count($stale), 'leads' => $stale, 'stale_days' => $days],
            'next_recommended_action' => 'draft_whatsapp with kind=stale_lead_nudge',
        ];
    }

    private function prepareRenewal(array $payload, array $actionDef, string $mode): array
    {
        $windows = (array) (new SettingsModel())->getSetting('renewal_lead_days', [90, 60, 30, 7]);
        $renewals = new RenewalsModel();
        $created = [];

        foreach ($windows as $days) {
            $due = $renewals->dueWithin((int) $days);
            foreach ($due as $r) {
                $existing = (new FollowUpsModel())
                    ->where('renewal_id', (int) $r['id'])
                    ->where('kind', 'renewal_reminder')
                    ->where('metadata->>\'window_days\'', (string) $days)
                    ->countAllResults();
                if ($existing > 0) continue;
                $fuId = (new FollowUpsModel())->insert([
                    'lead_id'          => $r['lead_id']    ?? null,
                    'renewal_id'       => (int) $r['id'],
                    'due_at'           => date('Y-m-d 09:00:00', strtotime((string) $r['next_renewal_date'] . " -{$days} days")),
                    'kind'             => 'renewal_reminder',
                    'channel'          => 'email',
                    'title'            => "Renewal reminder ({$days}d)",
                    'body'             => 'Auto-created by SalesBot for upcoming renewal.',
                    'status'           => 'pending',
                    'created_by_kind'  => 'bot',
                    'metadata'         => json_encode(['window_days' => $days]),
                ], true);
                $renewals->update((int) $r['id'], ['reminder_stage' => (string) $days . 'd']);
                $created[] = $fuId;
            }
        }

        return [
            'understanding'  => 'Reviewed upcoming renewals and created reminder follow-ups.',
            'data_accessed'  => ['engage_renewals', 'engage_settings.renewal_lead_days'],
            'recommendation' => 'Sales owner should reach out per follow-up schedule.',
            'action_taken'   => 'Created ' . count($created) . ' renewal reminders.',
            'approval_status'=> 'not_required',
            'evidence'       => ['created_follow_ups' => $created, 'windows' => $windows],
            'next_recommended_action' => count($created) ? 'draft_email with kind=renewal_nudge' : 'nothing due right now',
        ];
    }

    private function convertReachLead(array $payload): array
    {
        // Delegates to ReachIntakeService for consistency, when explicit reach
        // payload is provided; otherwise re-scores an existing lead marked as
        // reach source.
        if (isset($payload['reach_payload']) && is_array($payload['reach_payload'])) {
            $out = Services::reachIntake()->ingest($payload['reach_payload'], ['requester_kind' => 'bot']);
            return [
                'understanding'  => 'Reach campaign lead converted to Engage lead.',
                'data_accessed'  => ['engage_lead_sources', 'engage_campaigns'],
                'recommendation' => 'Score newly created lead.',
                'action_taken'   => 'Created/updated lead #' . ($out['lead']['id'] ?? '?'),
                'approval_status'=> 'not_required',
                'evidence'       => $out,
                'next_recommended_action' => 'score_lead → recommend_follow_up',
            ];
        }

        $lead = $this->requireLead($payload);
        $score = Services::leadScoring()->score($lead);
        $this->leads->update((int) $lead['id'], ['lead_score' => $score['score']]);
        return [
            'understanding'  => 'Existing Reach lead re-evaluated.',
            'data_accessed'  => ['engage_leads.id=' . $lead['id']],
            'recommendation' => 'Follow-up per scoring bucket.',
            'action_taken'   => 'Refreshed lead score.',
            'approval_status'=> 'not_required',
            'evidence'       => $score,
            'next_recommended_action' => 'recommend_follow_up',
        ];
    }

    private function requestApprovalOnly(array $payload): array
    {
        $approvalId = Services::approvalService()->request([
            'kind'         => (string) ($payload['kind'] ?? 'generic'),
            'action'       => 'request_approval',
            'subject_kind' => $payload['subject_kind'] ?? null,
            'subject_id'   => isset($payload['subject_id']) ? (string) $payload['subject_id'] : null,
            'risk_level'   => (string) ($payload['risk_level'] ?? 'medium'),
            'payload'      => $payload['payload'] ?? [],
            'requester_kind' => 'bot',
        ]);
        return [
            'understanding'  => 'Explicit approval request generated by bot.',
            'data_accessed'  => ['engage_approval_requests'],
            'recommendation' => 'Admin to decide.',
            'action_taken'   => 'Approval created id ' . ($approvalId ?? 'auto-approved'),
            'approval_status'=> $approvalId ? 'pending' : 'auto_approved',
            'approval_id'    => $approvalId,
            'next_recommended_action' => 'wait for admin decision',
        ];
    }

    // ---------- helpers --------------------------------------------------

    private function requireLead(array $payload): array
    {
        $leadId = (int) ($payload['lead_id'] ?? $payload['entity_id'] ?? 0);
        if ($leadId <= 0) {
            throw new \RuntimeException('lead_id is required.');
        }
        $row = $this->leads->find($leadId);
        if (! $row) {
            throw new \RuntimeException("Lead #{$leadId} not found.");
        }
        return $row;
    }

    private function guessEntityKind(array $payload): ?string
    {
        if (isset($payload['lead_id']))     return 'lead';
        if (isset($payload['renewal_id']))  return 'renewal';
        if (isset($payload['proposal_id'])) return 'proposal';
        return $payload['entity_kind'] ?? null;
    }

    private function writeErrorReport(string $action, array $payload, string $mode, string $error): array
    {
        $reportId = (int) $this->reports->insert([
            'action'                 => $action,
            'entity_kind'            => $payload['entity_kind'] ?? null,
            'entity_id'              => $payload['entity_id']   ?? null,
            'mode'                   => $mode,
            'understanding'          => 'Bot could not interpret request.',
            'errors'                 => json_encode([['message' => $error]]),
            'approval_status'        => 'error',
            'created_at'             => date('Y-m-d H:i:s'),
        ], true);
        return [
            'queue_id'    => null,
            'report_id'   => $reportId,
            'report'      => $this->reports->find($reportId),
            'approval_id' => null,
        ];
    }
}
