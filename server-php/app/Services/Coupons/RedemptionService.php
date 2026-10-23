<?php
declare(strict_types=1);

namespace App\Services\Coupons;

use App\Libraries\Licensing\LicensingSupport as S;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * The redemption ledger (engage_coupon_redemptions) — docs/COUPONS.md §5.
 *
 *   reserve   a quote holds one use of the coupon: state reserved, reserved_until = now + 14 days
 *   commit    the licence was issued / renewed with that quote_ref: reserved → committed (+ licence_ref), in the
 *             licence's own transaction (CouponLicenceHook)
 *   release   reserved → released (staff, or engage:coupons-expire once reserved_until has passed)
 *   reverse   committed → reversed (staff, reason required — e.g. the invoice was credited in full)
 *
 * Limits (max_redemptions_total, max_redemptions_per_holder) count LIVE uses: committed + reserved whose
 * reserved_until is still ahead. They are checked under row locks taken in one fixed order — code row, then coupon
 * row, then redemption row — so two concurrent reservations of the last use cannot both succeed and no two writers
 * can deadlock. A single-use code additionally has at most one live redemption (partial unique index).
 *
 * The per-holder key is the my.aicountly holder uuid when known, else the Manage company (cmp_id).
 */
class RedemptionService
{
    public const RESERVE_DAYS = 14;

    /** Detailed reason (staff) → generic reason (Manage / customer). */
    public const GENERIC = [
        'unknown_code' => 'invalid_or_expired', 'code_void' => 'invalid_or_expired', 'coupon_inactive' => 'invalid_or_expired',
        'coupon_paused' => 'invalid_or_expired', 'coupon_retired' => 'invalid_or_expired', 'coupon_expired' => 'invalid_or_expired',
        'not_yet_valid' => 'invalid_or_expired',
        'coupon_exhausted' => 'limit_reached', 'code_used' => 'limit_reached', 'total_limit' => 'limit_reached', 'holder_limit' => 'limit_reached',
        'wrong_product' => 'not_applicable', 'wrong_offer' => 'not_applicable', 'wrong_licence_type' => 'not_applicable',
        'wrong_kind' => 'not_applicable', 'below_min_order' => 'not_applicable', 'no_discount' => 'not_applicable',
        'offer_not_on_sale' => 'not_applicable',
    ];

    public const REASON_MESSAGES = [
        'unknown_code' => 'No coupon has this code.',
        'code_void' => 'This code was voided.',
        'coupon_inactive' => 'This coupon is not active yet (still a draft).',
        'coupon_paused' => 'This coupon is paused.',
        'coupon_retired' => 'This coupon was retired.',
        'coupon_expired' => 'This coupon has expired.',
        'not_yet_valid' => 'This coupon is not valid yet.',
        'coupon_exhausted' => 'This coupon has been used up.',
        'code_used' => 'This single-use code has already been used (or is held by another quote).',
        'total_limit' => 'This coupon has reached its total redemption limit.',
        'holder_limit' => 'This customer has already used this coupon as often as allowed.',
        'wrong_product' => 'This coupon does not apply to this product.',
        'wrong_offer' => 'This coupon does not apply to this offer.',
        'wrong_licence_type' => 'This coupon does not apply to this licence type.',
        'wrong_kind' => 'This coupon does not apply to this kind of purchase (new / renewal).',
        'below_min_order' => 'The offer price is below the coupon\'s minimum order value.',
        'no_discount' => 'The coupon gives no discount on this price (the net may not fall below ₹1).',
        'offer_not_on_sale' => 'That offer is not published and on sale.',
        'quote_not_found' => 'No quote has this quote_ref.',
        'quote_expired' => 'This quote has expired — create a new quote.',
        'quote_used' => 'This quote was already used for another licence.',
        'quote_mismatch' => 'This quote was made for a different offer, customer or company.',
        'reservation_lost' => 'The coupon reservation of this quote is no longer held — create a new quote.',
        'write_failed' => 'The quote could not be recorded with the licence.',
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    public function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    public static function generic(string $reason): string
    {
        return self::GENERIC[$reason] ?? 'invalid_or_expired';
    }

    public static function holderKey(?string $holderUuid, ?int $cmpId): string
    {
        return $holderUuid !== null && $holderUuid !== '' ? 'h:' . $holderUuid : 'c:' . (int) $cmpId;
    }

    private const LIVE = "(state = 'committed' OR (state = 'reserved' AND reserved_until > NOW()))";

    /**
     * Check a code against an offer for a holder. With $lock (inside the caller's transaction) the code and coupon rows
     * are locked FOR UPDATE and a stale reservation still holding a single-use code is released first.
     *
     * @param array<string,mixed> $offer an engage_offers row (OfferService::publishedVersion)
     * @return array{coupon: array<string,mixed>, code: array<string,mixed>, pricing: array<string,mixed>}
     * @throws CouponException
     */
    public function check(string $code, array $offer, string $kind, string $holderKey, bool $lock = false): array
    {
        $db = $this->db();
        $code = CodeGenerator::normalise($code);
        if (! CodeGenerator::isPlausible($code)) {
            throw new CouponException('unknown_code');
        }
        $forUpdate = $lock ? ' FOR UPDATE' : '';
        $k = $db->query('SELECT * FROM engage_coupon_codes WHERE code = ?' . $forUpdate, [$code])->getRowArray();
        if (! $k) {
            throw new CouponException('unknown_code');
        }
        $c = $db->query('SELECT * FROM engage_coupons WHERE id = ?' . $forUpdate, [(int) $k['coupon_id']])->getRowArray();
        $reason = match ($c['status']) {
            'draft' => 'coupon_inactive', 'paused' => 'coupon_paused', 'retired' => 'coupon_retired',
            'expired' => 'coupon_expired', 'exhausted' => 'coupon_exhausted', default => null,
        };
        if ($reason === null && $c['valid_from'] !== null && strtotime((string) $c['valid_from']) > time()) {
            $reason = 'not_yet_valid';
        }
        if ($reason === null && $c['valid_to'] !== null && strtotime((string) $c['valid_to']) <= time()) {
            $reason = 'coupon_expired';
        }
        if ($reason === null && $k['status'] === 'void') {
            $reason = 'code_void';
        }
        if ($reason !== null) {
            throw new CouponException($reason);
        }
        if ($k['kind'] === 'single_use' && $k['status'] !== 'available') {
            $held = $db->query("SELECT id, reserved_until FROM engage_coupon_redemptions WHERE code_id = ? AND state IN ('reserved','committed')
                AND NOT (state = 'reserved' AND reserved_until <= NOW())", [(int) $k['id']])->getRowArray();
            if ($held || $k['status'] === 'redeemed') {
                throw new CouponException('code_used');
            }
            if ($lock) { // the reservation that held it has lapsed: release it now (the job would anyway)
                $this->releaseStaleForCode((int) $k['id']);
                $k['status'] = 'available';
            }
        }

        $applies = S::json($c['applies_to']);
        if (($applies['product_codes'] ?? []) !== [] && ! in_array($offer['product_code'], $applies['product_codes'], true)) {
            throw new CouponException('wrong_product');
        }
        if (($applies['offer_codes'] ?? []) !== [] && ! in_array($offer['offer_code'], $applies['offer_codes'], true)) {
            throw new CouponException('wrong_offer');
        }
        if (($applies['licence_type_codes'] ?? []) !== [] && ! in_array($offer['licence_type_code'], $applies['licence_type_codes'], true)) {
            throw new CouponException('wrong_licence_type');
        }
        if ($c['new_or_renewal'] !== 'any' && $c['new_or_renewal'] !== $kind) {
            throw new CouponException('wrong_kind');
        }
        $list = (int) $offer['price_paise'];
        if ($c['min_order_paise'] !== null && $list < (int) $c['min_order_paise']) {
            throw new CouponException('below_min_order');
        }
        $pricing = PricingService::quote($list, (string) $offer['gst_rate'], (string) $offer['price_basis'], $c);
        if ($pricing['discount_paise'] < 1) {
            throw new CouponException('no_discount');
        }
        if ($c['max_redemptions_total'] !== null) {
            $n = (int) $db->query('SELECT COUNT(*) AS n FROM engage_coupon_redemptions WHERE coupon_id = ? AND ' . self::LIVE, [(int) $c['id']])->getRow()->n;
            if ($n >= (int) $c['max_redemptions_total']) {
                throw new CouponException('total_limit');
            }
        }
        if ($c['max_redemptions_per_holder'] !== null) {
            $n = (int) $db->query('SELECT COUNT(*) AS n FROM engage_coupon_redemptions WHERE coupon_id = ? AND holder_key = ? AND ' . self::LIVE,
                [(int) $c['id'], $holderKey])->getRow()->n;
            if ($n >= (int) $c['max_redemptions_per_holder']) {
                throw new CouponException('holder_limit');
            }
        }

        return ['coupon' => $c, 'code' => $k, 'pricing' => $pricing];
    }

    /**
     * Reserve one use. MUST run inside the caller's transaction (QuoteService). Idempotent on idempotency_key.
     *
     * @param array{code:string, offer:array, kind:string, holder_uuid:?string, cmp_id:?int, lead_id:?int, quote_ref:string,
     *              idempotency_key:string, reserved_until:string, source:string, staff_id:?int} $a
     * @return array<string,mixed> the redemption row
     * @throws CouponException
     */
    public function reserve(array $a): array
    {
        $db = $this->db();
        $existing = $db->query('SELECT * FROM engage_coupon_redemptions WHERE idempotency_key = ?', [$a['idempotency_key']])->getRowArray();
        if ($existing) {
            return $existing;
        }
        $holderKey = self::holderKey($a['holder_uuid'], $a['cmp_id']);
        $chk = $this->check($a['code'], $a['offer'], $a['kind'], $holderKey, true);
        $c = $chk['coupon'];
        $k = $chk['code'];
        $p = $chk['pricing'];

        $sellerId = null;
        if ($a['lead_id'] !== null) {
            $lead = $db->table('engage_leads')->where('id', $a['lead_id'])->get()->getRowArray();
            if ($lead && $c['seller_id'] !== null) {
                // A seller-owned coupon is a referral: the usual first-valid-registration / 90-day protection rules decide.
                $seller = $db->table('engage_sellers')->where('id', (int) $c['seller_id'])->get()->getRowArray();
                $res = $seller ? Services::sellerLeads()->applyReferral($lead, (string) $seller['seller_code']) : ['status' => 'ignored'];
                if (($res['status'] ?? '') === 'ignored') {
                    Services::auditService()->log('coupon.seller_referral_ignored', ['subject_kind' => 'lead', 'subject_id' => (int) $lead['id'],
                        'metadata' => ['coupon_ref' => $c['coupon_ref'], 'reason' => $res['reason'] ?? null]]);
                }
                $lead = $db->table('engage_leads')->where('id', $a['lead_id'])->get()->getRowArray();
            }
            $sellerId = $lead && ! empty($lead['seller_id']) ? (int) $lead['seller_id'] : null;
        } elseif ($c['seller_id'] !== null) {
            $sellerId = (int) $c['seller_id'];
        }

        $row = $db->query(
            'INSERT INTO engage_coupon_redemptions (coupon_id, code_id, code, single_use, holder_uuid, cmp_id, holder_key, lead_id, seller_id, kind,
                offer_code, offer_version, product_code, price_basis, gst_rate, quote_ref, list_paise, discount_paise, net_paise, taxable_paise,
                gst_paise, total_paise, state, reserved_until, idempotency_key, source, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS NUMERIC), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING *',
            [(int) $c['id'], (int) $k['id'], $k['code'], $k['kind'] === 'single_use' ? 't' : 'f', $a['holder_uuid'], $a['cmp_id'], $holderKey,
                $a['lead_id'], $sellerId, $a['kind'], $a['offer']['offer_code'], (int) $a['offer']['version'], $a['offer']['product_code'],
                $p['price_basis'], (string) $a['offer']['gst_rate'], $a['quote_ref'], $p['list_paise'], $p['discount_paise'], $p['net_paise'],
                $p['taxable_paise'], $p['gst_paise'], $p['total_paise'], 'reserved', $a['reserved_until'], $a['idempotency_key'], $a['source'],
                $a['staff_id']]
        )->getRowArray();
        if ($k['kind'] === 'single_use') {
            $db->query("UPDATE engage_coupon_codes SET status = 'reserved', updated_at = NOW() WHERE id = ?", [(int) $k['id']]);
        }

        return $row;
    }

    /**
     * reserved → committed for a licence. Runs inside the caller's (licence) transaction. Idempotent for the same licence.
     *
     * @return array<string,mixed> the committed redemption row
     * @throws CouponException
     */
    public function commit(int $redemptionId, string $licenceRef, ?string $financeRef = null, ?int $actorId = null): array
    {
        $db = $this->db();
        $peek = $db->query('SELECT code_id, coupon_id FROM engage_coupon_redemptions WHERE id = ?', [$redemptionId])->getRowArray();
        if (! $peek) {
            throw new CouponException('reservation_lost');
        }
        // Lock order: code → coupon → redemption (the same as reserve and release).
        $db->query('SELECT id FROM engage_coupon_codes WHERE id = ? FOR UPDATE', [(int) $peek['code_id']]);
        $c = $db->query('SELECT * FROM engage_coupons WHERE id = ? FOR UPDATE', [(int) $peek['coupon_id']])->getRowArray();
        $r = $db->query('SELECT * FROM engage_coupon_redemptions WHERE id = ? FOR UPDATE', [$redemptionId])->getRowArray();
        if ($r['state'] === 'committed' && $r['licence_ref'] === $licenceRef) {
            return $r;
        }
        if ($r['state'] !== 'reserved' || strtotime((string) $r['reserved_until']) <= time()) {
            throw new CouponException($r['state'] === 'committed' ? 'quote_used' : 'reservation_lost');
        }
        $r = $db->query("UPDATE engage_coupon_redemptions SET state = 'committed', committed_at = NOW(), licence_ref = ?, finance_document_ref = ?,
                actor_by = ?, seller_id = COALESCE((SELECT seller_id FROM engage_leads WHERE id = engage_coupon_redemptions.lead_id), seller_id),
                updated_at = NOW() WHERE id = ? RETURNING *", [$licenceRef, $financeRef, $actorId, $redemptionId])->getRowArray();
        if ((bool) $r['single_use'] === true || $r['single_use'] === 't') {
            $db->query("UPDATE engage_coupon_codes SET status = 'redeemed', updated_at = NOW() WHERE id = ?", [(int) $r['code_id']]);
        }
        if ($c['max_redemptions_total'] !== null && in_array($c['status'], ['active', 'paused'], true)) {
            $n = (int) $db->query("SELECT COUNT(*) AS n FROM engage_coupon_redemptions WHERE coupon_id = ? AND state = 'committed'", [(int) $c['id']])->getRow()->n;
            if ($n >= (int) $c['max_redemptions_total']) {
                $db->query("UPDATE engage_coupons SET status = 'exhausted', ended_at = NOW(), version = version + 1, updated_at = NOW() WHERE id = ?", [(int) $c['id']]);
                (new CouponService($db))->audit('coupon.exhausted', (int) $c['id'], ['coupon_ref' => $c['coupon_ref'], 'committed' => $n], true);
            }
        }
        (new CouponService($db))->audit('coupon.redeemed', (int) $c['id'], ['coupon_ref' => $c['coupon_ref'], 'redemption_id' => $redemptionId,
            'quote_ref' => $r['quote_ref'], 'licence_ref' => $licenceRef, 'discount_paise' => (int) $r['discount_paise'], 'net_paise' => (int) $r['net_paise']]);

        return $r;
    }

    /** reserved → released (staff). */
    public function release(int $id, string $reason, ?array $staff = null): array
    {
        return Tx::strict($this->db(), fn () => $this->releaseTx($id, $reason, $staff));
    }

    private function releaseTx(int $id, string $reason, ?array $staff = null): array
    {
        $db = $this->db();
        $peek = $db->query('SELECT code_id, coupon_id, state FROM engage_coupon_redemptions WHERE id = ?', [$id])->getRowArray();
        if (! $peek) {
            return ['status' => 404, 'error' => 'Redemption not found.'];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['status' => 422, 'error' => 'A reason is required.', 'details' => ['reason' => 'Why is this reservation released?']];
        }
        $db->transBegin();
        try {
            $db->query('SELECT id FROM engage_coupon_codes WHERE id = ? FOR UPDATE', [(int) $peek['code_id']]);
            $r = $db->query('SELECT * FROM engage_coupon_redemptions WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if ($r['state'] !== 'reserved') {
                $db->transRollback();

                return ['status' => 409, 'code' => 'invalid_transition', 'error' => "A {$r['state']} redemption cannot be released."];
            }
            $this->doRelease($r, mb_substr($reason, 0, 255), $staff !== null ? (int) $staff['id'] : null);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Coupon release failed: ' . $e->getMessage());

            return ['status' => 500, 'error' => 'Could not release the reservation.'];
        }
        (new CouponService($db))->audit('coupon.reservation_released', (int) $peek['coupon_id'], ['redemption_id' => $id, 'reason' => $reason]);

        return ['status' => 200, 'data' => self::view($this->find($id))];
    }

    /** committed → reversed (staff, reason required — e.g. the invoice was credited in full). The count is given back. */
    public function reverse(int $id, string $reason, array $staff): array
    {
        return Tx::strict($this->db(), fn () => $this->reverseTx($id, $reason, $staff));
    }

    private function reverseTx(int $id, string $reason, array $staff): array
    {
        $db = $this->db();
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'error' => 'A reason is required.', 'details' => ['reason' => 'Why is this redemption reversed?']];
        }
        $peek = $db->query('SELECT code_id, coupon_id FROM engage_coupon_redemptions WHERE id = ?', [$id])->getRowArray();
        if (! $peek) {
            return ['status' => 404, 'error' => 'Redemption not found.'];
        }
        $db->transBegin();
        try {
            $db->query('SELECT id FROM engage_coupon_codes WHERE id = ? FOR UPDATE', [(int) $peek['code_id']]);
            $r = $db->query('SELECT * FROM engage_coupon_redemptions WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if ($r['state'] !== 'committed') {
                $db->transRollback();

                return ['status' => 409, 'code' => 'invalid_transition', 'error' => "A {$r['state']} redemption cannot be reversed."];
            }
            $db->query("UPDATE engage_coupon_redemptions SET state = 'reversed', reversed_at = NOW(), reversal_reason = ?, actor_by = ?, updated_at = NOW() WHERE id = ?",
                [mb_substr($reason, 0, 2000), (int) $staff['id'], $id]);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Coupon reversal failed: ' . $e->getMessage());

            return ['status' => 500, 'error' => 'Could not reverse the redemption.'];
        }
        (new CouponService($db))->audit('coupon.redemption_reversed', (int) $peek['coupon_id'], ['redemption_id' => $id, 'reason' => $reason,
            'licence_ref' => $r['licence_ref']]);

        return ['status' => 200, 'data' => self::view($this->find($id))];
    }

    private function doRelease(array $r, string $reason, ?int $actorId): void
    {
        $db = $this->db();
        $db->query("UPDATE engage_coupon_redemptions SET state = 'released', released_at = NOW(), release_reason = ?, actor_by = ?, updated_at = NOW()
            WHERE id = ? AND state = 'reserved'", [$reason, $actorId, (int) $r['id']]);
        if ($r['single_use'] === true || $r['single_use'] === 't') {
            $db->query("UPDATE engage_coupon_codes SET status = 'available', updated_at = NOW() WHERE id = ? AND status = 'reserved'", [(int) $r['code_id']]);
        }
        $db->query("UPDATE engage_quotes SET status = 'expired', updated_at = NOW() WHERE quote_ref = ? AND status = 'open'", [$r['quote_ref']]);
    }

    /** Caller holds the code row lock. */
    private function releaseStaleForCode(int $codeId): void
    {
        foreach ($this->db()->query("SELECT * FROM engage_coupon_redemptions WHERE code_id = ? AND state = 'reserved' AND reserved_until <= NOW() FOR UPDATE", [$codeId])->getResultArray() as $r) {
            $this->doRelease($r, 'expired', null);
        }
    }

    /**
     * engage:coupons-expire: reserved redemptions past reserved_until → released (single-use codes back to available);
     * open quotes past expires_at → expired.
     *
     * @return array{released:int, quotes_expired:int}
     */
    public function expireStale(int $batch = 500): array
    {
        return Tx::strict($this->db(), fn () => $this->expireStaleTx($batch));
    }

    private function expireStaleTx(int $batch = 500): array
    {
        $db = $this->db();
        $released = 0;
        $ids = $db->query("SELECT id, code_id FROM engage_coupon_redemptions WHERE state = 'reserved' AND reserved_until <= NOW() ORDER BY id LIMIT ?", [$batch])->getResultArray();
        foreach ($ids as $x) {
            $db->transBegin();
            try {
                $db->query('SELECT id FROM engage_coupon_codes WHERE id = ? FOR UPDATE', [(int) $x['code_id']]);
                $r = $db->query("SELECT * FROM engage_coupon_redemptions WHERE id = ? AND state = 'reserved' AND reserved_until <= NOW() FOR UPDATE", [(int) $x['id']])->getRowArray();
                if ($r) {
                    $this->doRelease($r, 'expired', null);
                    $released++;
                }
                $db->transCommit();
            } catch (\Throwable $e) {
                $db->transRollback();
                log_message('error', 'Coupon reservation expiry failed for ' . $x['id'] . ': ' . $e->getMessage());
            }
        }
        $db->query("UPDATE engage_quotes SET status = 'expired', updated_at = NOW() WHERE status = 'open' AND expires_at <= NOW()");

        return ['released' => $released, 'quotes_expired' => $db->affectedRows()];
    }

    // ------------------------------------------------------------------ reads

    public function find(int $id): ?array
    {
        return $this->db()->query('SELECT r.*, c.coupon_ref, c.label AS coupon_label, s.seller_code FROM engage_coupon_redemptions r
            JOIN engage_coupons c ON c.id = r.coupon_id LEFT JOIN engage_sellers s ON s.id = r.seller_id WHERE r.id = ?', [$id])->getRowArray() ?: null;
    }

    public static function view(array $r): array
    {
        $state = $r['state'] === 'reserved' && strtotime((string) $r['reserved_until']) <= time() ? 'released' : $r['state'];

        return [
            'id' => (int) $r['id'], 'coupon_id' => (int) $r['coupon_id'], 'coupon_ref' => $r['coupon_ref'] ?? null, 'coupon_label' => $r['coupon_label'] ?? null,
            'code' => $r['code'], 'holder_uuid' => $r['holder_uuid'], 'cmp_id' => $r['cmp_id'] !== null ? (int) $r['cmp_id'] : null,
            'lead_id' => $r['lead_id'] !== null ? (int) $r['lead_id'] : null,
            'seller' => $r['seller_id'] !== null ? ['id' => (int) $r['seller_id'], 'seller_code' => $r['seller_code'] ?? null] : null,
            'kind' => $r['kind'], 'offer_code' => $r['offer_code'], 'offer_version' => (int) $r['offer_version'], 'product_code' => $r['product_code'],
            'price_basis' => $r['price_basis'], 'gst_rate' => PricingService::trimRate((string) $r['gst_rate']), 'quote_ref' => $r['quote_ref'],
            'list_paise' => (int) $r['list_paise'], 'discount_paise' => (int) $r['discount_paise'], 'net_paise' => (int) $r['net_paise'],
            'taxable_paise' => (int) $r['taxable_paise'], 'gst_paise' => (int) $r['gst_paise'], 'total_paise' => (int) $r['total_paise'],
            'state' => $state, 'stored_state' => $r['state'], 'reserved_until' => S::iso($r['reserved_until']), 'committed_at' => S::iso($r['committed_at']),
            'released_at' => S::iso($r['released_at']), 'release_reason' => $r['release_reason'], 'reversed_at' => S::iso($r['reversed_at']),
            'reversal_reason' => $r['reversal_reason'], 'licence_ref' => $r['licence_ref'], 'finance_document_ref' => $r['finance_document_ref'],
            'source' => $r['source'], 'created_at' => S::iso($r['created_at']),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(array $q = []): array
    {
        $where = [];
        $binds = [];
        foreach (['coupon_id' => 'r.coupon_id', 'seller_id' => 'r.seller_id', 'cmp_id' => 'r.cmp_id'] as $k => $col) {
            if (! empty($q[$k]) && ctype_digit((string) $q[$k])) {
                $where[] = "{$col} = ?";
                $binds[] = (int) $q[$k];
            }
        }
        foreach (['state' => 'r.state', 'holder_uuid' => 'r.holder_uuid', 'quote_ref' => 'r.quote_ref', 'licence_ref' => 'r.licence_ref'] as $k => $col) {
            if (! empty($q[$k]) && is_string($q[$k])) {
                $where[] = "{$col} = ?";
                $binds[] = $q[$k];
            }
        }
        $limit = min(500, max(1, (int) ($q['limit'] ?? 200)));
        $rows = $this->db()->query('SELECT r.*, c.coupon_ref, c.label AS coupon_label, s.seller_code FROM engage_coupon_redemptions r
            JOIN engage_coupons c ON c.id = r.coupon_id LEFT JOIN engage_sellers s ON s.id = r.seller_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY r.id DESC LIMIT {$limit}", $binds)->getResultArray();

        return array_map([self::class, 'view'], $rows);
    }

    /** Committed redemption for a quote_ref (commission basis), or null. */
    public function committedForQuote(string $quoteRef): ?array
    {
        return $this->db()->query("SELECT * FROM engage_coupon_redemptions WHERE quote_ref = ? AND state = 'committed'", [$quoteRef])->getRowArray() ?: null;
    }
}
