<?php

namespace App\Services;

use App\Libraries\Seller\Clock;
use App\Models\CampaignLeadsModel;
use App\Models\CampaignsModel;
use App\Models\LeadActivitiesModel;
use App\Models\LeadSourcesModel;
use App\Models\LeadsModel;
use App\Models\ProductsModel;
use App\Services\Seller\SellerLeadService;
use Config\Services;

/**
 * Reach -> Engage lead intake.
 *
 * Wired from POST /api/v1/internal/reach/leads (behind InternalTokenFilter).
 *   1. Validate source portal + campaign shape.
 *   2. Upsert campaign row.
 *   3. De-duplicate: email (case-insensitive) first, then mobile (last 10 digits of mobile / whatsapp).
 *      A repeat push NEVER changes stage, sales_status or owner (a converted / lost / won lead stays so;
 *      an open lead keeps its place in the pipeline); it only fills empty contact fields, records the
 *      new touch (UTM / landing page) and an activity.
 *   4. Seller attribution: `ref` (seller code) → SellerLeadService::applyReferral (90-day protection,
 *      never over another seller's protection, never on a closed lead).
 *   5. Score (and, for a new lead, schedule a follow-up) with the SalesBot; audit to Console.
 *
 * The answer says what happened: result created | updated, duplicate true | false, matched_on.
 *
 * Accepted extras (top level, or inside reach_meta): ref | seller_code, utm_source, utm_medium,
 * utm_campaign, utm_term, utm_content (or a `utm` object), landing_url | landing_page_url, referrer_url,
 * reach_lead_id, landing_page_id.
 */
class ReachIntakeService
{
    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public function ingest(array $payload, array $opts = []): array
    {
        $errors = $this->validate($payload);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $portal   = (string) ($payload['source_portal']   ?? 'reach.aicountly.org');
        $campKey  = (string) ($payload['campaign_code']   ?? $payload['campaign_id'] ?? '');
        $campName = (string) ($payload['campaign_name']   ?? $campKey);
        $meta     = is_array($payload['reach_meta'] ?? null) ? $payload['reach_meta'] : [];

        $campaign = (new CampaignsModel())->findOrCreateBySource($portal, $campKey ?: null, $campName, [
            'campaign_kind' => (string) ($payload['campaign_kind'] ?? 'reach'),
            'metadata'      => ['reach_meta' => $payload['campaign_meta'] ?? []],
        ]);

        $source = (new LeadSourcesModel())->where('code', 'reach_campaign')->first();

        $leads  = new LeadsModel();
        $email  = trim((string) ($payload['email'] ?? ''));
        $mobile = trim((string) ($payload['mobile'] ?? ''));
        [$existing, $matchedOn] = $leads->findDuplicate($email, $mobile !== '' ? $mobile : (string) ($payload['whatsapp'] ?? ''));

        $productCode = (string) ($payload['interested_product'] ?? '');
        $product = $productCode ? (new ProductsModel())->where('code', $productCode)->first() : null;
        $touch = self::touch($payload, $meta, $campKey ?: $campName);
        $reachLeadId = self::intOrNull($meta['reach_lead_id'] ?? $payload['reach_lead_id'] ?? null);
        $ref = (string) ($payload['ref'] ?? $meta['ref'] ?? $payload['seller_code'] ?? $meta['seller_code'] ?? '');

        if ($existing) {
            $leadId = (int) $existing['id'];
            $fill = [];
            foreach ([
                'organization'          => $payload['organization'] ?? null,
                'email'                 => $email ?: null,
                'mobile'                => $mobile ?: null,
                'whatsapp'              => $payload['whatsapp'] ?? null,
                'interested_product'    => $productCode ?: null,
                'interested_product_id' => $product['id'] ?? null,
                'subscription_interest' => $payload['subscription_interest'] ?? null,
                'expected_users'        => isset($payload['expected_users']) ? (int) $payload['expected_users'] : null,
                'expected_companies'    => isset($payload['expected_companies']) ? (int) $payload['expected_companies'] : null,
            ] as $field => $value) {
                if ($value !== null && $value !== '' && ($existing[$field] ?? null) === null) {
                    $fill[$field] = $value;
                }
            }
            if ($reachLeadId !== null && empty($existing['reach_lead_id'])) {
                $fill['reach_lead_id'] = $reachLeadId;
            }
            $attr = is_array($existing['attribution'] ?? null) ? $existing['attribution'] : [];
            $attr['first_touch'] ??= $touch;
            $attr['last_touch'] = $touch;
            $attr['touches'] = (int) ($attr['touches'] ?? 1) + 1;
            $fill['attribution'] = $attr;
            $metadata = is_array($existing['metadata'] ?? null) ? $existing['metadata'] : [];
            $metadata['reach_payload'] = $payload;
            $metadata['reach_repeat_pushes'] = (int) ($metadata['reach_repeat_pushes'] ?? 0) + 1;
            $fill['metadata'] = $metadata;
            // Deliberately NOT touched: stage, sales_status, owner_id, priority, name, notes.
            $leads->update($leadId, $fill);
            (new LeadActivitiesModel())->record($leadId, 'reach_update', 'Reach campaign lead pushed again (matched by ' . $matchedOn . ')',
                ! empty($payload['notes']) ? (string) $payload['notes'] : null, [
                'author_kind' => 'bot',
                'metadata'    => ['campaign_id' => $campaign['id'] ?? null, 'reach_meta' => $meta, 'matched_on' => $matchedOn,
                                  'kept_stage' => $existing['stage'], 'kept_sales_status' => $existing['sales_status']],
            ]);
            $result = 'updated';
        } else {
            $data = [
                'lead_code'             => $leads->generateLeadCode(),
                'name'                  => (string) ($payload['name'] ?? ''),
                'organization'          => $payload['organization']         ?? null,
                'email'                 => $email ?: null,
                'mobile'                => $mobile ?: null,
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
                'reach_lead_id'         => $reachLeadId,
                'attribution'           => ['first_touch' => $touch, 'last_touch' => $touch, 'touches' => 1],
                'metadata'              => ['reach_payload' => $payload],
            ];
            $leadId = (int) $leads->insert($data, true);
            (new LeadActivitiesModel())->record($leadId, 'reach_create', 'Reach campaign lead created', null, [
                'author_kind' => 'bot',
                'metadata'    => ['campaign_id' => $campaign['id'] ?? null, 'reach_meta' => $meta],
            ]);
            $result = 'created';
        }

        $sellerAttribution = ['status' => 'none'];
        if (trim($ref) !== '') {
            $sellerAttribution = Services::sellerLeads()->applyReferral($leads->find($leadId), $ref);
            if ($sellerAttribution['status'] === 'ignored') {
                (new LeadActivitiesModel())->record($leadId, 'seller_referral_ignored', 'Referral ' . strtoupper(trim($ref)) . ' not applied: ' . ($sellerAttribution['reason'] ?? ''), null, ['author_kind' => 'system']);
            }
        }

        if (! empty($campaign['id'])) {
            (new CampaignLeadsModel())->link((int) $campaign['id'], $leadId, (string) ($payload['attribution'] ?? 'primary'), $meta ?: null);
        }

        $lead = $leads->find($leadId);
        $scoreDispatch = null;
        $followDispatch = null;
        if (! SellerLeadService::isTerminal($lead)) {
            $scoreDispatch = Services::salesBot()->dispatch('score_lead', ['lead_id' => $leadId, 'requester_kind' => 'reach']);
            if ($result === 'created') {
                $followDispatch = Services::salesBot()->dispatch('schedule_follow_up', ['lead_id' => $leadId, 'requester_kind' => 'reach']);
            }
        }

        Services::auditService()->log('lead.reach_ingest', [
            'subject_kind' => 'lead',
            'subject_id'   => $leadId,
            'metadata'     => [
                'campaign_id'   => $campaign['id'] ?? null,
                'campaign_code' => $campKey,
                'source_portal' => $portal,
                'result'        => $result,
                'matched_on'    => $matchedOn,
                'seller_ref'    => $sellerAttribution['status'],
            ],
            'fanout_console' => true,
        ]);

        return [
            'ok'         => true,
            'result'     => $result,
            'duplicate'  => $result === 'updated',
            'matched_on' => $matchedOn,
            'lead'       => $leads->find($leadId),
            'campaign'   => $campaign,
            'seller_attribution' => $sellerAttribution,
            'score'      => $scoreDispatch['report'] ?? null,
            'follow_up'  => $followDispatch['report'] ?? null,
        ];
    }

    /** One attribution touch (UTM / landing page / ref) from the push. */
    private static function touch(array $payload, array $meta, string $campaign): array
    {
        $utm = [];
        $utmObj = is_array($payload['utm'] ?? null) ? $payload['utm'] : (is_array($meta['utm'] ?? null) ? $meta['utm'] : []);
        foreach (self::UTM_KEYS as $k) {
            $short = substr($k, 4);
            $v = $payload[$k] ?? $meta[$k] ?? $utmObj[$k] ?? $utmObj[$short] ?? null;
            if (is_scalar($v) && trim((string) $v) !== '') {
                $utm[$k] = mb_substr(trim((string) $v), 0, 191);
            }
        }
        $pick = static function (array $keys) use ($payload, $meta): ?string {
            foreach ($keys as $k) {
                $v = $payload[$k] ?? $meta[$k] ?? null;
                if (is_scalar($v) && trim((string) $v) !== '') {
                    return mb_substr(trim((string) $v), 0, 1000);
                }
            }

            return null;
        };

        return array_filter([
            'at'              => gmdate('Y-m-d\TH:i:s\Z'),
            'campaign'        => $campaign !== '' ? $campaign : null,
            'utm'             => $utm ?: null,
            'landing_url'     => $pick(['landing_url', 'landing_page_url']),
            'landing_page_id' => $pick(['landing_page_id']),
            'referrer_url'    => $pick(['referrer_url', 'referrer']),
            'ref'             => $pick(['ref', 'seller_code']),
        ], static fn ($v) => $v !== null);
    }

    private static function intOrNull(mixed $v): ?int
    {
        if (is_string($v) && ctype_digit($v)) {
            $v = (int) $v;
        }

        return is_int($v) && $v > 0 ? $v : null;
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
