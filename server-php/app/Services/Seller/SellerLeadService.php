<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use App\Models\LeadActivitiesModel;
use App\Models\LeadSourcesModel;
use App\Models\LeadsModel;
use App\Models\ProductsModel;
use App\Models\SettingsModel;
use Config\Services;

/**
 * Seller attribution of Engage leads.
 *
 * Registration from seller.aicountly.com creates a lead (source_type 'seller') protected for that seller
 * for seller_lead_protection_days (default 90). First valid registration wins. A registration is refused
 * (and logged in engage_seller_lead_registrations) when the contact (email case-insensitive, else mobile):
 *   - is protected for a seller (this one: duplicate_lead; another one: lead_protected — never named);
 *   - is already a customer (converted / won);
 *   - is already in the sales pipeline (created or worked on within the protection window).
 * An older, unprotected, not-converted lead is claimed: it keeps its history and owner and gains the seller.
 *
 * Referral (`ref` = seller code, carried by Reach) attributes a new lead, or an existing unprotected open
 * lead, to the seller with the same protection window. Converted / lost leads and leads protected for
 * another seller are never re-attributed.
 */
class SellerLeadService
{
    public const ATTRIBUTABLE_STATUSES = ['kyc_pending', 'active'];

    public function __construct(private ?SellerAccountService $accounts = null)
    {
        $this->accounts ??= Services::sellerAccounts();
    }

    public static function protectionDays(): int
    {
        $d = (int) (new SettingsModel())->getSetting('seller_lead_protection_days', 90);

        return $d >= 1 && $d <= 365 ? $d : 90;
    }

    public static function isProtected(array $lead, ?int $now = null): bool
    {
        $until = (string) ($lead['seller_protection_expires_at'] ?? '');

        return ! empty($lead['seller_id']) && $until !== '' && (int) strtotime($until) > ($now ?? time());
    }

    public static function isClosedWon(array $lead): bool
    {
        return ($lead['stage'] ?? '') === 'converted' || ($lead['sales_status'] ?? '') === 'won';
    }

    public static function isTerminal(array $lead): bool
    {
        return self::isClosedWon($lead) || in_array($lead['stage'] ?? '', ['lost', 'not_relevant'], true) || ($lead['sales_status'] ?? '') === 'lost';
    }

    /** @return array<string,string> */
    public static function validate(array $in): array
    {
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 191) {
            $e['name'] = 'Enter the contact name.';
        }
        $email = trim((string) ($in['email'] ?? ''));
        $mobile = trim((string) ($in['mobile'] ?? ''));
        if ($email === '' && $mobile === '') {
            $e['email'] = 'Give an email address or a mobile number.';
        }
        if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191)) {
            $e['email'] = 'Enter a valid email address.';
        }
        if ($mobile !== '' && (LeadsModel::normaliseMobile($mobile) === null || strlen(preg_replace('/\D+/', '', $mobile) ?? '') > 15)) {
            $e['mobile'] = 'Enter a valid mobile number.';
        }
        foreach (['company' => 191, 'organization' => 191, 'city' => 96, 'state' => 96, 'interest' => 191, 'notes' => 2000] as $f => $max) {
            if (mb_strlen((string) ($in[$f] ?? '')) > $max) {
                $e[$f] = "Keep this under {$max} characters.";
            }
        }
        if (isset($in['expected_users']) && $in['expected_users'] !== '' && $in['expected_users'] !== null
            && (! is_numeric($in['expected_users']) || (int) $in['expected_users'] < 0 || (int) $in['expected_users'] > 1000000)) {
            $e['expected_users'] = 'Enter a number of users.';
        }

        return $e;
    }

    /** @return array<string,mixed> */
    public function register(array $seller, array $in): array
    {
        if ($block = $this->accounts->writeBlock($seller)) {
            return $block;
        }
        if (($in['consent_confirmed'] ?? true) !== true) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Confirm that the contact agreed to be contacted.', 'details' => ['consent_confirmed' => 'Required.']];
        }
        $in['company'] ??= $in['organization'] ?? null;
        $errors = self::validate($in);
        // `interest` is free text from the portal; a product code / name in it links the product.
        $interest = trim((string) ($in['interest'] ?? $in['interested_product'] ?? ''));
        $product = (new ProductsModel())->resolve($interest);
        if ($errors !== []) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the highlighted fields.', 'details' => $errors];
        }

        $email  = trim((string) ($in['email'] ?? ''));
        $mobile = trim((string) ($in['mobile'] ?? ''));
        $leads  = new LeadsModel();
        $days   = self::protectionDays();
        [$existing, $matchedOn] = $leads->findDuplicate($email, $mobile);
        $log = fn (string $outcome, ?int $leadId, ?string $reason, ?string $until = null) => $this->accounts->db()->table('engage_seller_lead_registrations')->insert([
            'seller_id' => (int) $seller['id'], 'lead_id' => $leadId, 'outcome' => $outcome, 'reason' => $reason,
            'email' => $email !== '' ? strtolower($email) : null, 'mobile_normalized' => LeadsModel::normaliseMobile($mobile),
            'protection_expires_at' => $until,
        ]);

        if ($existing) {
            if (self::isProtected($existing)) {
                if ((int) $existing['seller_id'] === (int) $seller['id']) {
                    $log('rejected_duplicate', (int) $existing['id'], 'already registered by this seller');
                    $on = self::istDate((string) ($existing['seller_attributed_at'] ?? $existing['created_at']), 'j M Y');
                    $until = self::istDate((string) $existing['seller_protection_expires_at'], 'j M Y');

                    return ['status' => 409, 'code' => 'duplicate_lead',
                        'error' => "You registered this lead on {$on}; it is protected for you until {$until}.",
                        'details' => ['lead_code' => $existing['lead_code'], 'protected_until' => self::istDate((string) $existing['seller_protection_expires_at'])]];
                }
                $log('rejected_protected', (int) $existing['id'], 'protected for another seller');

                return self::protectedAnswer();
            }
            if (self::isClosedWon($existing)) {
                $log('rejected_customer', (int) $existing['id'], 'already a customer');

                return self::protectedAnswer();
            }
            $recent = max((int) strtotime((string) $existing['created_at']), (int) strtotime((string) ($existing['updated_at'] ?? $existing['created_at'])));
            if ($recent > time() - $days * 86400) {
                $log('rejected_duplicate', (int) $existing['id'], "matched by {$matchedOn}; already in the pipeline");

                return self::protectedAnswer();
            }
            // Stale, unprotected, open: the seller claims it.
            $until = Clock::at(time() + $days * 86400);
            $this->attach((int) $existing['id'], (int) $seller['id'], 'registration', $until);
            (new LeadActivitiesModel())->record((int) $existing['id'], 'seller_claim', 'Registered by seller ' . $seller['seller_code'], self::str($in['notes'] ?? null), [
                'author_kind' => 'seller', 'metadata' => ['seller_id' => (int) $seller['id'], 'matched_on' => $matchedOn, 'protected_until' => $until],
            ]);
            $log('claimed', (int) $existing['id'], "matched by {$matchedOn}; stale lead claimed", $until);
            Services::sellerAuth()->audit('seller.lead_claimed', $seller, ['lead_id' => (int) $existing['id']]);

            return ['status' => 201, 'data' => ['lead' => self::registeredView($leads->find((int) $existing['id'])), 'result' => 'claimed']];
        }

        $until = Clock::at(time() + $days * 86400);
        $source = (new LeadSourcesModel())->where('code', 'seller')->first();
        $leadId = (int) $leads->insert([
            'lead_code'             => $leads->generateLeadCode(),
            'name'                  => trim((string) $in['name']),
            'organization'          => self::str($in['company'] ?? null),
            'email'                 => $email !== '' ? strtolower($email) : null,
            'mobile'                => $mobile !== '' ? $mobile : null,
            'source_portal'         => 'seller.aicountly.com',
            'source_campaign'       => $seller['seller_code'],
            'source_type'           => 'seller',
            'lead_source_id'        => $source['id'] ?? null,
            'interested_product'    => $product['code'] ?? null,
            'interested_product_id' => $product['id'] ?? null,
            'subscription_interest' => $interest !== '' ? mb_substr($interest, 0, 191) : null,
            'expected_users'        => isset($in['expected_users']) && $in['expected_users'] !== '' ? (int) $in['expected_users'] : null,
            'notes'                 => self::str($in['notes'] ?? null),
            'stage'                 => 'new',
            'priority'              => 'normal',
            'sales_status'          => 'open',
            'seller_id'             => (int) $seller['id'],
            'seller_attribution'    => 'registration',
            'seller_attributed_at'  => Clock::now(),
            'seller_protection_expires_at' => $until,
            'metadata'              => ['seller_registration' => ['city' => self::str($in['city'] ?? null), 'state' => self::str($in['state'] ?? null),
                                        'consent_confirmed' => ($in['consent_confirmed'] ?? null) === true]],
        ], true);
        (new LeadActivitiesModel())->record($leadId, 'seller_registration', 'Registered by seller ' . $seller['seller_code'], null, [
            'author_kind' => 'seller', 'metadata' => ['seller_id' => (int) $seller['id'], 'protected_until' => $until],
        ]);
        $log('accepted', $leadId, null, $until);
        Services::sellerAuth()->audit('seller.lead_registered', $seller, ['lead_id' => $leadId]);

        return ['status' => 201, 'data' => ['lead' => self::registeredView($leads->find($leadId)), 'result' => 'created']];
    }

    private function attach(int $leadId, int $sellerId, string $how, string $until): void
    {
        (new LeadsModel())->update($leadId, [
            'seller_id' => $sellerId, 'seller_attribution' => $how, 'seller_attributed_at' => Clock::now(), 'seller_protection_expires_at' => $until,
        ]);
    }

    /**
     * Attribute a lead to the seller behind a `ref` code (Reach intake). Returns what happened.
     *
     * @return array{status:string, seller_code?:string, reason?:string}
     */
    public function applyReferral(array $lead, string $ref): array
    {
        $ref = strtoupper(trim($ref));
        if ($ref === '') {
            return ['status' => 'none'];
        }
        if (! preg_match('/\A[A-Z0-9\-]{3,32}\z/', $ref)) {
            return ['status' => 'ignored', 'reason' => 'malformed ref'];
        }
        $seller = $this->accounts->findByCode($ref);
        if (! $seller || ! in_array($seller['status'], self::ATTRIBUTABLE_STATUSES, true)) {
            return ['status' => 'ignored', 'reason' => 'no active seller for this ref'];
        }
        if (self::isTerminal($lead)) {
            return ['status' => 'ignored', 'reason' => 'lead already closed', 'seller_code' => $seller['seller_code']];
        }
        if (self::isProtected($lead)) {
            return (int) $lead['seller_id'] === (int) $seller['id']
                ? ['status' => 'kept', 'seller_code' => $seller['seller_code']]
                : ['status' => 'ignored', 'reason' => 'protected for another seller', 'seller_code' => $seller['seller_code']];
        }
        $until = Clock::at(time() + self::protectionDays() * 86400);
        $this->attach((int) $lead['id'], (int) $seller['id'], 'referral', $until);
        (new LeadActivitiesModel())->record((int) $lead['id'], 'seller_referral', 'Attributed to seller ' . $seller['seller_code'] . ' (referral link)', null, [
            'author_kind' => 'system', 'metadata' => ['seller_id' => (int) $seller['id'], 'protected_until' => $until],
        ]);

        return ['status' => 'attributed', 'seller_code' => $seller['seller_code']];
    }

    private static function protectedAnswer(): array
    {
        return ['status' => 409, 'code' => 'lead_protected', 'error' => 'This contact is already registered with AICOUNTLY.'];
    }

    /** IST calendar date of a timestamp (YYYY-MM-DD by default). */
    public static function istDate(?string $ts, string $format = 'Y-m-d'): ?string
    {
        if ($ts === null || $ts === '' || ($t = strtotime($ts)) === false) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format($format);
    }

    /** Stage → seller-portal status (registered | contacted | qualified | demo | proposal_sent | converted | lost | expired). */
    public const STATUS_STAGES = [
        'registered'    => ['new'],
        'contacted'     => ['contacted', 'nurture'],
        'qualified'     => ['qualified'],
        'demo'          => ['demo_required'],
        'proposal_sent' => ['proposal_required', 'proposal_sent', 'negotiation', 'waiting_for_approval'],
        'converted'     => ['converted'],
        'lost'          => ['lost', 'not_relevant'],
    ];

    public static function sellerStatus(array $lead): string
    {
        if (self::isClosedWon($lead)) {
            return 'converted';
        }
        $stage = (string) ($lead['stage'] ?? 'new');
        if (in_array($stage, self::STATUS_STAGES['lost'], true) || ($lead['sales_status'] ?? '') === 'lost') {
            return 'lost';
        }
        if (! empty($lead['seller_protection_expires_at']) && ! self::isProtected($lead)) {
            return 'expired';
        }
        foreach (self::STATUS_STAGES as $status => $stages) {
            if (in_array($stage, $stages, true)) {
                return $status;
            }
        }

        return 'contacted';
    }

    /** POST leads answer. @param array<string,mixed> $l */
    public static function registeredView(array $l): array
    {
        return [
            'lead_code'       => $l['lead_code'],
            'name'            => $l['name'],
            'status'          => self::sellerStatus($l),
            'registered_at'   => Clock::iso($l['seller_attributed_at'] ?? $l['created_at']),
            'protected_until' => self::istDate($l['seller_protection_expires_at'] ?? null),
        ];
    }

    /** GET leads item. @param array<string,mixed> $l */
    public static function sellerView(array $l): array
    {
        return [
            'lead_code'       => $l['lead_code'],
            'name'            => $l['name'],
            'company'         => $l['organization'],
            'email_masked'    => SellerAccountService::maskEmail($l['email'] ?? null),
            'mobile_masked'   => SellerAccountService::maskMobile($l['mobile'] ?? $l['whatsapp'] ?? null),
            'status'          => self::sellerStatus($l),
            'registered_at'   => Clock::iso($l['seller_attributed_at'] ?? $l['created_at']),
            'protected_until' => self::istDate($l['seller_protection_expires_at'] ?? null),
            'converted_at'    => Clock::iso($l['converted_at'] ?? null),
            'source'          => ($l['seller_attribution'] ?? '') === 'referral' ? 'referral_link' : 'registered',
        ];
    }

    /** @return array<string,mixed> */
    public function list(array $seller, array $q, int $limit, int $offset): array
    {
        $b = $this->accounts->db()->table('engage_leads')->where('seller_id', (int) $seller['id']);
        $status = (string) ($q['status'] ?? '');
        if ($status === 'converted') {
            $b->groupStart()->where('stage', 'converted')->orWhere('sales_status', 'won')->groupEnd();
        } elseif ($status === 'lost') {
            $b->whereIn('stage', self::STATUS_STAGES['lost']);
        } elseif ($status === 'expired') {
            $b->whereNotIn('stage', ['converted', 'lost', 'not_relevant'])->where('seller_protection_expires_at <=', Clock::now());
        } elseif (isset(self::STATUS_STAGES[$status])) {
            $b->whereIn('stage', self::STATUS_STAGES[$status])->groupStart()
                ->where('seller_protection_expires_at >', Clock::now())->orWhere('seller_protection_expires_at', null)->groupEnd();
        }
        if (! empty($q['q'])) {
            $b->groupStart()->like('name', (string) $q['q'], 'both', null, true)->orLike('organization', (string) $q['q'], 'both', null, true)
                ->orLike('lead_code', (string) $q['q'], 'both', null, true)->groupEnd();
        }
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['items' => array_map([self::class, 'sellerView'], $rows), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function show(array $seller, string $leadCode): ?array
    {
        $row = $this->accounts->db()->table('engage_leads')->where('seller_id', (int) $seller['id'])->where('lead_code', $leadCode)->get()->getRowArray();

        return $row ? self::sellerView($row) : null;
    }

    private static function str(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : $v;
    }
}
