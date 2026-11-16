<?php
declare(strict_types=1);

namespace App\Services\SellerLicences;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Libraries\Seller\IndianTaxIds;
use App\Libraries\Seller\SellerCrypto;
use App\Models\SettingsModel;
use App\Services\Coupons\PricingService;
use App\Services\Licensing\CatalogueService;
use App\Services\Licensing\LicenceService;
use App\Services\Licensing\OfferService;
use App\Services\Seller\CommissionService;
use App\Services\Tokens\FinanceLinkClient;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Sellers buy licences (docs/SELLING_LICENCES.md §7): an order of offers sold on the `seller` channel, at the price on
 * sale when the order is placed (frozen on the order and its lines), invoiced to the seller's KYC legal name and GSTIN.
 *
 *   awaiting_payment ──settle (Finance payment event, or staff confirming an offline payment)──▶ paid  (stock created)
 *          │            └─ the order's exact total, once: one payment settles one order (uq_engage_slo_payment)
 *          ├──cancel (seller or staff, while unpaid)──▶ cancelled
 *          └──expiry (seller_order_validity_days, engage:seller-orders-expire)──▶ expired (a late payment still settles it)
 *
 * A paid order becomes one licence UNIT per licence bought (SellerLicenceApplyService applies a unit to a customer).
 * Each unit carries its exact share of its line's taxable value and GST; the shares of a line sum to the line.
 *
 * Payment: offline (bank transfer / UPI to the account in setting seller_order_payment_instructions, quoting the order
 * reference; staff confirm it with the UTR and Finance's invoice number) until Finance accepts `purpose: licences` for
 * payment links — then ENGAGE_SELLER_ORDER_PAYMENT_LINKS=1 raises one per order (financeRequest()) and Finance's
 * payment.succeeded settles it through the same code. Nothing ever creates stock but a settled payment.
 */
class SellerLicenceOrderService
{
    public const STATUSES = ['awaiting_payment', 'paid', 'cancelled', 'expired'];
    public const MAX_LINES = 10;
    public const MAX_QUANTITY = 100;

    public function __construct(private ?BaseConnection $db = null, private ?FinanceLinkClient $finance = null)
    {
    }

    public function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /** Payment links for seller orders: switched on, Finance configured, and Aicountly's GST state known. */
    public static function linksEnabled(): bool
    {
        return filter_var(env('ENGAGE_SELLER_ORDER_PAYMENT_LINKS', '0'), FILTER_VALIDATE_BOOLEAN)
            && FinanceLinkClient::configured() && FinanceLinkClient::supplierStateCode() !== null;
    }

    /** Why this seller cannot buy now, or null. */
    public function buyBlock(array $seller): ?array
    {
        if ($seller['status'] !== 'active' || $seller['kyc_status'] !== 'kyc_verified') {
            return ['status' => 403, 'code' => 'seller_not_active', 'error' => 'You can buy licences once your KYC is verified and your seller account is active.'];
        }

        return null;
    }

    /** What a seller can buy: priced offers on the seller channel, with the commission plan of each product. */
    public function catalogue(array $seller): array
    {
        $commissions = Services::commissions();
        $products = (new CatalogueService($this->db()))->forChannel('seller', true);
        foreach ($products as &$p) {
            $plan = $commissions->planFor($p['code']);
            $p['commission'] = $plan ? ['plan' => $plan['name'], 'version' => (int) $plan['version'], 'rule' => CommissionService::ruleLabel($plan['rules'])] : null;
        }
        unset($p);
        $block = $this->buyBlock($seller);

        return [
            'products' => $products,
            'can_buy'  => $block === null,
            'reason'   => $block['error'] ?? null,
            'payment'  => ['mode' => self::linksEnabled() ? 'link' : 'offline', 'order_validity_days' => self::validityDays()],
        ];
    }

    public static function validityDays(): int
    {
        $d = (int) (new SettingsModel())->getSetting('seller_order_validity_days', 7);

        return $d >= 1 && $d <= 60 ? $d : 7;
    }

    // ------------------------------------------------------------------ place an order

    /**
     * {lines: [{offer_code, quantity}]} → 201 the order. $idem (the portal's Idempotency-Key) makes a retry answer the
     * same order (200, replayed); the same key with other lines is 409 idempotency_conflict.
     */
    public function create(array $seller, array $in, ?string $idem): array
    {
        if ($b = $this->buyBlock($seller)) {
            return $b;
        }
        [$lines, $e] = self::parseLines($in['lines'] ?? null);
        if ($e !== []) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the order.', 'details' => $e];
        }
        $hash = hash('sha256', (string) json_encode($lines));
        if ($idem !== null && ($prev = $this->byIdempotency((int) $seller['id'], $idem)) !== null) {
            return hash_equals((string) $prev['request_hash'], $hash)
                ? ['status' => 200, 'data' => $this->sellerView($prev) + ['replayed' => true]]
                : ['status' => 409, 'code' => 'idempotency_conflict', 'error' => 'This request was already used for a different order.'];
        }
        $buyer = $this->buyer($seller);
        if (isset($buyer['status'])) {
            return $buyer;
        }
        $offers = new OfferService($this->db());
        $priced = [];
        foreach ($lines as $i => $l) {
            $o = $offers->publishedVersion($l['offer_code']);
            if (! $o || ! OfferService::isOnSale($o) || ! in_array('seller', OfferService::channelsOf($o), true) || (int) $o['price_paise'] <= 0
                || in_array($o['is_default'], [true, 't', 'true', 1, '1'], true) || LicenceService::coversCourse($o) || ! OfferService::financeSac((string) $o['sac'])) {
                $e["lines.{$i}.offer_code"] = "{$l['offer_code']} is not on sale to sellers.";

                continue;
            }
            $priced[] = ['offer' => $o, 'quantity' => $l['quantity']] + self::priceLine($o, $l['quantity']);
        }
        if ($e !== []) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the order.', 'details' => $e];
        }
        $sum = static fn (string $k): int => array_sum(array_column($priced, $k));
        $db = $this->db();
        $ref = 'SLO-' . str_pad((string) $db->query("SELECT nextval('engage_seller_licence_order_ref_seq') AS n")->getRow()->n, 6, '0', STR_PAD_LEFT);
        $mode = self::linksEnabled() ? 'link' : 'offline';
        try {
            $orderId = S::strictTx($db, function () use ($db, $ref, $seller, $buyer, $priced, $sum, $mode, $idem, $hash): int {
                $db->transBegin();
                try {
                    $id = (int) $db->query(
                        "INSERT INTO engage_seller_licence_orders (order_ref, seller_id, status, list_paise, taxable_paise, gst_paise, total_paise, unit_count,
                            buyer_legal_name, buyer_gstin, buyer_state_code, buyer_address, buyer_pincode, buyer_email, buyer_mobile, payment_mode, expires_at,
                            idempotency_key, request_hash)
                         VALUES (?, ?, 'awaiting_payment', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + make_interval(days => ?), ?, ?) RETURNING id",
                        [$ref, (int) $seller['id'], $sum('line_amount_paise'), $sum('taxable_paise'), $sum('gst_paise'), $sum('total_paise'), $sum('quantity'),
                            $buyer['legal_name'], $buyer['gstin'], $buyer['state_code'], $buyer['address'], $buyer['pincode'], $buyer['email'], $buyer['mobile'],
                            $mode, self::validityDays(), $idem, $idem !== null ? $hash : null]
                    )->getRow()->id;
                    foreach ($priced as $n => $p) {
                        $o = $p['offer'];
                        $db->query(
                            'INSERT INTO engage_seller_licence_order_lines (order_id, line_no, offer_id, offer_code, offer_version, offer_snapshot, product_code, label,
                                quantity, unit_price_paise, price_basis, gst_rate, sac, line_amount_paise, taxable_paise, gst_paise, total_paise)
                             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, CAST(? AS NUMERIC), ?, ?, ?, ?, ?)',
                            [$id, $n + 1, (int) $o['id'], $o['offer_code'], (int) $o['version'], json_encode(OfferService::snapshot($o)), $o['product_code'], $o['label'],
                                $p['quantity'], $p['unit_price_paise'], $o['price_basis'], OfferService::rate((string) $o['gst_rate']), $o['sac'], $p['line_amount_paise'],
                                $p['taxable_paise'], $p['gst_paise'], $p['total_paise']]
                        );
                    }
                    $db->transCommit();

                    return $id;
                } catch (\Throwable $ex) {
                    $db->transRollback();

                    throw $ex;
                }
            });
        } catch (\Throwable $ex) {
            if ($idem !== null && str_contains($ex->getMessage(), 'uq_engage_slo_idem') && ($prev = $this->byIdempotency((int) $seller['id'], $idem)) !== null) {
                return ['status' => 200, 'data' => $this->sellerView($prev) + ['replayed' => true]];
            }
            log_message('error', 'Seller licence order failed: ' . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not place the order. Nothing was charged.'];
        }
        Services::sellerAuth()->audit('seller.licence_order_created', $seller, ['order_ref' => $ref, 'total_paise' => $sum('total_paise'), 'units' => $sum('quantity')]);
        if ($mode === 'link') {
            // Outside the transaction: an HTTP call never holds a database transaction open. A link that cannot be
            // raised leaves the order payable offline and retryable (POST licence-orders/{ref}/payment-link).
            $this->raiseLink($orderId);
        }

        return ['status' => 201, 'data' => $this->sellerView($this->row($orderId))];
    }

    /** @return array{0: list<array{offer_code:string, quantity:int}>, 1: array<string,string>} */
    private static function parseLines(mixed $in): array
    {
        if (! is_array($in) || $in === [] || ! array_is_list($in)) {
            return [[], ['lines' => 'Choose at least one licence to buy.']];
        }
        if (count($in) > self::MAX_LINES) {
            return [[], ['lines' => 'At most ' . self::MAX_LINES . ' different licences in one order.']];
        }
        $out = [];
        $e = [];
        $seen = [];
        foreach ($in as $i => $l) {
            $code = is_array($l) ? strtolower(trim((string) ($l['offer_code'] ?? ''))) : '';
            $q = is_array($l) ? ($l['quantity'] ?? null) : null;
            if (is_string($q) && ctype_digit($q)) {
                $q = (int) $q;
            }
            if (! preg_match('/\A[a-z0-9][a-z0-9_\-]{1,63}\z/', $code)) {
                $e["lines.{$i}.offer_code"] = 'Choose a licence.';
            } elseif (isset($seen[$code])) {
                $e["lines.{$i}.offer_code"] = 'Each licence once per order — change its quantity instead.';
            }
            if (! is_int($q) || $q < 1 || $q > self::MAX_QUANTITY) {
                $e["lines.{$i}.quantity"] = 'Quantity: 1 to ' . self::MAX_QUANTITY . '.';
            }
            $seen[$code] = true;
            $out[] = ['offer_code' => $code, 'quantity' => is_int($q) ? $q : 0];
        }

        return [$out, $e];
    }

    /**
     * One order line as Finance invoices it: the unit price on the offer's basis × quantity is the line amount, and
     * GST is worked out once on that amount (round half up), exactly like Finance's GstCalculator::line.
     *
     * @return array{unit_price_paise:int, line_amount_paise:int, taxable_paise:int, gst_paise:int, total_paise:int}
     */
    public static function priceLine(array $offer, int $quantity): array
    {
        $unit = (int) $offer['price_paise'];
        $amount = $unit * $quantity;
        $g = PricingService::gst($amount, (int) PricingService::rateBp(OfferService::rate((string) $offer['gst_rate'])), (string) $offer['price_basis']);

        return ['unit_price_paise' => $unit, 'line_amount_paise' => $amount, 'taxable_paise' => $g['taxable'], 'gst_paise' => $g['gst'], 'total_paise' => $g['total']];
    }

    /**
     * $quantity shares of a line's taxable value and GST that sum to the line exactly (the first units carry the odd
     * paise) — each licence unit's commission basis and revenue.
     *
     * @return list<array{taxable_paise:int, gst_paise:int, total_paise:int}>
     */
    public static function shares(int $taxable, int $gst, int $quantity): array
    {
        $out = [];
        for ($i = 0; $i < $quantity; $i++) {
            $t = intdiv($taxable, $quantity) + ($i < $taxable % $quantity ? 1 : 0);
            $g = intdiv($gst, $quantity) + ($i < $gst % $quantity ? 1 : 0);
            $out[] = ['taxable_paise' => $t, 'gst_paise' => $g, 'total_paise' => $t + $g];
        }

        return $out;
    }

    /**
     * The buyer as invoiced, from the seller's verified KYC: legal name, GSTIN (decrypted only here), the GST state
     * (the GSTIN's, else the KYC address state) as the place of supply, address and PIN. An error result otherwise.
     *
     * @return array<string,mixed>
     */
    private function buyer(array $seller): array
    {
        $k = $this->db()->table('engage_seller_kyc')->where('seller_id', (int) $seller['id'])->get()->getRowArray();
        if (! $k || $k['status'] !== 'kyc_verified' || trim((string) $k['legal_name']) === '') {
            return ['status' => 403, 'code' => 'kyc_required', 'error' => 'Your KYC must be verified before you can buy licences.'];
        }
        $gstin = null;
        if (! empty($k['gstin_enc'])) {
            $crypto = new SellerCrypto();
            if (! $crypto->isConfigured()) {
                return ['status' => 503, 'code' => 'kyc_unavailable', 'error' => 'Billing details are temporarily unavailable. Please try again later.'];
            }
            $gstin = strtoupper($crypto->decrypt((string) $k['gstin_enc']));
        }
        $state = $gstin !== null ? IndianTaxIds::gstStateCode($gstin) : IndianTaxIds::stateCodeFor((string) $k['state']);
        if ($state === null || ! IndianTaxIds::isValidStateCode($state)) {
            return ['status' => 422, 'code' => 'billing_state_unknown', 'error' => 'We could not tell your GST state from your KYC address. Contact Aicountly to correct it.'];
        }
        $address = implode(', ', array_filter([trim((string) $k['address_line1']), trim((string) $k['address_line2']), trim((string) $k['city']), trim((string) $k['state'])], 'strlen'));

        return [
            'legal_name' => trim((string) $k['legal_name']), 'gstin' => $gstin, 'state_code' => $state, 'address' => $address !== '' ? $address : null,
            'pincode' => $k['pincode'] ?: null, 'email' => strtolower((string) $seller['email']), 'mobile' => $seller['mobile'] ?: null,
        ];
    }

    // ------------------------------------------------------------------ payment

    /**
     * Why an order has no payment link, for the seller: Finance or the gateway was unavailable (try again), or Finance
     * refused the order's billing details (bank transfer, or Aicountly). Null when no attempt failed yet.
     */
    private static function linkProblem(array $o): ?string
    {
        $err = (string) ($o['link_error'] ?? '');
        if ($err === '') {
            return null;
        }
        $code = strtok($err, ':') ?: '';
        $refused = in_array($code, ['validation_failed', 'fee_breakdown_mismatch', 'gstin_state_mismatch', 'idempotency_conflict', 'bad_request'], true);

        return $refused
            ? 'The payment link could not be raised for this order\'s billing details. Pay by bank transfer as shown below, or contact Aicountly quoting ' . $o['order_ref'] . '.'
            : 'The payment link could not be raised just now. Try again in a few minutes, or pay by bank transfer as shown below.';
    }

    /** Ask Finance for this order's payment link; the order records the link or why it could not be raised. */
    public function raiseLink(int $orderId): array
    {
        $o = $this->row($orderId);
        if (! $o || $o['status'] !== 'awaiting_payment') {
            return ['status' => 409, 'code' => 'not_payable', 'error' => 'This order is not waiting for payment.'];
        }
        if (! self::linksEnabled()) {
            return ['status' => 409, 'code' => 'links_off', 'error' => 'Online payment is not available yet: pay by bank transfer quoting ' . $o['order_ref'] . '.'];
        }
        $r = ($this->finance ?? new FinanceLinkClient())->raise((string) $o['order_ref'], $this->financeRequest($o, $this->lines((int) $o['id'])));
        if ($r['ok']) {
            $l = $r['link'];
            $this->db()->query("UPDATE engage_seller_licence_orders SET payment_mode = 'link', link_id = ?, link_url = ?, link_expires_at = ?, link_error = NULL, updated_at = NOW()
                WHERE id = ? AND status = 'awaiting_payment'", [(string) ($l['link_id'] ?? ''), $l['url'] ?? null,
                    isset($l['expire_at']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $l['expire_at'])) . '+00' : null, $orderId]);
        } else {
            $this->db()->query('UPDATE engage_seller_licence_orders SET link_error = ?, updated_at = NOW() WHERE id = ?',
                [mb_substr(($r['code'] ?? 'error') . ': ' . ($r['message'] ?? ''), 0, 500), $orderId]);
        }

        return ['status' => 200, 'data' => $this->sellerView($this->row($orderId))];
    }

    /**
     * What Finance needs to raise the payment link and the invoice for a seller order — the proposed `purpose:
     * licences` contract (docs/SELLING_LICENCES.md §9). Fees are split exactly as Finance's GstCalculator does for the
     * payer's state (FinanceLinkClient::split); one invoice line per order line.
     *
     * @param list<array<string,mixed>> $lines
     */
    public function financeRequest(array $o, array $lines): array
    {
        $state = (string) $o['buyer_state_code'];
        $items = [];
        $tot = ['taxable_paise' => 0, 'cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 0];
        foreach ($lines as $l) {
            [$c, $s, $i] = FinanceLinkClient::split((int) $l['gst_paise'], $state);
            $items[] = [
                'description' => $l['label'] . ' — ' . (int) $l['quantity'] . ' licence' . ((int) $l['quantity'] === 1 ? '' : 's'),
                'product_code' => $l['product_code'], 'offer_code' => $l['offer_code'], 'offer_version' => (int) $l['offer_version'],
                'quantity' => (int) $l['quantity'], 'unit_price_paise' => (int) $l['unit_price_paise'], 'amount_paise' => (int) $l['line_amount_paise'],
                'price_basis' => $l['price_basis'], 'gst_rate' => OfferService::rate((string) $l['gst_rate']), 'sac' => $l['sac'],
                'taxable_paise' => (int) $l['taxable_paise'], 'cgst_paise' => $c, 'sgst_paise' => $s, 'igst_paise' => $i,
            ];
            $tot['taxable_paise'] += (int) $l['taxable_paise'];
            $tot['cgst_paise'] += $c;
            $tot['sgst_paise'] += $s;
            $tot['igst_paise'] += $i;
        }

        return [
            'purpose'      => 'licences',
            'source_ref'   => (string) $o['order_ref'],
            'description'  => 'Aicountly licences — order ' . $o['order_ref'],
            'seller_code'  => (string) $this->db()->query('SELECT seller_code FROM engage_sellers WHERE id = ?', [(int) $o['seller_id']])->getRow()->seller_code,
            'buyer_gstin'  => $o['buyer_gstin'],
            'payer'        => array_filter([
                'name' => (string) $o['buyer_legal_name'], 'email' => $o['buyer_email'], 'mobile' => $o['buyer_mobile'], 'state_code' => $state,
                'address' => $o['buyer_address'], 'pincode' => $o['buyer_pincode'],
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'amount_paise' => (int) $o['total_paise'],
            'lines'        => $items,
            'fee'          => $tot,
            'expire_at'    => S::iso((string) $o['expires_at']),
        ];
    }

    /**
     * A payment for the order: mark it paid and create its stock, in one transaction. Idempotent on payment_ref: the
     * same payment again answers 200 duplicate. The amount must be the order's total to the paisa (409 amount_mismatch
     * and nothing changes). A cancelled order is never settled (409 order_closed — refund it); an expired one is (the
     * seller paid the price they were quoted).
     *
     * @param array{payment_ref:string, amount_paise:int|null, invoice_no?:?string, invoice_date?:?string, paid_at?:?string} $p
     * @param 'finance'|'staff' $via
     */
    public function settle(string $ref, array $p, string $via, ?array $staff = null): array
    {
        $paymentRef = trim((string) ($p['payment_ref'] ?? ''));
        $amount = $p['amount_paise'] ?? null;
        $e = [];
        if (! preg_match('/\A[A-Za-z0-9_.:\/\-]{3,96}\z/', $paymentRef)) {
            $e['payment_ref'] = 'The payment reference (UTR, gateway payment id): 3–96 letters, digits or _ . : / -';
        }
        if (! is_int($amount) || $amount < 1) {
            $e['amount'] = 'The amount received.';
        }
        $invoice = trim((string) ($p['invoice_no'] ?? ''));
        if ($invoice !== '' && ! preg_match('#\A[A-Za-z0-9/_.\-]{1,40}\z#', $invoice)) {
            $e['invoice_no'] = 'An invoice number may use letters, digits and / _ . - (40 at most).';
        }
        if ($via === 'staff' && $invoice === '') {
            $e['invoice_no'] = 'Finance\'s invoice number for this payment.';
        }
        $invoiceDate = trim((string) ($p['invoice_date'] ?? ''));
        if ($invoiceDate !== '' && (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $invoiceDate) || strtotime($invoiceDate) === false)) {
            $e['invoice_date'] = 'A date like 2026-11-15.';
        }
        $paidAt = trim((string) ($p['paid_at'] ?? '')) !== '' ? S::parseInstant((string) $p['paid_at']) : gmdate('Y-m-d H:i:s') . '+00';
        if ($paidAt === null || strtotime($paidAt) > time() + 86400) {
            $e['paid_at'] = 'When the money arrived (not in the future).';
        }
        if ($e !== []) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the payment.', 'details' => $e];
        }
        $db = $this->db();
        try {
            $r = S::strictTx($db, function () use ($db, $ref, $paymentRef, $amount, $invoice, $invoiceDate, $paidAt, $via, $staff): array {
                $db->transBegin();
                try {
                    $out = $this->settleTx($ref, $paymentRef, (int) $amount, $invoice !== '' ? $invoice : null, $invoiceDate !== '' ? $invoiceDate : null, (string) $paidAt, $via, $staff);
                    ($out['status'] ?? 500) < 300 ? $db->transCommit() : $db->transRollback();

                    return $out;
                } catch (\Throwable $ex) {
                    $db->transRollback();

                    throw $ex;
                }
            });
        } catch (\Throwable $ex) {
            if (str_contains($ex->getMessage(), 'uq_engage_slo_payment')) {
                return ['status' => 409, 'code' => 'payment_used', 'error' => "Payment {$paymentRef} has already settled another order."];
            }
            log_message('error', "Seller licence order {$ref}: settlement failed: " . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not record the payment.'];
        }
        if (($r['status'] ?? 500) === 200 && empty($r['duplicate'])) {
            Services::auditService()->log('seller.licence_order_paid', array_filter([
                'subject_kind' => 'seller_licence_order', 'subject_id' => (int) $r['order']['id'],
                'metadata' => ['order_ref' => $ref, 'payment_ref' => $paymentRef, 'amount_paise' => $amount, 'invoice_no' => $invoice ?: null, 'via' => $via, 'units' => (int) $r['order']['unit_count']],
                'fanout_console' => true,
                'actor_email' => $staff === null ? 'system:finance' : null, 'actor_role' => $staff === null ? 'system' : null,
            ], static fn ($v) => $v !== null));
        }
        if (isset($r['order'])) {
            $r['data'] = $this->staffView($r['order']) + (empty($r['duplicate']) ? [] : ['duplicate' => true]);
            unset($r['order']);
        }

        return $r;
    }

    private function settleTx(string $ref, string $paymentRef, int $amount, ?string $invoice, ?string $invoiceDate, string $paidAt, string $via, ?array $staff): array
    {
        $db = $this->db();
        $o = $db->query('SELECT * FROM engage_seller_licence_orders WHERE order_ref = ? FOR UPDATE', [$ref])->getRowArray();
        if (! $o) {
            return ['status' => 404, 'code' => 'not_found', 'error' => 'No such licence order.'];
        }
        if ($o['status'] === 'paid') {
            // Finance retrying is correct behaviour; an error would make it retry for ever.
            return $o['payment_ref'] === $paymentRef ? ['status' => 200, 'order' => $o, 'duplicate' => true]
                : ['status' => 409, 'code' => 'already_paid', 'error' => "This order was already paid ({$o['payment_ref']})."];
        }
        if ($o['status'] === 'cancelled') {
            return ['status' => 409, 'code' => 'order_closed', 'error' => 'This order was cancelled before the payment arrived. Refund the payment and place a new order.'];
        }
        if ($amount !== (int) $o['total_paise']) {
            log_message('critical', "Seller licence order {$ref}: payment {$paymentRef} of {$amount} paise does not match the order total {$o['total_paise']}.");

            return ['status' => 409, 'code' => 'amount_mismatch', 'error' => 'The amount received (₹' . number_format($amount / 100, 2) . ') is not the order total (₹'
                . number_format((int) $o['total_paise'] / 100, 2) . '). Nothing was recorded.', 'details' => ['expected_paise' => (int) $o['total_paise'], 'received_paise' => $amount]];
        }
        $db->query("UPDATE engage_seller_licence_orders SET status = 'paid', payment_ref = ?, amount_paid_paise = ?, invoice_no = ?, invoice_date = ?, paid_at = ?,
                settled_via = ?, settled_by = ?, updated_at = NOW() WHERE id = ?",
            [$paymentRef, $amount, $invoice, $invoiceDate, $paidAt, $via, $staff !== null ? (int) $staff['id'] : null, (int) $o['id']]);
        foreach ($this->lines((int) $o['id']) as $l) {
            foreach (self::shares((int) $l['taxable_paise'], (int) $l['gst_paise'], (int) $l['quantity']) as $share) {
                $unitRef = 'SLU-' . str_pad((string) $db->query("SELECT nextval('engage_seller_licence_unit_ref_seq') AS n")->getRow()->n, 6, '0', STR_PAD_LEFT);
                $db->query(
                    "INSERT INTO engage_seller_licence_units (unit_ref, order_id, line_id, seller_id, offer_id, offer_code, offer_version, product_code,
                        taxable_paise, gst_paise, total_paise, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')",
                    [$unitRef, (int) $o['id'], (int) $l['id'], (int) $o['seller_id'], (int) $l['offer_id'], $l['offer_code'], (int) $l['offer_version'],
                        $l['product_code'], $share['taxable_paise'], $share['gst_paise'], $share['total_paise']]
                );
            }
        }

        return ['status' => 200, 'order' => $db->query('SELECT * FROM engage_seller_licence_orders WHERE id = ?', [(int) $o['id']])->getRowArray()];
    }

    /** Seller (own order) or staff cancel an unpaid order. */
    public function cancel(string $ref, string $reason, string $by, ?int $sellerId = null, ?array $staff = null): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Say why the order is cancelled.', 'details' => ['reason' => 'Required.']];
        }
        $o = $this->byRef($ref, $sellerId);
        if (! $o) {
            return ['status' => 404, 'code' => 'not_found', 'error' => 'No such licence order.'];
        }
        if (! in_array($o['status'], ['awaiting_payment', 'expired'], true)) {
            return ['status' => 409, 'code' => 'not_cancellable', 'error' => $o['status'] === 'paid' ? 'This order is paid: it cannot be cancelled. Unused licences can be refunded by Aicountly.' : 'This order is already ' . $o['status'] . '.'];
        }
        $this->db()->query("UPDATE engage_seller_licence_orders SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ?, cancelled_by_kind = ?, updated_at = NOW()
            WHERE id = ? AND status IN ('awaiting_payment','expired')", [mb_substr($reason, 0, 1000), $by, (int) $o['id']]);
        if ($this->db()->affectedRows() !== 1) {
            return ['status' => 409, 'code' => 'conflict', 'error' => 'This order changed meanwhile. Reload.'];
        }
        $meta = ['order_ref' => $o['order_ref'], 'reason' => $reason];
        if ($by === 'seller') {
            Services::sellerAuth()->audit('seller.licence_order_cancelled', Services::sellerAccounts()->find((int) $o['seller_id']) ?? [], $meta);
        } else {
            Services::auditService()->log('seller.licence_order_cancelled', ['subject_kind' => 'seller_licence_order', 'subject_id' => (int) $o['id'], 'metadata' => $meta, 'fanout_console' => true]);
        }
        $row = $this->row((int) $o['id']);

        return ['status' => 200, 'data' => $by === 'seller' ? $this->sellerView($row) : $this->staffView($row)];
    }

    /** Unpaid orders past their validity become expired (engage:seller-orders-expire). @return int how many */
    public function expireDue(): int
    {
        return count($this->db()->query("UPDATE engage_seller_licence_orders SET status = 'expired', updated_at = NOW()
            WHERE status = 'awaiting_payment' AND expires_at <= NOW() RETURNING id")->getResultArray());
    }

    /** Staff take an unused unit out of the seller's stock (e.g. refunded), with a reason. */
    public function voidUnit(string $unitRef, string $reason, array $staff): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Say why the licence is withdrawn.', 'details' => ['reason' => 'Required.']];
        }
        $this->db()->query("UPDATE engage_seller_licence_units SET status = 'void', voided_at = NOW(), voided_by = ?, void_reason = ?, updated_at = NOW()
            WHERE unit_ref = ? AND status = 'available'", [(int) $staff['id'], mb_substr($reason, 0, 1000), $unitRef]);
        if ($this->db()->affectedRows() !== 1) {
            $u = $this->db()->table('engage_seller_licence_units')->where('unit_ref', $unitRef)->get()->getRowArray();

            return $u ? ['status' => 409, 'code' => 'unit_not_available', 'error' => "This licence is {$u['status']}: only an unused licence can be withdrawn."]
                : ['status' => 404, 'code' => 'not_found', 'error' => 'No such licence unit.'];
        }
        $u = $this->db()->table('engage_seller_licence_units')->where('unit_ref', $unitRef)->get()->getRowArray();
        Services::auditService()->log('seller.licence_unit_voided', ['subject_kind' => 'seller_licence_unit', 'subject_id' => (int) $u['id'],
            'metadata' => ['unit_ref' => $unitRef, 'reason' => $reason, 'seller_id' => (int) $u['seller_id']], 'fanout_console' => true]);

        return ['status' => 200, 'data' => SellerLicenceApplyService::unitView($u)];
    }

    // ------------------------------------------------------------------ reads

    public function row(int $id): ?array
    {
        return $this->db()->query('SELECT * FROM engage_seller_licence_orders WHERE id = ?', [$id])->getRowArray() ?: null;
    }

    public function byRef(string $ref, ?int $sellerId = null): ?array
    {
        return $this->db()->query('SELECT * FROM engage_seller_licence_orders WHERE order_ref = ?' . ($sellerId !== null ? ' AND seller_id = ?' : ''),
            $sellerId !== null ? [$ref, $sellerId] : [$ref])->getRowArray() ?: null;
    }

    private function byIdempotency(int $sellerId, string $key): ?array
    {
        return $this->db()->query('SELECT * FROM engage_seller_licence_orders WHERE seller_id = ? AND idempotency_key = ?', [$sellerId, $key])->getRowArray() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $orderId): array
    {
        return $this->db()->query('SELECT * FROM engage_seller_licence_order_lines WHERE order_id = ? ORDER BY line_no', [$orderId])->getResultArray();
    }

    /** Status as shown: an unpaid order past its validity reads expired before the job records it. */
    public static function effectiveStatus(array $o): string
    {
        return $o['status'] === 'awaiting_payment' && strtotime((string) $o['expires_at']) <= time() ? 'expired' : (string) $o['status'];
    }

    /** @return array<string,mixed> */
    public function sellerView(array $o): array
    {
        $status = self::effectiveStatus($o);
        $units = $this->db()->query('SELECT status, COUNT(*) AS n FROM engage_seller_licence_units WHERE order_id = ? GROUP BY status', [(int) $o['id']])->getResultArray();
        $byStatus = array_column($units, 'n', 'status');
        $payable = $status === 'awaiting_payment';
        $instructions = trim((string) (new SettingsModel())->getSetting('seller_order_payment_instructions', ''));

        return [
            'order_ref'    => $o['order_ref'],
            'status'       => $status,
            'currency'     => $o['currency'],
            'list_paise'   => (int) $o['list_paise'],
            'taxable_paise' => (int) $o['taxable_paise'],
            'gst_paise'    => (int) $o['gst_paise'],
            'total_paise'  => (int) $o['total_paise'],
            'unit_count'   => (int) $o['unit_count'],
            'lines'        => array_map(static fn (array $l): array => [
                'offer_code' => $l['offer_code'], 'offer_version' => (int) $l['offer_version'], 'label' => $l['label'], 'product_code' => $l['product_code'],
                'quantity' => (int) $l['quantity'], 'unit_price_paise' => (int) $l['unit_price_paise'], 'price_basis' => $l['price_basis'],
                'gst_rate' => OfferService::rate((string) $l['gst_rate']), 'line_amount_paise' => (int) $l['line_amount_paise'],
                'taxable_paise' => (int) $l['taxable_paise'], 'gst_paise' => (int) $l['gst_paise'], 'total_paise' => (int) $l['total_paise'],
                'term' => S::cycleLabel((int) (S::json($l['offer_snapshot'])['term_months'] ?? 0), (int) (S::json($l['offer_snapshot'])['term_days'] ?? 0)),
            ], $this->lines((int) $o['id'])),
            // As KYC shows it everywhere: the GSTIN masked (staff see it in full).
            'buyer'        => ['legal_name' => $o['buyer_legal_name'], 'gstin_masked' => $o['buyer_gstin'] ? substr((string) $o['buyer_gstin'], 0, 2) . str_repeat('X', 9) . substr((string) $o['buyer_gstin'], -4) : null,
                'state_code' => $o['buyer_state_code']],
            'payment'      => [
                'mode'         => $o['payment_mode'],
                'link_url'     => $payable && $o['payment_mode'] === 'link' ? $o['link_url'] : null,
                'link_expires_at' => $payable ? S::iso($o['link_expires_at']) : null,
                // Why there is no link yet, in words for the seller (staff see Finance's own answer as link_error).
                'link_problem' => $payable && $o['payment_mode'] === 'link' && empty($o['link_url']) ? self::linkProblem($o) : null,
                // Bank transfer whenever there is no link to pay: a sales manager confirms that payment either way.
                'instructions' => $payable && ($o['payment_mode'] === 'offline' || empty($o['link_url']))
                    ? ($instructions !== '' ? $instructions : 'Aicountly will share the bank details for this order.') : null,
                'reference'    => $o['order_ref'],
                'pay_by'       => $payable ? S::iso((string) $o['expires_at']) : null,
            ],
            'invoice_no'   => $o['invoice_no'],
            'invoice_date' => $o['invoice_date'],
            'paid_at'      => S::iso($o['paid_at']),
            'units'        => ['available' => (int) ($byStatus['available'] ?? 0), 'applied' => (int) ($byStatus['applied'] ?? 0), 'void' => (int) ($byStatus['void'] ?? 0)],
            'cancelled_at' => S::iso($o['cancelled_at']),
            'cancel_reason' => $o['cancel_reason'],
            'created_at'   => S::iso($o['created_at']),
        ];
    }

    /** @return array<string,mixed> */
    public function staffView(array $o): array
    {
        $seller = $this->db()->query('SELECT id, seller_code, name, organization, email FROM engage_sellers WHERE id = ?', [(int) $o['seller_id']])->getRowArray();

        return $this->sellerView($o) + [
            'id'                => (int) $o['id'],
            'seller'            => $seller ? ['id' => (int) $seller['id'], 'seller_code' => $seller['seller_code'], 'name' => $seller['name'], 'organization' => $seller['organization']] : null,
            'stored_status'     => $o['status'],
            'buyer_gstin'       => $o['buyer_gstin'],
            'buyer_email'       => $o['buyer_email'],
            'buyer_address'     => $o['buyer_address'],
            'buyer_pincode'     => $o['buyer_pincode'],
            'payment_ref'       => $o['payment_ref'],
            'amount_paid_paise' => $o['amount_paid_paise'] !== null ? (int) $o['amount_paid_paise'] : null,
            'settled_via'       => $o['settled_via'],
            'link_error'        => $o['link_error'],
            'expires_at'        => S::iso((string) $o['expires_at']),
            'cancelled_by_kind' => $o['cancelled_by_kind'],
        ];
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function listForSeller(int $sellerId, ?string $status, int $limit, int $offset): array
    {
        return $this->listWhere(['o.seller_id = ?'], [$sellerId], $status, $limit, $offset, fn (array $o) => $this->sellerView($o));
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function listForStaff(array $q, int $limit, int $offset): array
    {
        $where = [];
        $binds = [];
        if (! empty($q['seller_id']) && ctype_digit((string) $q['seller_id'])) {
            $where[] = 'o.seller_id = ?';
            $binds[] = (int) $q['seller_id'];
        }
        if (! empty($q['q'])) {
            $where[] = '(o.order_ref ILIKE ? OR o.buyer_legal_name ILIKE ? OR o.payment_ref ILIKE ? OR o.invoice_no ILIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $q['q']) . '%';
            array_push($binds, $like, $like, $like, $like);
        }

        return $this->listWhere($where, $binds, isset($q['status']) ? (string) $q['status'] : null, $limit, $offset, fn (array $o) => $this->staffView($o));
    }

    private function listWhere(array $where, array $binds, ?string $status, int $limit, int $offset, callable $view): array
    {
        if ($status !== null && $status !== '') {
            if ($status === 'expired') {
                $where[] = "(o.status = 'expired' OR (o.status = 'awaiting_payment' AND o.expires_at <= NOW()))";
            } elseif ($status === 'awaiting_payment') {
                $where[] = "o.status = 'awaiting_payment' AND o.expires_at > NOW()";
            } elseif (in_array($status, self::STATUSES, true)) {
                $where[] = 'o.status = ?';
                $binds[] = $status;
            }
        }
        $sql = 'FROM engage_seller_licence_orders o' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');
        $total = (int) $this->db()->query('SELECT COUNT(*) AS n ' . $sql, $binds)->getRow()->n;
        $rows = $this->db()->query('SELECT o.* ' . $sql . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?', array_merge($binds, [$limit, $offset]))->getResultArray();

        return ['items' => array_map($view, $rows), 'total' => $total];
    }
}
