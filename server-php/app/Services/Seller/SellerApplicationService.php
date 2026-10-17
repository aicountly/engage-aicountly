<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use App\Libraries\Seller\IndianTaxIds;
use App\Models\LeadActivitiesModel;
use App\Models\LeadSourcesModel;
use App\Models\LeadsModel;
use Config\Services;

/**
 * "Request to become a seller" (seller.aicountly.com) and its review by Engage staff.
 *
 * submit()  — application + an Engage lead (source_type 'seller_request'). The public answer is the
 *             same whether or not the email already applied / is already a seller (no enumeration).
 * approve() — creates the seller (SLR-NNNNNN), links the application's lead, issues the one-time
 *             activation link (72 h) and emails it when a seller mailer is configured; otherwise the link
 *             is returned ONCE to the approving staff member. Never claims an email was sent when it was not.
 * reject()  — with a reason.
 */
class SellerApplicationService
{
    public const RECEIVED_MESSAGE = 'Thank you. Your request to become an AICOUNTLY seller has been received; our team will contact you.';

    public function __construct(private ?SellerAccountService $accounts = null, private ?SellerMailer $mailer = null)
    {
        $this->accounts ??= Services::sellerAccounts();
        $this->mailer   ??= Services::sellerMailer();
    }

    /** Body fields (seller-aicountly docs/ENGAGE_SELLER_API.md "POST /applications"); `organization` / `message` accepted as aliases. */
    private static function normaliseInput(array $in): array
    {
        $in['business_name'] ??= $in['organization'] ?? null;
        $in['sales_approach'] ??= $in['message'] ?? null;

        return $in;
    }

    /** @return array<string,string> field → message */
    public static function validate(array $in): array
    {
        $in = self::normaliseInput($in);
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 191) {
            $e['name'] = 'Enter your full name.';
        }
        $email = trim((string) ($in['email'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            $e['email'] = 'Enter a valid email address.';
        }
        $mobile = (string) ($in['mobile'] ?? '');
        if (LeadsModel::normaliseMobile($mobile) === null || strlen(preg_replace('/\D+/', '', $mobile) ?? '') > 15) {
            $e['mobile'] = 'Enter a valid mobile number.';
        }
        $et = (string) ($in['entity_type'] ?? '');
        if ($et !== '' && ! in_array($et, IndianTaxIds::ENTITY_TYPES, true)) {
            $e['entity_type'] = 'Choose individual, proprietorship, partnership, LLP or company.';
        }
        foreach (['business_name' => 191, 'city' => 96, 'state' => 96, 'experience' => 1000] as $f => $max) {
            if (mb_strlen((string) ($in[$f] ?? '')) > $max) {
                $e[$f] = "Keep this under {$max} characters.";
            }
        }
        $approach = trim((string) ($in['sales_approach'] ?? ''));
        if ($approach !== '' && (mb_strlen($approach) < 10 || mb_strlen($approach) > 1000)) {
            $e['sales_approach'] = 'Describe how you will sell (10 to 1000 characters).';
        }
        if (array_key_exists('consent', $in) && (! is_array($in['consent']) || ($in['consent']['contact'] ?? null) !== true)) {
            $e['consent.contact'] = 'We need your consent to contact you.';
        }

        return $e;
    }

    /**
     * 201 {application_code, status: requested} for a new request; 200 with the open application for a
     * repeat; 200 {application_code: null, status: received} when the email already belongs to a seller.
     *
     * @return array{ok:bool, errors?:array<string,string>, status?:int, data?:array<string,mixed>}
     */
    public function submit(array $in, string $ip, string $ua): array
    {
        $errors = self::validate($in);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $in = self::normaliseInput($in);
        $db = $this->accounts->db();
        $email = strtolower(trim((string) $in['email']));
        $open = $db->query("SELECT application_code, status FROM engage_seller_applications WHERE LOWER(email) = ? AND status IN ('requested','under_review') LIMIT 1", [$email])->getRowArray();
        if ($open) {
            return ['ok' => true, 'status' => 200, 'data' => ['application_code' => $open['application_code'], 'status' => $open['status']]];
        }
        if ($this->accounts->findByEmail($email)) {
            return ['ok' => true, 'status' => 200, 'data' => ['application_code' => null, 'status' => 'received']];
        }

        $name = trim((string) $in['name']);
        $source = is_array($in['source'] ?? null) ? array_filter(array_map(static fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 255) : null, $in['source']), static fn ($v) => $v !== null && $v !== '') : [];
        $consent = is_array($in['consent'] ?? null) ? [
            'contact' => ($in['consent']['contact'] ?? null) === true,
            'privacy_policy_url' => mb_substr((string) ($in['consent']['privacy_policy_url'] ?? ''), 0, 255) ?: null,
            'consented_at' => mb_substr((string) ($in['consent']['consented_at'] ?? ''), 0, 40) ?: null,
            'ip' => $ip,
        ] : null;
        $leads = new LeadsModel();
        $leadSource = (new LeadSourcesModel())->where('code', 'seller_request')->first();
        $touch = array_filter(['at' => gmdate('Y-m-d\TH:i:s\Z'), 'utm' => array_intersect_key($source, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])) ?: null,
            'landing_path' => $source['landing_path'] ?? null, 'ref' => $source['ref'] ?? null], static fn ($v) => $v !== null);
        $leadId = (int) $leads->insert([
            'lead_code'      => $leads->generateLeadCode(),
            'name'           => $name,
            'organization'   => self::str($in['business_name'] ?? null),
            'email'          => $email,
            'mobile'         => trim((string) $in['mobile']),
            'source_portal'  => 'seller.aicountly.com',
            'source_campaign'=> 'seller_programme',
            'source_type'    => 'seller_request',
            'lead_source_id' => $leadSource['id'] ?? null,
            'notes'          => self::str($in['sales_approach'] ?? null),
            'stage'          => 'new',
            'priority'       => 'normal',
            'sales_status'   => 'open',
            'attribution'    => ['first_touch' => $touch, 'last_touch' => $touch, 'touches' => 1],
            'metadata'       => ['seller_application' => ['entity_type' => $in['entity_type'] ?? null, 'city' => $in['city'] ?? null,
                                 'state' => $in['state'] ?? null, 'experience' => $in['experience'] ?? null]],
        ], true);

        $n = (int) $db->query("SELECT nextval('engage_seller_application_code_seq') AS n")->getRow()->n;
        $code = 'SAP-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        try {
            $db->table('engage_seller_applications')->insert([
                'application_code' => $code,
                'name'         => $name,
                'email'        => $email,
                'mobile'       => trim((string) $in['mobile']),
                'organization' => self::str($in['business_name'] ?? null),
                'entity_type'  => self::str($in['entity_type'] ?? null),
                'city'         => self::str($in['city'] ?? null),
                'state'        => self::str($in['state'] ?? null),
                'profile'      => self::str($in['profile'] ?? null),
                'message'      => self::str($in['sales_approach'] ?? null),
                'lead_id'      => $leadId,
                'ip_address'   => substr($ip, 0, 64),
                'user_agent'   => substr($ua, 0, 255),
                'metadata'     => json_encode(['experience' => self::str($in['experience'] ?? null), 'consent' => $consent, 'source' => $source ?: null]),
            ]);
        } catch (\Throwable $e) {
            // Unique open-application index: a concurrent duplicate.
            log_message('warning', 'Seller application insert refused: ' . $e->getMessage());
            $open = $db->query("SELECT application_code, status FROM engage_seller_applications WHERE LOWER(email) = ? AND status IN ('requested','under_review') LIMIT 1", [$email])->getRowArray();

            return ['ok' => true, 'status' => 200, 'data' => ['application_code' => $open['application_code'] ?? null, 'status' => $open['status'] ?? 'received']];
        }
        $appId = (int) $db->insertID();
        (new LeadActivitiesModel())->record($leadId, 'seller_application', 'Seller programme request received (' . $code . ')', null, [
            'author_kind' => 'system', 'metadata' => ['application_id' => $appId],
        ]);
        Services::auditService()->log('seller.application_received', [
            'actor_email' => $email, 'actor_role' => 'applicant',
            'subject_kind' => 'seller_application', 'subject_id' => $appId, 'metadata' => ['lead_id' => $leadId, 'application_code' => $code],
        ]);

        return ['ok' => true, 'status' => 201, 'data' => ['application_code' => $code, 'status' => 'requested']];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->accounts->db()->table('engage_seller_applications')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed> */
    public function markUnderReview(int $id, array $staff): array
    {
        $app = $this->find($id);
        if (! $app) {
            return ['status' => 404, 'error' => 'Application not found.'];
        }
        if ($app['status'] !== 'requested') {
            return ['status' => 409, 'error' => 'Only a new request can be moved to under review (this one is ' . $app['status'] . ').'];
        }
        $this->accounts->db()->table('engage_seller_applications')->where('id', $id)->update([
            'status' => 'under_review', 'reviewed_by' => $staff['id'], 'reviewed_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        $this->auditStaff('seller.application_under_review', $id, []);

        return ['status' => 200, 'data' => $this->find($id)];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, array $staff, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'error' => 'A reason is required to reject an application.', 'details' => ['reason' => 'Give a reason.']];
        }
        $app = $this->find($id);
        if (! $app) {
            return ['status' => 404, 'error' => 'Application not found.'];
        }
        if (! in_array($app['status'], ['requested', 'under_review'], true)) {
            return ['status' => 409, 'error' => 'This application was already ' . $app['status'] . '.'];
        }
        $this->accounts->db()->table('engage_seller_applications')->where('id', $id)->update([
            'status' => 'rejected', 'decision_reason' => $reason, 'reviewed_by' => $staff['id'], 'reviewed_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        if (! empty($app['lead_id'])) {
            (new LeadActivitiesModel())->record((int) $app['lead_id'], 'seller_application', 'Seller application rejected', $reason);
        }
        $this->auditStaff('seller.application_rejected', $id, ['reason' => $reason]);

        return ['status' => 200, 'data' => $this->find($id)];
    }

    /** @return array<string,mixed> */
    public function approve(int $id, array $staff, ?string $note = null): array
    {
        $db = $this->accounts->db();
        $app = $this->find($id);
        if (! $app) {
            return ['status' => 404, 'error' => 'Application not found.'];
        }
        if (! in_array($app['status'], ['requested', 'under_review'], true)) {
            return ['status' => 409, 'error' => 'This application was already ' . $app['status'] . '.'];
        }
        if ($this->accounts->findByEmail((string) $app['email'])) {
            return ['status' => 409, 'error' => 'A seller with this email address already exists.'];
        }

        $db->transStart();
        $code = $this->accounts->nextSellerCode();
        $db->table('engage_sellers')->insert([
            'seller_code'    => $code,
            'application_id' => $id,
            'lead_id'        => $app['lead_id'],
            'name'           => $app['name'],
            'email'          => $app['email'],
            'mobile'         => $app['mobile'],
            'organization'   => $app['organization'],
            'entity_type'    => $app['entity_type'],
            'city'           => $app['city'],
            'state'          => $app['state'],
            'status'         => 'onboarding',
            'approved_by'    => $staff['id'],
            'status_changed_at' => Clock::now(),
            'status_changed_by' => $staff['id'],
        ]);
        $sellerId = (int) $db->insertID();
        $db->table('engage_seller_referral_codes')->insert(['seller_id' => $sellerId, 'code' => $code, 'is_primary' => true]);
        $db->table('engage_seller_applications')->where('id', $id)->update([
            'status' => 'approved', 'seller_id' => $sellerId, 'decision_reason' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'reviewed_by' => $staff['id'], 'reviewed_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        $db->transComplete();
        if (! $db->transStatus()) {
            return ['status' => 500, 'error' => 'Could not create the seller. Nothing was changed.'];
        }

        if (! empty($app['lead_id'])) {
            (new LeadActivitiesModel())->record((int) $app['lead_id'], 'seller_application', "Seller application approved — seller {$code}", $note, [
                'metadata' => ['seller_id' => $sellerId, 'seller_code' => $code],
            ]);
        }
        $seller = $this->accounts->find($sellerId);
        $delivery = (new SellerAdminService($this->accounts, $this->mailer))->deliverLink($seller, 'activation', (int) $staff['id']);
        $this->auditStaff('seller.application_approved', $id, ['seller_id' => $sellerId, 'seller_code' => $code, 'activation_email_sent' => $delivery['email_sent']]);

        return ['status' => 201, 'data' => [
            'application' => $this->find($id),
            'seller'      => SellerAdminService::staffView($seller),
            'activation'  => $delivery,
            'warnings'    => $this->accounts->currentAgreement() === null
                ? ['No seller agreement is published yet: the seller can set a password but cannot finish activation until one is published (Sellers → Agreement).']
                : [],
        ]];
    }

    private function auditStaff(string $event, int $appId, array $meta): void
    {
        Services::auditService()->log($event, [
            'subject_kind' => 'seller_application', 'subject_id' => $appId, 'metadata' => $meta, 'fanout_console' => true,
        ]);
    }

    private static function str(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : $v;
    }
}
