<?php
declare(strict_types=1);

namespace App\Services\Coupons;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Services\Licensing\OfferService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Quotes (engage_quotes) — `POST /api/v1/quotes` (staff) and the quote inside a Manage upgrade request.
 *
 *   {holder_uuid | cmp_id, offer_code, coupon_code?, kind?: new|renewal, lead_id?, idempotency_key?}
 *   → list, discount, net, GST on net (offer's gst_rate / price_basis), total, quote_ref, and — with a valid code — a
 *     reserved redemption (reserved_until = expires_at = now + 14 days).
 *
 * Staff then raise the Finance invoice with the discounted line (finance_line in the answer) and issue / renew the
 * licence with quote_ref: CouponLicenceHook commits the reservation in the licence's own transaction.
 */
class QuoteService
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    public function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    public static function validHolder(mixed $v): bool
    {
        return is_string($v) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9\-]{7,63}\z/', $v) === 1;
    }

    public static function validCmp(mixed $v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }

        return is_string($v) && preg_match('/\A[1-9]\d{0,17}\z/', $v) ? (int) $v : null;
    }

    /**
     * @param array{source?:string, staff_id?:?int, channel?:string, scope_key?:string, ip?:?string, coupon_optional?:bool} $ctx
     *        coupon_optional: an unusable code does not fail the quote (Manage upgrade request) — the quote is made at list
     *        price and coupon_reason says why.
     */
    public function create(array $in, array $ctx = []): array
    {
        return Tx::strict($this->db(), fn () => $this->createTx($in, $ctx));
    }

    private function createTx(array $in, array $ctx = []): array
    {
        $source = $ctx['source'] ?? 'staff';
        $e = [];
        $holder = isset($in['holder_uuid']) && $in['holder_uuid'] !== '' && $in['holder_uuid'] !== null ? $in['holder_uuid'] : null;
        if ($holder !== null && ! self::validHolder($holder)) {
            $e['holder_uuid'] = 'The my.aicountly holder uuid.';
        }
        $cmp = isset($in['cmp_id']) && $in['cmp_id'] !== '' && $in['cmp_id'] !== null ? self::validCmp($in['cmp_id']) : null;
        if (isset($in['cmp_id']) && $in['cmp_id'] !== '' && $in['cmp_id'] !== null && $cmp === null) {
            $e['cmp_id'] = 'A positive Manage company id.';
        }
        if ($holder === null && $cmp === null && ! isset($e['holder_uuid']) && ! isset($e['cmp_id'])) {
            $e['holder_uuid'] = 'Give the holder uuid or the Manage company (cmp_id).';
        }
        $kind = (string) ($in['kind'] ?? 'new');
        if (! in_array($kind, ['new', 'renewal'], true)) {
            $e['kind'] = 'new or renewal.';
        }
        $leadId = isset($in['lead_id']) && $in['lead_id'] !== '' && $in['lead_id'] !== null ? (int) $in['lead_id'] : null;
        if ($leadId !== null && ! $this->db()->table('engage_leads')->where('id', $leadId)->countAllResults()) {
            $e['lead_id'] = 'No such lead.';
        }
        $idem = isset($in['idempotency_key']) && is_string($in['idempotency_key']) && $in['idempotency_key'] !== '' ? $in['idempotency_key'] : null;
        if ($idem !== null && ! preg_match('/\A[A-Za-z0-9_.:\-]{8,120}\z/', $idem)) {
            $e['idempotency_key'] = '8–120 letters, digits or _ . : -';
        }
        $offerCode = is_string($in['offer_code'] ?? null) ? trim($in['offer_code']) : '';
        $offer = $offerCode !== '' ? (new OfferService($this->db()))->publishedVersion($offerCode) : null;
        if (! $offer || ! OfferService::isOnSale($offer)) {
            $e['offer_code'] = 'Choose a published offer that is on sale.';
        }
        $code = CodeGenerator::normalise($in['coupon_code'] ?? '');
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the quote.', 'details' => $e];
        }
        if ($idem !== null && ($q = $this->findBy('idempotency_key', $idem))) {
            return ['status' => 200, 'data' => $this->view($q) + ['duplicate' => true]];
        }

        $attempts = new AttemptLimiter($this->db());
        $db = $this->db();
        $couponReason = null;
        $db->transBegin();
        try {
            $n = (int) $db->query("SELECT nextval('engage_quote_ref_seq') AS n")->getRow()->n;
            $ref = 'QTE-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $expires = S::db(new \DateTimeImmutable('+' . RedemptionService::RESERVE_DAYS . ' days'));
            $red = null;
            if ($code !== '') {
                $db->query('SAVEPOINT coupon_reserve');
                try {
                    $red = (new RedemptionService($db))->reserve([
                        'code' => $code, 'offer' => $offer, 'kind' => $kind, 'holder_uuid' => $holder, 'cmp_id' => $cmp, 'lead_id' => $leadId,
                        'quote_ref' => $ref, 'idempotency_key' => 'quote:' . $ref, 'reserved_until' => $expires, 'source' => $source,
                        'staff_id' => $ctx['staff_id'] ?? null,
                    ]);
                    $db->query('RELEASE SAVEPOINT coupon_reserve');
                } catch (CouponException $ce) {
                    $db->query('ROLLBACK TO SAVEPOINT coupon_reserve');
                    $attempts->record($ctx['channel'] ?? 'staff_quote', $ctx['scope_key'] ?? ('staff:' . ($ctx['staff_id'] ?? 0)), $code, null, 'rejected', $ce->reason, $ctx['ip'] ?? null);
                    if (empty($ctx['coupon_optional'])) {
                        $db->transCommit(); // keeps the attempt row; nothing else was written

                        return ['status' => 422, 'code' => 'coupon_not_applicable', 'error' => $ce->getMessage(),
                            'details' => ['coupon_code' => $ce->getMessage(), 'reason' => $ce->reason, 'reason_code' => RedemptionService::generic($ce->reason)]];
                    }
                    $couponReason = $ce->reason;
                }
            }
            $p = $red !== null
                ? ['list_paise' => (int) $red['list_paise'], 'discount_paise' => (int) $red['discount_paise'], 'net_paise' => (int) $red['net_paise'],
                    'taxable_paise' => (int) $red['taxable_paise'], 'gst_paise' => (int) $red['gst_paise'], 'total_paise' => (int) $red['total_paise']]
                : PricingService::quote((int) $offer['price_paise'], (string) $offer['gst_rate'], (string) $offer['price_basis']);
            $db->query(
                'INSERT INTO engage_quotes (quote_ref, source, holder_uuid, cmp_id, lead_id, kind, offer_id, offer_code, offer_version, product_code,
                    price_basis, gst_rate, list_paise, discount_paise, net_paise, taxable_paise, gst_paise, total_paise, coupon_id, coupon_code,
                    expires_at, idempotency_key, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS NUMERIC), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$ref, $source, $holder, $cmp, $leadId, $kind, (int) $offer['id'], $offer['offer_code'], (int) $offer['version'], $offer['product_code'],
                    $offer['price_basis'], (string) $offer['gst_rate'], $p['list_paise'], $p['discount_paise'], $p['net_paise'], $p['taxable_paise'],
                    $p['gst_paise'], $p['total_paise'], $red !== null ? (int) $red['coupon_id'] : null, $red !== null ? $red['code'] : null,
                    $expires, $idem, $ctx['staff_id'] ?? null]
            );
            if ($db->transStatus() === false) {
                throw new \RuntimeException('a quote write failed');
            }
            if ($red !== null) {
                $attempts->record($ctx['channel'] ?? 'staff_quote', $ctx['scope_key'] ?? ('staff:' . ($ctx['staff_id'] ?? 0)), $code, (int) $red['coupon_id'], 'reserved', null, $ctx['ip'] ?? null);
            }
            $db->transCommit();
        } catch (\Throwable $ex) {
            $db->transRollback();
            if ($idem !== null && ($q = $this->findBy('idempotency_key', $idem))) {
                return ['status' => 200, 'data' => $this->view($q) + ['duplicate' => true]];
            }
            log_message('error', 'Quote failed: ' . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not create the quote.'];
        }
        $quote = $this->findBy('quote_ref', $ref);
        \Config\Services::auditService()->log('quote.created', ['subject_kind' => 'quote', 'subject_id' => $ref, 'fanout_console' => $red !== null,
            'metadata' => ['offer_code' => $offer['offer_code'], 'holder_uuid' => $holder, 'cmp_id' => $cmp, 'coupon_reserved' => $red !== null,
                'discount_paise' => $p['discount_paise'], 'source' => $source]]
            + ($source === 'manage' ? ['actor_email' => 'manage:' . ($ctx['actor'] ?? 'unknown'), 'actor_role' => 'service:manage'] : []));

        return ['status' => 201, 'data' => $this->view($quote) + ($couponReason !== null
            ? ['coupon_reason' => $couponReason, 'coupon_reason_code' => RedemptionService::generic($couponReason)] : [])];
    }

    public function findBy(string $col, string $value): ?array
    {
        if (! in_array($col, ['quote_ref', 'idempotency_key'], true)) {
            return null;
        }

        return $this->db()->query("SELECT q.*, o.label AS offer_label, o.sac, c.coupon_ref, c.label AS coupon_label,
                r.id AS redemption_id, r.state AS redemption_state, r.reserved_until
            FROM engage_quotes q JOIN engage_offers o ON o.id = q.offer_id
            LEFT JOIN engage_coupons c ON c.id = q.coupon_id
            LEFT JOIN engage_coupon_redemptions r ON r.quote_ref = q.quote_ref
            WHERE q.{$col} = ?", [$value])->getRowArray() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function list(array $q = []): array
    {
        $where = [];
        $binds = [];
        foreach (['status', 'holder_uuid', 'offer_code', 'source'] as $k) {
            if (! empty($q[$k]) && is_string($q[$k])) {
                $where[] = "q.{$k} = ?";
                $binds[] = $q[$k];
            }
        }
        if (! empty($q['cmp_id']) && ctype_digit((string) $q['cmp_id'])) {
            $where[] = 'q.cmp_id = ?';
            $binds[] = (int) $q['cmp_id'];
        }
        $rows = $this->db()->query("SELECT q.*, o.label AS offer_label, o.sac, c.coupon_ref, c.label AS coupon_label,
                r.id AS redemption_id, r.state AS redemption_state, r.reserved_until
            FROM engage_quotes q JOIN engage_offers o ON o.id = q.offer_id
            LEFT JOIN engage_coupons c ON c.id = q.coupon_id
            LEFT JOIN engage_coupon_redemptions r ON r.quote_ref = q.quote_ref"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY q.id DESC LIMIT 200', $binds)->getResultArray();

        return array_map(fn ($r) => $this->view($r), $rows);
    }

    public function view(array $q): array
    {
        $status = $q['status'] === 'open' && strtotime((string) $q['expires_at']) <= time() ? 'expired' : $q['status'];
        $discount = (int) $q['discount_paise'];

        return [
            'quote_ref'      => $q['quote_ref'],
            'status'         => $status,
            'source'         => $q['source'],
            'holder_uuid'    => $q['holder_uuid'],
            'cmp_id'         => $q['cmp_id'] !== null ? (int) $q['cmp_id'] : null,
            'lead_id'        => $q['lead_id'] !== null ? (int) $q['lead_id'] : null,
            'kind'           => $q['kind'],
            'offer'          => ['code' => $q['offer_code'], 'version' => (int) $q['offer_version'], 'label' => $q['offer_label'] ?? $q['offer_code'],
                'product_code' => $q['product_code']],
            'price_basis'    => $q['price_basis'],
            'gst_rate'       => PricingService::trimRate((string) $q['gst_rate']),
            'list_paise'     => (int) $q['list_paise'],
            'discount_paise' => $discount,
            'net_paise'      => (int) $q['net_paise'],
            'taxable_paise'  => (int) $q['taxable_paise'],
            'gst_paise'      => (int) $q['gst_paise'],
            'total_paise'    => (int) $q['total_paise'],
            'coupon'         => $q['coupon_id'] !== null ? ['code' => $q['coupon_code'], 'coupon_ref' => $q['coupon_ref'] ?? null, 'label' => $q['coupon_label'] ?? null] : null,
            'redemption'     => ! empty($q['redemption_id']) ? ['id' => (int) $q['redemption_id'], 'state' => $q['redemption_state'],
                'reserved_until' => S::iso($q['reserved_until'])] : null,
            'expires_at'     => S::iso($q['expires_at']),
            'licence_ref'    => $q['licence_ref'],
            'used_at'        => S::iso($q['used_at']),
            'created_at'     => S::iso($q['created_at']),
            // What to type on the Finance invoice line (docs/INVOICING.md "Discounted lines").
            'finance_line'   => [
                'amount_paise'      => (int) $q['net_paise'],
                'list_amount_paise' => $discount > 0 ? (int) $q['list_paise'] : null,
                'discount_paise'    => $discount > 0 ? $discount : null,
                'discount_ref'      => $discount > 0 ? $q['coupon_code'] : null,
                'price_basis'       => $q['price_basis'],
                'gst_rate'          => PricingService::trimRate((string) $q['gst_rate']),
                'sac'               => $q['sac'] ?? null,
            ],
        ];
    }
}
