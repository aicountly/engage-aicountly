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
    public const PROFILES = ['reseller', 'associate', 'business_facilitator', 'consultant', 'other'];

    public function __construct(private ?SellerAccountService $accounts = null, private ?SellerMailer $mailer = null)
    {
        $this->accounts ??= Services::sellerAccounts();
        $this->mailer   ??= Services::sellerMailer();
    }

    /** @return array<string,string> field → message */
    public static function validate(array $in): array
    {
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
        $profile = (string) ($in['profile'] ?? '');
        if ($profile !== '' && ! in_array($profile, self::PROFILES, true)) {
            $e['profile'] = 'Choose one of: ' . implode(', ', self::PROFILES) . '.';
        }
        foreach (['organization' => 191, 'city' => 96, 'state' => 96] as $f => $max) {
            if (mb_strlen((string) ($in[$f] ?? '')) > $max) {
                $e[$f] = "Keep this under {$max} characters.";
            }
        }
        if (mb_strlen((string) ($in['message'] ?? '')) > 2000) {
            $e['message'] = 'Keep the message under 2000 characters.';
        }

        return $e;
    }

    /**
     * @return array{ok:bool, errors?:array<string,string>, created?:bool, application_id?:int}
     */
    public function submit(array $in, string $ip, string $ua): array
    {
        $errors = self::validate($in);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $db = $this->accounts->db();
        $email = strtolower(trim((string) $in['email']));
        $open = $db->query("SELECT id FROM engage_seller_applications WHERE LOWER(email) = ? AND status IN ('requested','under_review') LIMIT 1", [$email])->getRowArray();
        if ($open || $this->accounts->findByEmail($email)) {
            log_message('info', 'Seller application not stored: an open application or seller already exists for this email.');

            return ['ok' => true, 'created' => false];
        }

        $name = trim((string) $in['name']);
        $leads = new LeadsModel();
        $source = (new LeadSourcesModel())->where('code', 'seller_request')->first();
        $leadId = (int) $leads->insert([
            'lead_code'      => $leads->generateLeadCode(),
            'name'           => $name,
            'organization'   => self::str($in['organization'] ?? null),
            'email'          => trim((string) $in['email']),
            'mobile'         => trim((string) $in['mobile']),
            'source_portal'  => 'seller.aicountly.com',
            'source_campaign'=> 'seller_programme',
            'source_type'    => 'seller_request',
            'lead_source_id' => $source['id'] ?? null,
            'notes'          => self::str($in['message'] ?? null),
            'stage'          => 'new',
            'priority'       => 'normal',
            'sales_status'   => 'open',
            'metadata'       => ['seller_application' => ['profile' => $in['profile'] ?? null, 'entity_type' => $in['entity_type'] ?? null,
                                 'city' => $in['city'] ?? null, 'state' => $in['state'] ?? null]],
        ], true);

        $n = (int) $db->query("SELECT nextval('engage_seller_application_code_seq') AS n")->getRow()->n;
        try {
            $db->table('engage_seller_applications')->insert([
                'application_code' => 'SAP-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                'name'         => $name,
                'email'        => trim((string) $in['email']),
                'mobile'       => trim((string) $in['mobile']),
                'organization' => self::str($in['organization'] ?? null),
                'entity_type'  => self::str($in['entity_type'] ?? null),
                'city'         => self::str($in['city'] ?? null),
                'state'        => self::str($in['state'] ?? null),
                'profile'      => self::str($in['profile'] ?? null),
                'message'      => self::str($in['message'] ?? null),
                'lead_id'      => $leadId,
                'ip_address'   => substr($ip, 0, 64),
                'user_agent'   => substr($ua, 0, 255),
            ]);
        } catch (\Throwable $e) {
            // Unique open-application index: a concurrent duplicate. Same public answer.
            log_message('warning', 'Seller application insert refused: ' . $e->getMessage());

            return ['ok' => true, 'created' => false];
        }
        $appId = (int) $db->insertID();
        (new LeadActivitiesModel())->record($leadId, 'seller_application', 'Seller programme request received', null, [
            'author_kind' => 'system', 'metadata' => ['application_id' => $appId],
        ]);
        Services::auditService()->log('seller.application_received', [
            'actor_email' => trim((string) $in['email']), 'actor_role' => 'applicant',
            'subject_kind' => 'seller_application', 'subject_id' => $appId, 'metadata' => ['lead_id' => $leadId],
        ]);

        return ['ok' => true, 'created' => true, 'application_id' => $appId];
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
