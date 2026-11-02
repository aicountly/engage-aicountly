<?php
declare(strict_types=1);

namespace App\Services\Tokens;

use App\Services\Coupons\PricingService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Buying AI tokens: quote → payment link → paid → granted.
 *
 * Engage owns the order because three facts have to survive the round trip to the
 * gateway and none of them belongs to Finance: how many tokens were bought, the rate
 * they were priced at, and which coupon was applied. Finance knows an amount; it does
 * not know the amount was 1,000,000 tokens at 100 paise per thousand less a discount.
 *
 * THE PRICE IS FROZEN AT QUOTE. A customer who opens a link on Tuesday and pays on
 * Friday gets Tuesday's price — the only defensible answer, and the one the invoice
 * already states.
 *
 * NOTHING IS GRANTED UNTIL FINANCE SAYS SO. `paid` is written only from a payment
 * Finance has re-read from the provider, never from a webhook; `granted` only once
 * the grant row exists, in the same transaction. A crash between the two leaves an
 * order that can be retried rather than tokens that were silently never given.
 */
class TokenOrderService
{
    public const STATUSES = ['quoted', 'awaiting_payment', 'paid', 'granted', 'cancelled', 'expired', 'failed'];

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    public function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    // ------------------------------------------------------------------ quote

    /**
     * Price a purchase without creating anything — what the Buy Credits screen shows
     * before the customer commits.
     *
     * Either `tokens` or `amount_rupees`; the other is derived. A coupon is applied
     * exactly as it would be on a licence, so the discount a customer is quoted here
     * and the discount Finance prints on the invoice are the same number.
     *
     * @return array<string,mixed>
     */
    public function quote(array $in, ?string $holderUuid = null): array
    {
        $rates = new TokenRateService($this->db);
        $pricing = $rates->pricing();
        $rate = (int) $pricing['paise_per_1000_tokens'];

        $tokens = 0;
        if (isset($in['tokens']) && $in['tokens'] !== '') {
            $tokens = (int) $in['tokens'];
        } elseif (isset($in['amount_rupees']) && $in['amount_rupees'] !== '') {
            $tokens = $rates->tokensForPaise((int) round(((float) $in['amount_rupees']) * 100));
        }
        if ($tokens < 1) {
            return self::fail(422, 'validation_failed', 'How many tokens, or how much to spend.', ['tokens' => 'A whole number above zero.']);
        }
        if ($tokens > 100_000_000_000) {
            return self::fail(422, 'validation_failed', 'That is more tokens than anyone can buy at once.', ['tokens' => 'Too many.']);
        }

        $list = $rates->paiseForTokens($tokens);
        $discount = 0;
        $couponCode = null;
        $couponId = null;

        $code = strtoupper(trim((string) ($in['coupon_code'] ?? '')));
        if ($code !== '') {
            $c = $this->coupon($code);
            if ($c === null) {
                return self::fail(422, 'coupon_invalid', 'That coupon cannot be used for a token purchase.', ['coupon_code' => 'Not recognised, or not active.']);
            }
            // The same arithmetic a licence quote uses, including its floor: a coupon
            // can never take the price to zero, because a zero invoice is meaningless
            // and Finance refuses one. A free grant is a voucher, not a coupon.
            $discount = PricingService::discount($list, $c);
            if ($discount <= 0) {
                return self::fail(422, 'coupon_invalid', 'That coupon gives nothing on this amount.', ['coupon_code' => 'No discount applies.']);
            }
            if ($c['min_order_paise'] !== null && $list < (int) $c['min_order_paise']) {
                return self::fail(422, 'below_min_order', 'This coupon needs a larger purchase.',
                    ['coupon_code' => 'Minimum ' . number_format(((int) $c['min_order_paise']) / 100, 2) . ' before GST.']);
            }
            $couponCode = $code;
            $couponId = (int) $c['id'];
        }

        $net = $list - $discount;
        $gstRate = (float) $pricing['gst_rate'];
        $gst = (int) round($net * $gstRate / 100);
        $total = $net + $gst;

        if ($total < 100) {
            return self::fail(422, 'amount_too_small', 'A purchase has to come to at least ₹1.00.',
                ['tokens' => 'Buy more tokens, or use a voucher instead — a free grant is a voucher, not a discounted purchase.']);
        }

        return ['status' => 200, 'data' => [
            'tokens'               => $tokens,
            'price_paise_per_1000' => $rate,
            'list_paise'           => $list,
            'discount_paise'       => $discount,
            'net_paise'            => $net,
            'gst_rate'             => $gstRate,
            'gst_paise'            => $gst,
            'total_paise'          => $total,
            'sac'                  => (string) $pricing['sac'],
            'coupon_code'          => $couponCode,
            'coupon_id'            => $couponId,
            'currency'             => 'INR',
        ]];
    }

    // ------------------------------------------------------------------ order

    /**
     * Turn a quote into an order the customer can pay.
     *
     * One open order per holder, enforced by a partial unique index: two live
     * checkouts is how somebody pays twice for one intention. An existing open order
     * is returned rather than refused, so a customer who reloads the page gets their
     * order back instead of an error.
     */
    public function create(array $in, string $holderUuid, int $cmpId, array $payer, string $source = 'manage'): array
    {
        $q = $this->quote($in, $holderUuid);
        if (($q['status'] ?? 500) !== 200) {
            return $q;
        }
        $d = $q['data'];

        $errors = [];
        $name = trim((string) ($payer['name'] ?? ''));
        if ($name === '') {
            $errors['payer.name'] = 'The name the invoice is made out to.';
        }
        $state = trim((string) ($payer['state_code'] ?? ''));
        if (preg_match('/^[0-9]{2}$/', $state) !== 1) {
            $errors['payer.state_code'] = 'The GST state code — it decides the place of supply.';
        }
        if ($cmpId <= 0) {
            $errors['cmp_id'] = 'The company this purchase belongs to.';
        }
        if ($errors !== []) {
            return self::fail(422, 'validation_failed', 'Please complete the billing details.', $errors);
        }

        $db = $this->db();
        $existing = $db->query(
            "SELECT * FROM engage_token_orders WHERE holder_uuid = ? AND status IN ('quoted','awaiting_payment')",
            [$holderUuid]
        )->getRowArray();
        if ($existing !== null) {
            return ['status' => 200, 'data' => self::view($existing) + ['existing' => true]];
        }

        $ref = 'TKO-' . str_pad((string) $db->query("SELECT nextval('engage_token_order_ref_seq') AS n")->getRow()->n, 6, '0', STR_PAD_LEFT);
        $db->table('engage_token_orders')->insert([
            'order_ref'            => $ref,
            'holder_uuid'          => $holderUuid,
            'cmp_id'               => $cmpId,
            'tokens'               => $d['tokens'],
            'price_paise_per_1000' => $d['price_paise_per_1000'],
            'list_paise'           => $d['list_paise'],
            'discount_paise'       => $d['discount_paise'],
            'net_paise'            => $d['net_paise'],
            'gst_rate'             => $d['gst_rate'],
            'gst_paise'            => $d['gst_paise'],
            'total_paise'          => $d['total_paise'],
            'sac'                  => $d['sac'],
            'coupon_code'          => $d['coupon_code'],
            'coupon_id'            => $d['coupon_id'],
            'payer_name'           => mb_substr($name, 0, 120),
            'payer_email'          => trim((string) ($payer['email'] ?? '')) ?: null,
            'payer_mobile'         => trim((string) ($payer['mobile'] ?? '')) ?: null,
            'payer_state_code'     => $state,
            'payer_address'        => trim((string) ($payer['address'] ?? '')) ?: null,
            'payer_pincode'        => trim((string) ($payer['pincode'] ?? '')) ?: null,
            'buyer_gstin'          => strtoupper(trim((string) ($payer['gstin'] ?? ''))) ?: null,
            'status'               => 'quoted',
            'source'               => $source,
        ]);
        $id = (int) $db->insertID();
        $this->audit('token_order.create', $id, ['order_ref' => $ref, 'tokens' => $d['tokens'], 'total_paise' => $d['total_paise'], 'coupon' => $d['coupon_code']]);

        return ['status' => 201, 'data' => $this->find($id)];
    }

    /** What Finance needs to raise the payment link for this order. */
    public function financeRequest(array $o): array
    {
        $fee = [
            'taxable_paise' => (int) $o['net_paise'],
            // The buyer's state against the seller's decides the split; Finance
            // recomputes it anyway and refuses a disagreement, which is the point.
            'cgst_paise'    => 0,
            'sgst_paise'    => 0,
            'igst_paise'    => 0,
            'sac'           => (string) $o['sac'],
            'gst_rate'      => (string) $o['gst_rate'],
            'price_basis'   => 'exclusive',
        ];
        if ((int) $o['discount_paise'] > 0) {
            // Printed on the invoice line as list less discount, with the code.
            $fee['list_amount_paise'] = (int) $o['list_paise'];
            $fee['discount_paise']    = (int) $o['discount_paise'];
            $fee['discount_ref']      = (string) $o['coupon_code'];
        }

        return [
            'purpose'      => 'tokens',
            'source_ref'   => (string) $o['order_ref'],
            'cmp_id'       => (int) $o['cmp_id'],
            'buyer_uuid'   => (string) $o['holder_uuid'],
            'token_count'  => (int) $o['tokens'],
            'buyer_gstin'  => $o['buyer_gstin'],
            'product_code' => 'manage',
            'description'  => number_format((float) $o['tokens']) . ' Aicountly AI tokens',
            'payer'        => array_filter([
                'name'       => (string) $o['payer_name'],
                'email'      => $o['payer_email'],
                'mobile'     => $o['payer_mobile'],
                'state_code' => (string) $o['payer_state_code'],
                'address'    => $o['payer_address'],
                'pincode'    => $o['payer_pincode'],
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'amount_paise' => (int) $o['total_paise'],
            'fee'          => $fee,
        ];
    }

    /** Record the link Finance made, and send the customer to it. */
    public function linkRaised(int $id, array $link): array
    {
        $this->db()->table('engage_token_orders')->where('id', $id)->whereIn('status', ['quoted', 'awaiting_payment'])->update([
            'status'          => 'awaiting_payment',
            'link_id'         => (string) ($link['link_id'] ?? ''),
            'link_url'        => $link['url'] ?? null,
            'link_expires_at' => isset($link['expire_at']) ? date('Y-m-d H:i:sP', (int) strtotime((string) $link['expire_at'])) : null,
            'updated_at'      => date('Y-m-d H:i:sP'),
        ]);
        $this->audit('token_order.link', $id, ['link_id' => $link['link_id'] ?? null]);

        return ['status' => 200, 'data' => $this->find($id)];
    }

    /**
     * Finance confirms a payment: mark the order paid and queue the grant, together.
     *
     * One transaction, because an order marked paid without a grant row is a customer
     * who has been charged and given nothing. Idempotent on the payment reference —
     * Finance may deliver the same event more than once, and a unique index means the
     * second one changes nothing rather than granting twice.
     *
     * @param array{payment_ref:string, invoice_no?:?string, invoice_date?:?string, paid_at?:?string} $payment
     */
    public function settle(string $orderRef, array $payment): array
    {
        $paymentRef = trim((string) ($payment['payment_ref'] ?? ''));
        if ($paymentRef === '') {
            return self::fail(422, 'validation_failed', 'The payment reference is required.');
        }

        $db = $this->db();
        $db->transBegin();
        try {
            $db->query('SELECT pg_advisory_xact_lock(hashtext(?))', ['engage-token-order:' . $orderRef]);
            $o = $db->query('SELECT * FROM engage_token_orders WHERE order_ref = ?', [$orderRef])->getRowArray();
            if ($o === null) {
                $db->transCommit();

                return self::fail(404, 'not_found', 'No such token order.');
            }
            if (in_array($o['status'], ['paid', 'granted'], true)) {
                // Already settled. Saying so is a success: a repeated event is correct
                // behaviour from Finance, and an error would make it retry for ever.
                $db->transCommit();

                return ['status' => 200, 'data' => $this->find((int) $o['id']) + ['duplicate' => true]];
            }
            if (in_array($o['status'], ['cancelled', 'expired'], true)) {
                $db->transCommit();

                return self::fail(409, 'order_closed', 'That order was closed before the payment arrived. Refund it and raise a new one.');
            }

            $grantRef = 'TKO:' . $orderRef . ':' . $paymentRef;
            $db->table('engage_token_orders')->where('id', (int) $o['id'])->update([
                'status'       => 'granted',
                'payment_ref'  => $paymentRef,
                'invoice_no'   => $payment['invoice_no'] ?? null,
                'invoice_date' => ($payment['invoice_date'] ?? null) ?: null,
                'paid_at'      => isset($payment['paid_at']) ? date('Y-m-d H:i:sP', (int) strtotime((string) $payment['paid_at'])) : date('Y-m-d H:i:sP'),
                'grant_ref'    => $grantRef,
                'granted_at'   => date('Y-m-d H:i:sP'),
                'updated_at'   => date('Y-m-d H:i:sP'),
            ]);

            // The grant goes in the same transaction. Pulse is idempotent on grant_ref,
            // so a redelivery credits once.
            $db->table('engage_token_grants')->ignore(true)->insert([
                'grant_ref'   => $grantRef,
                'holder_uuid' => (string) $o['holder_uuid'],
                'tokens'      => (int) $o['tokens'],
                'reason'      => 'Purchase ' . $orderRef . ($o['invoice_no'] ?? '' ? '' : ''),
                'origin'      => 'purchase',
                'origin_ref'  => $orderRef,
                'status'      => 'pending',
            ]);

            // A coupon used on a purchase is spent when the money arrives, not when the
            // customer typed it: an abandoned checkout must not burn their coupon.
            if (($o['coupon_id'] ?? null) !== null) {
                $db->query(
                    "UPDATE engage_coupon_redemptions SET state = 'committed', committed_at = NOW(), updated_at = NOW()
                      WHERE idempotency_key = ? AND state = 'reserved'",
                    ['token_order:' . $orderRef]
                );
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $this->audit('token_order.settle', (int) $o['id'], [
            'order_ref' => $orderRef, 'payment_ref' => $paymentRef, 'tokens' => (int) $o['tokens'], 'invoice_no' => $payment['invoice_no'] ?? null,
        ]);

        return ['status' => 200, 'data' => $this->find((int) $o['id'])];
    }

    public function cancel(string $orderRef, string $reason): array
    {
        $o = $this->db()->query('SELECT * FROM engage_token_orders WHERE order_ref = ?', [$orderRef])->getRowArray();
        if ($o === null) {
            return self::fail(404, 'not_found', 'No such token order.');
        }
        if (in_array($o['status'], ['paid', 'granted'], true)) {
            return self::fail(409, 'already_paid', 'That order is paid. Refund it rather than cancelling it.');
        }
        $this->db()->table('engage_token_orders')->where('id', (int) $o['id'])->update([
            'status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:sP'),
            'cancel_reason' => mb_substr($reason, 0, 255), 'updated_at' => date('Y-m-d H:i:sP'),
        ]);
        $this->audit('token_order.cancel', (int) $o['id'], ['order_ref' => $orderRef, 'reason' => $reason]);

        return ['status' => 200, 'data' => $this->find((int) $o['id'])];
    }

    // ------------------------------------------------------------------ reads

    public function find(int $id): ?array
    {
        $r = $this->db()->table('engage_token_orders')->where('id', $id)->get()->getRowArray();

        return $r === null ? null : self::view($r);
    }

    public function findByRef(string $ref): ?array
    {
        $r = $this->db()->table('engage_token_orders')->where('order_ref', $ref)->get()->getRowArray();

        return $r === null ? null : self::view($r);
    }

    /** @return list<array<string,mixed>> */
    public function forHolder(string $holderUuid, int $limit = 50): array
    {
        $rows = $this->db()->table('engage_token_orders')->where('holder_uuid', $holderUuid)
            ->orderBy('created_at', 'DESC')->limit(max(1, min(200, $limit)))->get()->getResultArray();

        return array_map(static fn (array $r): array => self::view($r), $rows);
    }

    // --------------------------------------------------------------- helpers

    /** An active coupon that may be used on a token purchase. */
    private function coupon(string $code): ?array
    {
        $r = $this->db()->query(
            "SELECT c.* FROM engage_coupon_codes k JOIN engage_coupons c ON c.id = k.coupon_id
              WHERE k.code = ? AND k.status = 'available' AND c.status = 'active'
                AND (c.valid_from IS NULL OR c.valid_from <= NOW())
                AND (c.valid_to   IS NULL OR c.valid_to   >  NOW())",
            [$code]
        )->getRowArray();
        if ($r === null) {
            return null;
        }
        // A coupon scoped to particular products or offers is for licences, not for
        // tokens. Only an unscoped one applies here, so a "20% off Books Pro" coupon
        // cannot quietly discount a token purchase.
        $applies = json_decode((string) ($r['applies_to'] ?? '{}'), true);
        foreach (['product_codes', 'offer_codes', 'licence_type_codes'] as $k) {
            if (($applies[$k] ?? []) !== []) {
                return null;
            }
        }

        return $r;
    }

    private static function view(array $r): array
    {
        return [
            'id'                   => (int) $r['id'],
            'order_ref'            => (string) $r['order_ref'],
            'holder_uuid'          => (string) $r['holder_uuid'],
            'cmp_id'               => (int) $r['cmp_id'],
            'tokens'               => (int) $r['tokens'],
            'price_paise_per_1000' => (int) $r['price_paise_per_1000'],
            'list_paise'           => (int) $r['list_paise'],
            'discount_paise'       => (int) $r['discount_paise'],
            'net_paise'            => (int) $r['net_paise'],
            'gst_rate'             => (float) $r['gst_rate'],
            'gst_paise'            => (int) $r['gst_paise'],
            'total_paise'          => (int) $r['total_paise'],
            'coupon_code'          => $r['coupon_code'],
            'status'               => (string) $r['status'],
            'link_url'             => $r['link_url'],
            'link_expires_at'      => $r['link_expires_at'],
            'invoice_no'           => $r['invoice_no'],
            'invoice_date'         => $r['invoice_date'],
            'paid_at'              => $r['paid_at'],
            'granted_at'           => $r['granted_at'],
            'created_at'           => $r['created_at'],
        ];
    }

    private static function fail(int $status, string $code, string $msg, array $details = []): array
    {
        return ['status' => $status, 'code' => $code, 'error' => $msg] + ($details ? ['details' => $details] : []);
    }

    private function audit(string $event, int $id, array $meta): void
    {
        Services::auditService()->log($event, ['subject_kind' => 'token_order', 'subject_id' => $id, 'metadata' => $meta, 'fanout_console' => true]);
    }
}
