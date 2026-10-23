<?php
declare(strict_types=1);

namespace App\Services\Coupons;

use App\Services\Licensing\OfferService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Manage-facing coupon routes (contract §4.1, AIC-S2S client `manage`, key scope `quote`):
 *
 *   POST v1/manage/coupons/preview    {holder_uuid, offer_code, code}
 *        → {valid, reason_code, list_paise, discount_paise, net_paise, gst_rate, gst_paise, total_paise, coupon:{code,label}|null}
 *          (+ additive price_basis, taxable_paise). No reservation. Generic reason codes only.
 *   POST v1/manage/upgrade-requests   {holder_uuid, cmp_id?, offer_code, coupon_code?, contact:{name,email,phone}, request_id?}
 *        → {request_ref, quote:{…}|null} — a subscription inquiry for staff; with a valid code the quote holds a reserved
 *          redemption (14 days). Idempotent on request_id (else X-AIC-Request-Id).
 *
 * Both are rate limited per holder (AttemptLimiter) and audited as manage:<X-AIC-Actor>.
 * Every result: ['status' => int, 'data' => …] or ['status' => 4xx, 'code' => …, 'error' => …, 'details' => …, 'retry_after'?].
 */
class ManageCouponService
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    private static function bad(string $field, string $msg): array
    {
        return ['status' => 400, 'code' => 'bad_request', 'error' => $msg, 'details' => ['field' => $field]];
    }

    /** @param array{actor?:string, ip?:?string} $ctx */
    public function preview(array $in, array $ctx): array
    {
        $holder = $in['holder_uuid'] ?? null;
        if (! QuoteService::validHolder($holder)) {
            return self::bad('holder_uuid', 'holder_uuid is required.');
        }
        $offerCode = $in['offer_code'] ?? null;
        if (! is_string($offerCode) || ! preg_match('/\A[a-z0-9][a-z0-9_\-]{1,63}\z/', $offerCode)) {
            return self::bad('offer_code', 'offer_code is required.');
        }
        $code = CodeGenerator::normalise($in['code'] ?? '');
        if ($code === '' || strlen($code) > 64) {
            return self::bad('code', 'code is required.');
        }
        $limiter = new AttemptLimiter($this->db());
        $scope = 'h:' . $holder;
        if (($wait = $limiter->retryAfter('manage_preview', $scope)) !== null) {
            $limiter->record('manage_preview', $scope, $code, null, 'rate_limited', null, $ctx['ip'] ?? null);
            $this->audit('manage.coupon.preview_rate_limited', $holder, ['offer_code' => $offerCode], $ctx);

            return ['status' => 429, 'code' => 'rate_limited', 'error' => 'Too many coupon checks. Try again later.', 'retry_after' => $wait, 'details' => []];
        }

        $offer = (new OfferService($this->db()))->publishedVersion($offerCode);
        $base = ['valid' => false, 'reason_code' => 'not_applicable', 'list_paise' => null, 'discount_paise' => 0, 'net_paise' => null,
            'gst_rate' => null, 'gst_paise' => null, 'total_paise' => null, 'price_basis' => null, 'taxable_paise' => null, 'coupon' => null];
        if (! $offer || ! OfferService::isOnSale($offer)) {
            $limiter->record('manage_preview', $scope, $code, null, 'rejected', 'offer_not_on_sale', $ctx['ip'] ?? null);
            $this->audit('manage.coupon.preview', $holder, ['offer_code' => $offerCode, 'valid' => false, 'reason' => 'offer_not_on_sale'], $ctx);

            return ['status' => 200, 'data' => $base];
        }
        $list = PricingService::quote((int) $offer['price_paise'], (string) $offer['gst_rate'], (string) $offer['price_basis']);
        $priced = static fn (array $p) => ['list_paise' => $p['list_paise'], 'discount_paise' => $p['discount_paise'], 'net_paise' => $p['net_paise'],
            'gst_rate' => $p['gst_rate'], 'gst_paise' => $p['gst_paise'], 'total_paise' => $p['total_paise'], 'price_basis' => $p['price_basis'],
            'taxable_paise' => $p['taxable_paise']];
        try {
            $chk = (new RedemptionService($this->db()))->check($code, $offer, 'new', RedemptionService::holderKey($holder, null));
        } catch (CouponException $e) {
            $limiter->record('manage_preview', $scope, $code, null, 'rejected', $e->reason, $ctx['ip'] ?? null);
            $this->audit('manage.coupon.preview', $holder, ['offer_code' => $offerCode, 'valid' => false, 'reason' => $e->reason], $ctx);

            return ['status' => 200, 'data' => ['reason_code' => RedemptionService::generic($e->reason)] + $priced($list) + $base];
        }
        $limiter->record('manage_preview', $scope, $code, (int) $chk['coupon']['id'], 'valid', null, $ctx['ip'] ?? null);
        $this->audit('manage.coupon.preview', $holder, ['offer_code' => $offerCode, 'valid' => true, 'coupon_ref' => $chk['coupon']['coupon_ref']], $ctx);

        return ['status' => 200, 'data' => ['valid' => true, 'reason_code' => null] + $priced($chk['pricing'])
            + ['coupon' => ['code' => CodeGenerator::display($chk['code']['code']), 'label' => $chk['coupon']['label']]]];
    }

    /** @param array{actor?:string, ip?:?string, request_id?:string} $ctx */
    public function upgradeRequest(array $in, array $ctx): array
    {
        $holder = $in['holder_uuid'] ?? null;
        if (! QuoteService::validHolder($holder)) {
            return self::bad('holder_uuid', 'holder_uuid is required.');
        }
        $cmp = null;
        if (isset($in['cmp_id']) && $in['cmp_id'] !== null && $in['cmp_id'] !== '') {
            $cmp = QuoteService::validCmp(is_int($in['cmp_id']) ? $in['cmp_id'] : (string) $in['cmp_id']);
            if ($cmp === null) {
                return self::bad('cmp_id', 'cmp_id must be a positive company id.');
            }
        }
        $offerCode = $in['offer_code'] ?? null;
        if (! is_string($offerCode) || ! preg_match('/\A[a-z0-9][a-z0-9_\-]{1,63}\z/', $offerCode)) {
            return self::bad('offer_code', 'offer_code is required.');
        }
        $contact = $in['contact'] ?? null;
        $name = is_array($contact) && is_string($contact['name'] ?? null) ? trim($contact['name']) : '';
        $email = is_array($contact) && is_string($contact['email'] ?? null) ? strtolower(trim($contact['email'])) : '';
        $phone = is_array($contact) && is_string($contact['phone'] ?? null) ? trim($contact['phone']) : '';
        if ($name === '' || mb_strlen($name) > 191) {
            return self::bad('contact.name', 'contact.name is required.');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
            return self::bad('contact.email', 'contact.email must be an email address.');
        }
        if ($phone !== '' && ! preg_match('/\A\+?[0-9 ()\-]{6,24}\z/', $phone)) {
            return self::bad('contact.phone', 'contact.phone is not a phone number.');
        }
        $key = is_string($in['request_id'] ?? null) && $in['request_id'] !== '' ? $in['request_id'] : (string) ($ctx['request_id'] ?? '');
        if (! preg_match('/\A[A-Za-z0-9._\-]{8,96}\z/', $key)) {
            return self::bad('request_id', 'request_id must be 8–96 letters, digits or . _ -');
        }
        $requestKey = 'manage:' . $holder . ':' . $key;
        $db = $this->db();
        if ($existing = $db->table('engage_subscription_inquiries')->where('request_key', $requestKey)->get()->getRowArray()) {
            return ['status' => 200, 'data' => $this->answer($existing) + ['duplicate' => true]];
        }

        $limiter = new AttemptLimiter($db);
        $scope = 'h:' . $holder;
        if (($wait = $limiter->retryAfter('manage_upgrade', $scope)) !== null) {
            $this->audit('manage.upgrade_request.rate_limited', $holder, ['offer_code' => $offerCode], $ctx);

            return ['status' => 429, 'code' => 'rate_limited', 'error' => 'Too many upgrade requests. Try again later.', 'retry_after' => $wait, 'details' => []];
        }
        $offer = (new OfferService($db))->publishedVersion($offerCode);
        if (! $offer || ! OfferService::isOnSale($offer)) {
            return ['status' => 422, 'code' => 'offer_not_available', 'error' => 'That plan is not on sale.', 'details' => ['field' => 'offer_code']];
        }
        $lead = $db->query('SELECT id FROM engage_leads WHERE lower(email) = ? ORDER BY id DESC LIMIT 2', [$email])->getResultArray();
        $leadId = count($lead) === 1 ? (int) $lead[0]['id'] : null;

        $code = CodeGenerator::normalise($in['coupon_code'] ?? '');
        $quote = (new QuoteService($db))->create([
            'holder_uuid' => $holder, 'cmp_id' => $cmp, 'offer_code' => $offerCode, 'coupon_code' => $code, 'kind' => 'new', 'lead_id' => $leadId,
            'idempotency_key' => 'upgrade:' . substr(hash('sha256', $requestKey), 0, 48),
        ], ['source' => 'manage', 'channel' => 'manage_upgrade', 'scope_key' => $scope, 'ip' => $ctx['ip'] ?? null, 'coupon_optional' => true,
            'actor' => $ctx['actor'] ?? null]);
        if (! in_array($quote['status'], [200, 201], true)) {
            log_message('error', 'Upgrade request quote failed: ' . json_encode($quote));

            return ['status' => 503, 'code' => 'database_unavailable', 'error' => 'Engage is temporarily unavailable.', 'details' => [], 'retryable' => true];
        }
        $q = $quote['data'];
        if ($code === '') { // with a code QuoteService already logged the attempt (reserved / rejected)
            $limiter->record('manage_upgrade', $scope, null, null, 'no_code', null, $ctx['ip'] ?? null);
        }

        $productId = $db->table('engage_products')->select('id')->where('code', $offer['product_code'])->get()->getRow()->id ?? null;
        $n = (int) $db->query("SELECT nextval('engage_upgrade_request_ref_seq') AS n")->getRow()->n;
        $requestRef = 'UPG-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        $notes = 'Upgrade request from Manage: ' . $offer['label'] . ' (' . $offerCode . ')' . ($q['coupon'] !== null ? ' with coupon ' . $q['coupon']['code'] : '')
            . '. Quote ' . $q['quote_ref'] . ', total ₹' . number_format($q['total_paise'] / 100, 2, '.', '') . '.';
        try {
            $db->query(
                "INSERT INTO engage_subscription_inquiries (lead_id, product_id, billing_cycle, target_amount, currency, status, notes, metadata,
                    request_ref, request_key, source, holder_uuid, cmp_id, offer_code, quote_ref, contact_name, contact_email, contact_phone)
                 VALUES (?, ?, 'plan', CAST(? AS NUMERIC), 'INR', 'inquiry', ?, ?::jsonb, ?, ?, 'manage', ?, ?, ?, ?, ?, ?, ?)",
                [$leadId, $productId, number_format($q['total_paise'] / 100, 2, '.', ''), $notes, json_encode([
                    'source' => 'manage_upgrade_request', 'request_ref' => $requestRef, 'quote_ref' => $q['quote_ref'], 'offer_code' => $offerCode,
                    'coupon_code' => $q['coupon']['code'] ?? null, 'coupon_reason_code' => $q['coupon_reason_code'] ?? null,
                    'list_paise' => $q['list_paise'], 'discount_paise' => $q['discount_paise'], 'net_paise' => $q['net_paise'], 'total_paise' => $q['total_paise'],
                ]), $requestRef, $requestKey, $holder, $cmp, $offerCode, $q['quote_ref'], $name, $email, $phone !== '' ? $phone : null]
            );
        } catch (\Throwable $e) {
            if ($existing = $db->table('engage_subscription_inquiries')->where('request_key', $requestKey)->get()->getRowArray()) {
                return ['status' => 200, 'data' => $this->answer($existing) + ['duplicate' => true]];
            }
            log_message('error', 'Upgrade request insert failed: ' . $e->getMessage());

            return ['status' => 503, 'code' => 'database_unavailable', 'error' => 'Engage is temporarily unavailable.', 'details' => [], 'retryable' => true];
        }
        $this->audit('manage.upgrade_request.created', $holder, ['request_ref' => $requestRef, 'quote_ref' => $q['quote_ref'], 'offer_code' => $offerCode,
            'cmp_id' => $cmp, 'coupon_reserved' => $q['coupon'] !== null, 'coupon_reason' => $q['coupon_reason'] ?? null], $ctx, true);

        $row = $db->table('engage_subscription_inquiries')->where('request_key', $requestKey)->get()->getRowArray();

        return ['status' => 201, 'data' => $this->answer($row)];
    }

    /** {request_ref, quote|null, coupon_reason_code?} for a stored request. */
    private function answer(array $inquiry): array
    {
        $q = $inquiry['quote_ref'] ? (new QuoteService($this->db()))->findBy('quote_ref', (string) $inquiry['quote_ref']) : null;
        $meta = json_decode((string) ($inquiry['metadata'] ?? '{}'), true) ?: [];
        $quote = null;
        if ($q && $q['coupon_id'] !== null) {
            $v = (new QuoteService($this->db()))->view($q);
            $quote = [
                'quote_ref' => $v['quote_ref'], 'list_paise' => $v['list_paise'], 'discount_paise' => $v['discount_paise'], 'net_paise' => $v['net_paise'],
                'gst_rate' => $v['gst_rate'], 'gst_paise' => $v['gst_paise'], 'total_paise' => $v['total_paise'], 'price_basis' => $v['price_basis'],
                'taxable_paise' => $v['taxable_paise'], 'coupon' => ['code' => CodeGenerator::display((string) $v['coupon']['code']), 'label' => $v['coupon']['label']],
                'expires_at' => $v['expires_at'],
            ];
        }

        return ['request_ref' => $inquiry['request_ref'], 'quote' => $quote]
            + (! empty($meta['coupon_reason_code']) ? ['coupon_reason_code' => $meta['coupon_reason_code']] : []);
    }

    private function audit(string $event, string $holder, array $meta, array $ctx, bool $fanout = false): void
    {
        Services::auditService()->log($event, [
            'actor_id' => null, 'actor_email' => 'manage:' . ($ctx['actor'] ?? 'unknown'), 'actor_role' => 'service:manage',
            'subject_kind' => 'holder', 'subject_id' => $holder, 'fanout_console' => $fanout,
            'metadata' => $meta + ['request_id' => $ctx['request_id'] ?? null, 'key_id' => $ctx['key_id'] ?? null],
        ]);
    }
}
