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
            $found = $this->coupon($code, $holderUuid);
            if ($found === null) {
                return self::fail(422, 'coupon_invalid', 'That coupon cannot be used for a token purchase.',
                    ['coupon_code' => 'Not recognised, no longer active, or already used as many times as it allows.']);
            }
            $c = $found['coupon'];
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
        // The order and the hold on the coupon go together: an order carrying a discount
        // nobody recorded is a coupon that can be used for ever.
        $db->transBegin();
        try {
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
            if (($d['coupon_code'] ?? null) !== null) {
                $this->reserveCoupon($d, $ref, $holderUuid, $cmpId, $source);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
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
                'reason'      => 'Purchase ' . $orderRef,
                'origin'      => 'purchase',
                'origin_ref'  => $orderRef,
                'status'      => 'pending',
            ]);

            // A coupon used on a purchase is spent when the money arrives, not when the
            // customer typed it: an abandoned checkout must not burn their coupon.
            if (($o['coupon_id'] ?? null) !== null) {
                $this->commitCoupon($orderRef);
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
        $db = $this->db();
        $db->transBegin();
        try {
            $db->table('engage_token_orders')->where('id', (int) $o['id'])->update([
                'status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:sP'),
                'cancel_reason' => mb_substr($reason, 0, 255), 'updated_at' => date('Y-m-d H:i:sP'),
            ]);
            // The coupon goes back to the customer. Changing their mind must not cost them
            // a single-use code.
            $this->releaseCoupon($orderRef, 'The token order was cancelled: ' . mb_substr($reason, 0, 180));
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
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

    /**
     * An active coupon that may be used on a token purchase, with its code row.
     *
     * The limits are checked HERE rather than at the order, so the customer is told at the
     * quote — before they have filled in billing details — that their coupon is spent.
     *
     * @return array{coupon:array<string,mixed>, code:array<string,mixed>}|null
     */
    private function coupon(string $code, ?string $holderUuid = null): ?array
    {
        $db = $this->db();
        $r = $db->query(
            "SELECT c.*, k.id AS code_id, k.kind AS code_row_kind, k.status AS code_status
               FROM engage_coupon_codes k JOIN engage_coupons c ON c.id = k.coupon_id
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
        // How many times it has been used, counting a live reservation: somebody halfway
        // through a checkout has it, and a second tab must not be given it as well.
        $used = static fn (string $where, array $args): int => (int) $db->query(
            "SELECT COUNT(*) AS n FROM engage_coupon_redemptions
              WHERE {$where} AND (state = 'committed' OR (state = 'reserved' AND reserved_until > NOW()))",
            $args
        )->getRow()->n;
        if ($r['max_redemptions_total'] !== null && $used('coupon_id = ?', [(int) $r['id']]) >= (int) $r['max_redemptions_total']) {
            return null;
        }
        if ($holderUuid !== null && $r['max_redemptions_per_holder'] !== null
            && $used('coupon_id = ? AND holder_uuid = ?', [(int) $r['id'], $holderUuid]) >= (int) $r['max_redemptions_per_holder']) {
            return null;
        }

        return ['coupon' => $r, 'code' => ['id' => (int) $r['code_id'], 'kind' => (string) $r['code_row_kind']]];
    }

    /**
     * Hold the coupon for this order: a `reserved` redemption, released if the order is
     * cancelled and committed when the money arrives.
     *
     * A token purchase is not a licence, so `offer_code` / `product_code` say `tokens` and
     * `order_ref` carries what it is held against. The reservation lapses on its own
     * (engage:coupons-expire releases stale ones), so an abandoned checkout gives the
     * coupon back without anybody doing anything.
     *
     * @param array<string,mixed> $d the quote
     */
    private function reserveCoupon(array $d, string $orderRef, string $holderUuid, int $cmpId, string $source): void
    {
        $db = $this->db();
        $c = $this->coupon((string) $d['coupon_code'], $holderUuid);
        if ($c === null) {
            // Taken between the quote and the order. The order is priced from the quote,
            // so refusing here would lose a correct price; the discount simply is not
            // recorded as a redemption and sales sees the order's own coupon_code.
            log_message('warning', 'token order {o}: coupon {c} was no longer available when the order was created', ['o' => $orderRef, 'c' => $d['coupon_code']]);

            return;
        }
        $db->query(
            "INSERT INTO engage_coupon_redemptions (coupon_id, code_id, code, single_use, holder_uuid, cmp_id, holder_key, kind,
                offer_code, offer_version, product_code, price_basis, gst_rate, order_ref, list_paise, discount_paise, net_paise,
                taxable_paise, gst_paise, total_paise, state, reserved_until, idempotency_key, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'new', 'tokens', 1, 'tokens', 'exclusive', CAST(? AS NUMERIC), ?, ?, ?, ?, ?, ?, ?, 'reserved',
                NOW() + INTERVAL '14 days', ?, ?)
             ON CONFLICT (idempotency_key) DO NOTHING",
            [
                (int) $c['coupon']['id'], $c['code']['id'], (string) $d['coupon_code'], $c['code']['kind'] === 'single_use' ? 't' : 'f',
                $holderUuid, $cmpId, 'holder:' . $holderUuid, (string) $d['gst_rate'], $orderRef,
                (int) $d['list_paise'], (int) $d['discount_paise'], (int) $d['net_paise'],
                (int) $d['net_paise'], (int) $d['gst_paise'], (int) $d['total_paise'],
                'token_order:' . $orderRef, $source === 'staff' ? 'staff' : 'manage',
            ]
        );
        if ($c['code']['kind'] === 'single_use') {
            $db->query("UPDATE engage_coupon_codes SET status = 'reserved', updated_at = NOW() WHERE id = ? AND status = 'available'", [$c['code']['id']]);
        }
    }

    /**
     * reserved → committed. Runs inside settle()'s transaction.
     *
     * A single-use code is marked redeemed and a coupon that has now reached its total is
     * marked exhausted, exactly as a licence redemption does — otherwise a campaign with a
     * cap would keep giving money away through the credits screen.
     */
    private function commitCoupon(string $orderRef): void
    {
        $db = $this->db();
        $r = $db->query("SELECT * FROM engage_coupon_redemptions WHERE idempotency_key = ? FOR UPDATE", ['token_order:' . $orderRef])->getRowArray();
        if ($r === null || $r['state'] !== 'reserved') {
            return;
        }
        $db->query(
            "UPDATE engage_coupon_redemptions SET state = 'committed', committed_at = NOW(), order_ref = ?, updated_at = NOW() WHERE id = ?",
            [$orderRef, (int) $r['id']]
        );
        if ($r['single_use'] === true || $r['single_use'] === 't') {
            $db->query("UPDATE engage_coupon_codes SET status = 'redeemed', updated_at = NOW() WHERE id = ?", [(int) $r['code_id']]);
        }
        $c = $db->query('SELECT * FROM engage_coupons WHERE id = ?', [(int) $r['coupon_id']])->getRowArray();
        if ($c !== null && $c['max_redemptions_total'] !== null && in_array($c['status'], ['active', 'paused'], true)) {
            $n = (int) $db->query("SELECT COUNT(*) AS n FROM engage_coupon_redemptions WHERE coupon_id = ? AND state = 'committed'", [(int) $c['id']])->getRow()->n;
            if ($n >= (int) $c['max_redemptions_total']) {
                $db->query("UPDATE engage_coupons SET status = 'exhausted', ended_at = NOW(), version = version + 1, updated_at = NOW() WHERE id = ?", [(int) $c['id']]);
            }
        }
    }

    /** reserved → released: the customer gets the coupon back. */
    private function releaseCoupon(string $orderRef, string $reason): void
    {
        $db = $this->db();
        $r = $db->query("SELECT * FROM engage_coupon_redemptions WHERE idempotency_key = ? FOR UPDATE", ['token_order:' . $orderRef])->getRowArray();
        if ($r === null || $r['state'] !== 'reserved') {
            return;
        }
        $db->query(
            "UPDATE engage_coupon_redemptions SET state = 'released', released_at = NOW(), release_reason = ?, updated_at = NOW() WHERE id = ?",
            [mb_substr($reason, 0, 255), (int) $r['id']]
        );
        if ($r['single_use'] === true || $r['single_use'] === 't') {
            $db->query("UPDATE engage_coupon_codes SET status = 'available', updated_at = NOW() WHERE id = ? AND status = 'reserved'", [(int) $r['code_id']]);
        }
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
