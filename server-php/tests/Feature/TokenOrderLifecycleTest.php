<?php

namespace Tests\Feature;

use App\Services\Tokens\TokenOrderService;
use Tests\Support\LicensingTestCase;

/**
 * Buying AI tokens, from quote to granted.
 *
 * Everything here is about money arriving once and tokens being given once. The
 * payment itself happens at the gateway and is not simulated; what is tested is
 * everything Engage promises either side of it.
 */
final class TokenOrderLifecycleTest extends LicensingTestCase
{
    private const HOLDER = 'holder-order-1';
    private const CMP = 4242;

    private function svc(): TokenOrderService
    {
        return new TokenOrderService($this->db());
    }

    private function payer(): array
    {
        return ['name' => 'Priya Sharma', 'email' => 'priya@example.test', 'state_code' => '27'];
    }

    private function order(array $in = [], string $holder = self::HOLDER): array
    {
        return $this->svc()->create($in + ['tokens' => 1000000], $holder, self::CMP, $this->payer());
    }

    // ── the quote ──────────────────────────────────────────────────────────

    public function testRs1000BuysAMillionTokensPlusGst(): void
    {
        $q = $this->svc()->quote(['tokens' => 1000000]);

        $this->assertSame(200, $q['status']);
        $d = $q['data'];
        $this->assertSame([100000, 0, 100000, 18000, 118000], [$d['list_paise'], $d['discount_paise'], $d['net_paise'], $d['gst_paise'], $d['total_paise']],
            'Rs 1,000 taxable + 18% GST = Rs 1,180 collected');
        $this->assertSame(100, $d['price_paise_per_1000'], 'Rs 1.00 per 1,000 tokens');
    }

    public function testBuyingByRupeesGivesTheSameAnswerAsBuyingByTokens(): void
    {
        $byTokens = $this->svc()->quote(['tokens' => 1000000])['data'];
        $byRupees = $this->svc()->quote(['amount_rupees' => '1000'])['data'];

        $this->assertSame($byTokens['tokens'], $byRupees['tokens']);
        $this->assertSame($byTokens['total_paise'], $byRupees['total_paise']);
    }

    public function testAPurchaseTooSmallToChargeForIsRefused(): void
    {
        $q = $this->svc()->quote(['tokens' => 1]);

        $this->assertSame(422, $q['status']);
        $this->assertStringContainsString('voucher', $q['details']['tokens'] ?? '',
            'and it points at the instrument that CAN give something away free');
    }

    public function testNothingAtAllIsRefused(): void
    {
        $this->assertSame(422, $this->svc()->quote([])['status']);
        $this->assertSame(422, $this->svc()->quote(['tokens' => 0])['status']);
        $this->assertSame(422, $this->svc()->quote(['tokens' => -5])['status']);
    }

    // ── the order ──────────────────────────────────────────────────────────

    public function testAnOrderFreezesThePriceItWasQuotedAt(): void
    {
        $o = $this->order();
        $this->assertSame(201, $o['status']);
        $ref = $o['data']['order_ref'];
        $this->assertStringStartsWith('TKO-', $ref);
        $this->assertSame('quoted', $o['data']['status']);

        // The price changes after the customer has been quoted.
        $this->db()->table('engage_settings')->where('key', 'token_price_paise_per_1000')->update(['value_json' => '200']);

        $still = $this->svc()->findByRef($ref);
        $this->assertSame(118000, $still['total_paise'], 'Tuesday\'s quote is still Tuesday\'s price');
        $this->assertSame(100, $still['price_paise_per_1000']);

        $this->db()->table('engage_settings')->where('key', 'token_price_paise_per_1000')->update(['value_json' => '100']);
    }

    /** Two live checkouts is how somebody pays twice for one intention. */
    public function testOneOpenOrderPerHolder(): void
    {
        $first = $this->order();
        $second = $this->order(['tokens' => 2000000]);

        $this->assertSame(200, $second['status'], 'the existing order comes back rather than an error');
        $this->assertTrue($second['data']['existing'] ?? false);
        $this->assertSame($first['data']['order_ref'], $second['data']['order_ref']);
    }

    public function testBillingDetailsAreRequiredBeforeAnInvoiceCanBeRaised(): void
    {
        $r = $this->svc()->create(['tokens' => 1000000], self::HOLDER, self::CMP, ['name' => '', 'state_code' => 'XX']);

        $this->assertSame(422, $r['status']);
        $this->assertArrayHasKey('payer.name', $r['details']);
        $this->assertArrayHasKey('payer.state_code', $r['details']);
    }

    // ── settlement ─────────────────────────────────────────────────────────

    public function testAConfirmedPaymentGrantsExactlyWhatWasBought(): void
    {
        $ref = $this->order()['data']['order_ref'];

        $r = $this->svc()->settle($ref, ['payment_ref' => 'pay_ABC123', 'invoice_no' => 'AIC/26-27/000042', 'invoice_date' => '2026-11-02']);

        $this->assertSame(200, $r['status']);
        $this->assertSame('granted', $r['data']['status']);
        $this->assertSame('AIC/26-27/000042', $r['data']['invoice_no']);

        $grant = $this->db()->table('engage_token_grants')->where('origin_ref', $ref)->get()->getRowArray();
        $this->assertNotNull($grant, 'the grant is queued in the same transaction as the payment');
        $this->assertSame(1000000, (int) $grant['tokens'], 'exactly what was paid for');
        $this->assertSame('purchase', $grant['origin']);
        $this->assertSame('pending', $grant['status'], 'queued, not delivered: Pulse gets it from the outbox');
    }

    /** Finance retrying is correct behaviour; granting twice is not. */
    public function testARepeatedPaymentEventDoesNotGrantTwice(): void
    {
        $ref = $this->order()['data']['order_ref'];
        $this->svc()->settle($ref, ['payment_ref' => 'pay_DUP']);
        $again = $this->svc()->settle($ref, ['payment_ref' => 'pay_DUP']);

        $this->assertSame(200, $again['status'], 'a repeat is a success, or Finance would retry for ever');
        $this->assertTrue($again['data']['duplicate'] ?? false);
        $this->assertSame(1, $this->db()->table('engage_token_grants')->where('origin_ref', $ref)->countAllResults());
    }

    public function testAPaidOrderCannotBeRePriced(): void
    {
        $ref = $this->order()['data']['order_ref'];
        $this->svc()->settle($ref, ['payment_ref' => 'pay_FROZEN']);

        $this->expectExceptionMessageMatches('/cannot be re-priced/');
        $this->db()->query('UPDATE engage_token_orders SET tokens = 99 WHERE order_ref = ?', [$ref]);
    }

    public function testAPaymentForAnUnknownOrderIsNotSilentlyAccepted(): void
    {
        $this->assertSame(404, $this->svc()->settle('TKO-999999', ['payment_ref' => 'pay_X'])['status']);
    }

    public function testAPaymentThatArrivesAfterCancellationIsRefusedRatherThanGranted(): void
    {
        $ref = $this->order()['data']['order_ref'];
        $this->svc()->cancel($ref, 'Changed my mind');

        $r = $this->svc()->settle($ref, ['payment_ref' => 'pay_LATE']);

        $this->assertSame(409, $r['status']);
        $this->assertSame('order_closed', $r['code'], 'refund it rather than granting against a closed order');
        $this->assertSame(0, $this->db()->table('engage_token_grants')->where('origin_ref', $ref)->countAllResults());
    }

    public function testAPaidOrderCannotBeCancelled(): void
    {
        $ref = $this->order()['data']['order_ref'];
        $this->svc()->settle($ref, ['payment_ref' => 'pay_PAID']);

        $r = $this->svc()->cancel($ref, 'oops');
        $this->assertSame(409, $r['status']);
        $this->assertSame('already_paid', $r['code']);
    }

    // ── what Finance is asked for ──────────────────────────────────────────

    public function testTheFinanceRequestCarriesWhoTheTokensAreFor(): void
    {
        $ref = $this->order()['data']['order_ref'];
        $row = $this->db()->table('engage_token_orders')->where('order_ref', $ref)->get()->getRowArray();

        $req = $this->svc()->financeRequest($row);

        $this->assertSame('tokens', $req['purpose']);
        $this->assertSame($ref, $req['source_ref'], 'the order reference is the idempotency key and the link key');
        $this->assertSame(self::CMP, $req['cmp_id'], 'so Manage can show the customer their own invoice');
        $this->assertSame(self::HOLDER, $req['buyer_uuid'], 'so the tokens reach the right account');
        $this->assertSame(1000000, $req['token_count']);
        $this->assertSame(118000, $req['amount_paise']);
        $this->assertSame('exclusive', $req['fee']['price_basis'], 'GST is added on top of the token price');
        $this->assertSame(100000, $req['fee']['taxable_paise']);
    }

    public function testADiscountedOrderTellsFinanceWhatToPrintOnTheInvoice(): void
    {
        // A staff coupon: flat Rs 200 off, unscoped so it applies to a token purchase.
        $this->activeCoupon('TOKENS200', 20000);
        $o = $this->svc()->create(['tokens' => 1000000, 'coupon_code' => 'TOKENS200'], 'holder-disc-1', self::CMP, $this->payer());
        $this->assertSame(201, $o['status'], json_encode($o));

        $row = $this->db()->table('engage_token_orders')->where('order_ref', $o['data']['order_ref'])->get()->getRowArray();
        $req = $this->svc()->financeRequest($row);

        $this->assertSame(100000, $req['fee']['list_amount_paise'], 'the list price is printed');
        $this->assertSame(20000, $req['fee']['discount_paise']);
        $this->assertSame('TOKENS200', $req['fee']['discount_ref'], 'and the code it came from');
        $this->assertSame(80000, $req['fee']['taxable_paise'], 'GST is on the price AFTER the discount');
        $this->assertSame(94400, $req['amount_paise'], 'Rs 800 + 18% = Rs 944');
    }

    /** A coupon written for a licence must not quietly discount tokens. */
    public function testACouponScopedToAProductDoesNotApplyToTokens(): void
    {
        $this->activeCoupon('BOOKSONLY', 20000, ['product_codes' => ['books'], 'offer_codes' => [], 'licence_type_codes' => []]);

        $q = $this->svc()->quote(['tokens' => 1000000, 'coupon_code' => 'BOOKSONLY']);

        $this->assertSame(422, $q['status']);
        $this->assertSame('coupon_invalid', $q['code']);
    }

    public function testAnUnknownCouponIsRefusedRatherThanIgnored(): void
    {
        $q = $this->svc()->quote(['tokens' => 1000000, 'coupon_code' => 'NOPE']);

        $this->assertSame(422, $q['status'], 'silently charging full price would be worse');
        $this->assertSame('coupon_invalid', $q['code']);
    }

    // ── the coupon is actually spent ───────────────────────────────────────
    //
    // engage_coupon_redemptions is the estate's one record of who used which coupon: it is
    // what enforces "once per customer", what makes a campaign run out, and what sales reads
    // to see what a campaign cost. Token purchases used to write the code onto the order and
    // nothing else, so a single-use coupon could be used on every purchase a customer made.

    public function testAnOrderHoldsTheCouponAndThePaymentSpendsIt(): void
    {
        $this->activeCoupon('HOLDME', 20000, null, ['max_redemptions_per_holder' => 1]);
        $o = $this->svc()->create(['tokens' => 1000000, 'coupon_code' => 'HOLDME'], 'holder-hold-1', self::CMP, $this->payer());
        $ref = $o['data']['order_ref'];

        $r = $this->db()->table('engage_coupon_redemptions')->where('idempotency_key', 'token_order:' . $ref)->get()->getRowArray();
        $this->assertNotNull($r, 'the coupon is held the moment the order exists');
        $this->assertSame('reserved', $r['state'], 'held, not spent: an abandoned checkout must not burn it');
        $this->assertSame([80000, 20000], [(int) $r['net_paise'], (int) $r['discount_paise']]);
        $this->assertSame($ref, $r['order_ref']);
        $this->assertNull($r['licence_ref'], 'a purchase of tokens is not a licence');

        $this->svc()->settle($ref, ['payment_ref' => 'pay_HOLD']);
        $r = $this->db()->table('engage_coupon_redemptions')->where('idempotency_key', 'token_order:' . $ref)->get()->getRowArray();
        $this->assertSame('committed', $r['state'], 'the money arriving is what spends it');
    }

    public function testACouponUsableOnceCannotBeUsedTwice(): void
    {
        $this->activeCoupon('ONLYONCE', 20000, null, ['max_redemptions_per_holder' => 1]);
        $ref = $this->svc()->create(['tokens' => 1000000, 'coupon_code' => 'ONLYONCE'], 'holder-once-1', self::CMP, $this->payer())['data']['order_ref'];
        $this->svc()->settle($ref, ['payment_ref' => 'pay_ONCE']);

        $again = $this->svc()->quote(['tokens' => 1000000, 'coupon_code' => 'ONLYONCE'], 'holder-once-1');

        $this->assertSame(422, $again['status'], 'the customer is told at the quote, before they fill anything in');
        $this->assertSame('coupon_invalid', $again['code']);
    }

    /** Somebody halfway through a checkout holds it; a second tab must not be given it too. */
    public function testALiveReservationCountsTowardsTheLimit(): void
    {
        $this->activeCoupon('ONEOPEN', 20000, null, ['max_redemptions_per_holder' => 1]);
        $this->svc()->create(['tokens' => 1000000, 'coupon_code' => 'ONEOPEN'], 'holder-open-1', self::CMP, $this->payer());

        $this->assertSame(422, $this->svc()->quote(['tokens' => 2000000, 'coupon_code' => 'ONEOPEN'], 'holder-open-1')['status']);
    }

    public function testCancellingGivesTheCouponBack(): void
    {
        $this->activeCoupon('GIVEBACK', 20000, null, ['max_redemptions_per_holder' => 1]);
        $ref = $this->svc()->create(['tokens' => 1000000, 'coupon_code' => 'GIVEBACK'], 'holder-back-1', self::CMP, $this->payer())['data']['order_ref'];

        $this->svc()->cancel($ref, 'Changed my mind');

        $r = $this->db()->table('engage_coupon_redemptions')->where('idempotency_key', 'token_order:' . $ref)->get()->getRowArray();
        $this->assertSame('released', $r['state'], 'changing their mind must not cost them the coupon');
        $this->assertSame(200, $this->svc()->quote(['tokens' => 1000000, 'coupon_code' => 'GIVEBACK'], 'holder-back-1')['status'], 'and they can use it again');
    }

    public function testACampaignWithATotalCapRunsOut(): void
    {
        $this->activeCoupon('CAPPED', 20000, null, ['max_redemptions_total' => 1]);
        $ref = $this->svc()->create(['tokens' => 1000000, 'coupon_code' => 'CAPPED'], 'holder-cap-1', self::CMP, $this->payer())['data']['order_ref'];
        $this->svc()->settle($ref, ['payment_ref' => 'pay_CAP']);

        $this->assertSame('exhausted', $this->db()->table('engage_coupons')->where('label', 'CAPPED')->get()->getRowArray()['status'],
            'the cap is reached, so it stops being offered to anybody');
        $this->assertSame(422, $this->svc()->quote(['tokens' => 1000000, 'coupon_code' => 'CAPPED'], 'holder-cap-2')['status']);
    }

    public function testASingleUseCodeIsMarkedRedeemedWhenItIsPaidFor(): void
    {
        $this->activeCoupon('SINGLE1', 20000, null, [], 'single_use');
        $ref = $this->svc()->create(['tokens' => 1000000, 'coupon_code' => 'SINGLE1'], 'holder-single-1', self::CMP, $this->payer())['data']['order_ref'];
        $this->assertSame('reserved', $this->db()->table('engage_coupon_codes')->where('code', 'SINGLE1')->get()->getRowArray()['status']);

        $this->svc()->settle($ref, ['payment_ref' => 'pay_SINGLE']);

        $this->assertSame('redeemed', $this->db()->table('engage_coupon_codes')->where('code', 'SINGLE1')->get()->getRowArray()['status']);
        $this->assertSame(422, $this->svc()->quote(['tokens' => 1000000, 'coupon_code' => 'SINGLE1'], 'holder-single-2')['status'], 'a used single-use code is gone');
    }

    /**
     * @param array<string,mixed>|null $appliesTo
     * @param array<string,mixed> $limits
     */
    private function activeCoupon(string $code, int $flatPaise, ?array $appliesTo = null, array $limits = [], string $codeKind = 'shared'): void
    {
        $db = $this->db();
        $ref = 'CPN-' . substr(md5($code), 0, 6);
        $db->table('engage_coupons')->insert([
            'coupon_ref'    => $ref,
            'code_kind'     => $codeKind,
            'label'         => $code,
            'discount_type' => 'flat',
            'flat_paise'    => $flatPaise,
            'applies_to'    => json_encode($appliesTo ?? ['product_codes' => [], 'offer_codes' => [], 'licence_type_codes' => []]),
            'status'        => 'active',
            'approved_at'   => date('Y-m-d H:i:sP'),
        ] + $limits);
        $db->table('engage_coupon_codes')->insert([
            'coupon_id' => (int) $db->insertID(), 'code' => $code, 'kind' => $codeKind, 'status' => 'available',
        ]);
    }
}
