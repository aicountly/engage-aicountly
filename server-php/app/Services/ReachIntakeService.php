<?php

namespace App\Services;

use App\Models\CampaignLeadsModel;
use App\Models\CampaignsModel;
use App\Models\LeadActivitiesModel;
use App\Models\LeadSourcesModel;
use App\Models\LeadsModel;
use App\Models\ProductsModel;
use Config\Services;

/**
 * Reach -> Engage lead intake.
 *
 * Wired from POST /api/internal/reach/leads (behind InternalTokenFilter).
 * Steps:
 *   1. Validate source portal + campaign shape.
 *   2. Upsert campaign row.
 *   3. Upsert lead by email + campaign.
 *   4. Score lead with the SalesBot.
 *   5. Schedule/recommend follow-up.
 *   6. Emit audit event to Console.
 */
class ReachIntakeService
{
    public function ingest(array $payload, array $opts = []): array
    {
        $errors = $this->validate($payload);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $portal   = (string) ($payload['source_portal']   ?? 'reach.aicountly.org');
        $campKey  = (string) ($payload['campaign_code']   ?? $payload['campaign_id'] ?? '');
        $campName = (string) ($payload['campaign_name']   ?? $campKey);

        $campaign = (new CampaignsModel())->findOrCreateBySource($portal, $campKey ?: null, $campName, [
            'campaign_kind' => (string) ($payload['campaign_kind'] ?? 'reach'),
            'metadata'      => ['reach_meta' => $payload['campaign_meta'] ?? []],
        ]);

        $source = (new LeadSourcesModel())->where('code', 'reach_campaign')->first();

        // Upsert lead.
        $leads = new LeadsModel();
        $email = trim((string) ($payload['email'] ?? ''));
        $existing = $email ? $leads->findByEmail($email) : null;

        $productCode = (string) ($payload['interested_product'] ?? '');
        $product = $productCode ? (new ProductsModel())->where('code', $productCode)->first() : null;

        $data = [
            'name'                  => (string) ($payload['name'] ?? ''),
            'organization'          => $payload['organization']         ?? null,
            'email'                 => $email ?: null,
            'mobile'                => $payload['mobile']               ?? null,
            'whatsapp'              => $payload['whatsapp']             ?? null,
            'source_portal'         => $portal,
            'source_campaign'       => $campKey ?: $campName,
            'source_type'           => 'reach_campaign',
            'lead_source_id'        => $source['id']  ?? null,
            'interested_product'    => $productCode ?: null,
            'interested_product_id' => $product['id'] ?? null,
            'subscription_interest' => $payload['subscription_interest'] ?? null,
            'expected_users'        => isset($payload['expected_users'])     ? (int) $payload['expected_users']     : null,
            'expected_companies'    => isset($payload['expected_companies']) ? (int) $payload['expected_companies'] : null,
            'notes'                 => $payload['notes'] ?? null,
            'stage'                 => 'new',
            'priority'              => $payload['priority'] ?? 'normal',
            'sales_status'          => 'open',
            'metadata'              => json_encode(['reach_payload' => $payload]),
        ];

        if ($existing) {
            $leads->update((int) $existing['id'], $data);
            $leadId = (int) $existing['id'];
            (new LeadActivitiesModel())->record($leadId, 'reach_update', 'Reach campaign lead updated', null, [
                'author_kind' => 'bot',
                'metadata'    => ['campaign_id' => $campaign['id'] ?? null, 'reach_meta' => $payload['reach_meta'] ?? []],
            ]);
        } else {
            $data['lead_code'] = $leads->generateLeadCode();
            $leadId = (int) $leads->insert($data, true);
            (new LeadActivitiesModel())->record($leadId, 'reach_create', 'Reach campaign lead created', null, [
                'author_kind' => 'bot',
                'metadata'    => ['campaign_id' => $campaign['id'] ?? null, 'reach_meta' => $payload['reach_meta'] ?? []],
            ]);
        }

        if (! empty($campaign['id'])) {
            (new CampaignLeadsModel())->link((int) $campaign['id'], $leadId, (string) ($payload['attribution'] ?? 'primary'), $payload['reach_meta'] ?? null);
        }

        // Score + follow-up via the Sales Bot.
        $scoreDispatch = Services::salesBot()->dispatch('score_lead', ['lead_id' => $leadId, 'requester_kind' => 'reach']);
        $followDispatch = Services::salesBot()->dispatch('schedule_follow_up', ['lead_id' => $leadId, 'requester_kind' => 'reach']);

        Services::auditService()->log('lead.reach_ingest', [
            'subject_kind' => 'lead',
            'subject_id'   => $leadId,
            'metadata'     => [
                'campaign_id'   => $campaign['id'] ?? null,
                'campaign_code' => $campKey,
                'source_portal' => $portal,
            ],
            'fanout_console' => true,
        ]);

        return [
            'ok'       => true,
            'lead'     => $leads->find($leadId),
            'campaign' => $campaign,
            'score'    => $scoreDispatch['report'] ?? null,
            'follow_up'=> $followDispatch['report'] ?? null,
        ];
    }

    private function validate(array $payload): array
    {
        $errs = [];
        if (empty($payload['name']) || ! is_string($payload['name'])) {
            $errs[] = 'name is required.';
        }
        if (empty($payload['email']) && empty($payload['mobile']) && empty($payload['whatsapp'])) {
            $errs[] = 'At least one of email, mobile, or whatsapp is required.';
        }
        $portal = (string) ($payload['source_portal'] ?? '');
        if ($portal !== '' && ! str_ends_with($portal, 'aicountly.org') && ! str_ends_with($portal, 'aicountly.com')) {
            $errs[] = 'source_portal must be a trusted aicountly.* origin.';
        }
        $campKey = (string) ($payload['campaign_code'] ?? $payload['campaign_id'] ?? '');
        $campName = (string) ($payload['campaign_name'] ?? '');
        if ($campKey === '' && $campName === '') {
            $errs[] = 'campaign_code or campaign_name is required.';
        }
        return $errs;
    }
}
