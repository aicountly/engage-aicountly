<?php

namespace Tests\Feature;

use App\Services\Tokens\FinanceLinkClient;
use App\Services\Tokens\TokenOrderService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
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
        return ['name' => 'Priya Sharma', 'email' => 'priya@example.test', 'state_code' => '27', 'mobile' => '98765 43210'];
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
    /**
     * One unpaid order per holder and scope. An order whose payment page was opened comes back as 409 with the order,
     * so Manage shows "pay it or cancel it" rather than silently sending the customer to an older page; buying this
     * company's credits is a different purchase from buying the workspace's, so both may be open.
     */
    public function testOneOpenOrderPerHolderAndScope(): void
    {
        $first = $this->order();
        $this->svc()->linkRaised((int) $first['data']['id'], ['link_id' => 'fin_pl_OPEN1', 'url' => 'https://pay.example/1']);

        $second = $this->order(['tokens' => 2000000]);
        $this->assertSame(409, $second['status']);
        $this->assertSame('open_order_exists', $second['code']);
        $this->assertSame([$first['data']['order_ref'], 'https://pay.example/1'], [$second['details']['order']['order_ref'], $second['details']['order']['link_url']]);

        $company = $this->order(['scope' => 'company']);
        $this->assertSame(201, $company['status'], 'company credits alongside workspace credits');
        $this->assertSame(['company', self::CMP], [$company['data']['scope'], $company['data']['credit_cmp_id']]);
    }

    /** An order Finance never opened a page for cannot be paid, so a new attempt replaces it. */
    public function testAnOrderWithoutAPaymentPageIsReplaced(): void
    {
        $first = $this->order();
        $second = $this->order(['tokens' => 2000000]);

        $this->assertSame(201, $second['status']);
        $this->assertSame('cancelled', $this->svc()->findByRef($first['data']['order_ref'])['status']);
    }

    public function testAMobileNumberIsNeededForThePaymentPage(): void
    {
        $r = $this->svc()->create(['tokens' => 1000000], self::HOLDER, self::CMP, ['name' => 'Priya', 'state_code' => '27', 'mobile' => '12345']);
        $this->assertSame(422, $r['status']);
        $this->assertArrayHasKey('payer.mobile', $r['details']);

        $ok = $this->svc()->create(['tokens' => 1000000], self::HOLDER, self::CMP, ['name' => 'Priya', 'state_code' => '27', 'mobile' => '098765-43210']);
        $this->assertSame(201, $ok['status']);
        $row = $this->db()->table('engage_token_orders')->where('order_ref', $ok['data']['order_ref'])->get()->getRowArray();
        $this->assertSame('+919876543210', $row['payer_mobile'], 'normalised as Finance wants it');
        $this->assertSame('+919876543210', $this->svc()->financeRequest($row)['payer']['mobile']);
    }

    public function testScopeIsWorkspaceOrOneCompany(): void
    {
        $this->assertSame(['scope'], array_keys($this->svc()->quote(['tokens' => 1000000, 'scope' => 'galaxy'])['details']));
        $this->assertSame(['cmp_id'], array_keys($this->svc()->quote(['tokens' => 1000000, 'scope' => 'company'])['details']));
        $q = $this->svc()->quote(['tokens' => 1000000, 'scope' => 'company', 'cmp_id' => 77]);
        $this->assertSame(['company', 77], [$q['data']['scope'], $q['data']['credit_cmp_id']]);
        $this->assertSame(['workspace', null], [($w = $this->svc()->quote(['tokens' => 1000000]))['data']['scope'], $w['data']['credit_cmp_id']]);
    }

    /** Company credits go to that company's bucket at AI Pulse; workspace credits to the owner's pool. */
    public function testCompanyCreditsAreGrantedToTheirCompany(): void
    {
        $o = $this->order(['scope' => 'company', 'company_name' => 'Acme Pvt Ltd'])['data'];
        $row = $this->db()->table('engage_token_orders')->where('order_ref', $o['order_ref'])->get()->getRowArray();
        $this->assertSame('1,000,000 Aicountly AI tokens — for Acme Pvt Ltd only', $this->svc()->financeRequest($row)['description'], 'the invoice line says whose credits');
        $this->assertSame(self::CMP, $this->svc()->financeRequest($row)['cmp_id'], 'billed to the company the order was placed in');
        $this->svc()->settle($o['order_ref'], ['payment_ref' => 'pay_CMP1']);
        $grant = $this->db()->table('engage_token_grants')->where('origin_ref', $o['order_ref'])->get()->getRowArray();
        $this->assertSame(self::CMP, (int) $grant['cmp_id']);

        $w = $this->order([], 'holder-workspace-1')['data'];
        $this->svc()->settle($w['order_ref'], ['payment_ref' => 'pay_WS1']);
        $this->assertNull($this->db()->table('engage_token_grants')->where('origin_ref', $w['order_ref'])->get()->getRowArray()['cmp_id']);
        $this->assertSame('pending', $this->svc()->findByRef($w['order_ref'])['grant_status'], 'paid, not yet in the wallet');
    }

    /** Cancelling closes the payment page at Finance first; a page paid meanwhile keeps the order. */
    public function testCancellingClosesThePaymentPageAtFinance(): void
    {
        $this->setEnv('FINANCE_API_BASE_URL', 'https://finance.example.test');
        $this->setEnv('ENGAGE_OUTBOUND_FINANCE_S2S_KEY_ID', 'engage-issue-2026-11');
        $this->setEnv('ENGAGE_OUTBOUND_FINANCE_S2S_SECRET', str_repeat('s', 40));
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['success' => true, 'data' => ['status' => 'cancelled']])),
            new Response(409, [], (string) json_encode(['success' => false, 'error' => ['code' => 'already_paid', 'message' => 'Paid.']])),
        ]));
        $stack->push(Middleware::history($history));
        $svc = new TokenOrderService($this->db(), new FinanceLinkClient(new Client(['handler' => $stack])));

        $o = $svc->create(['tokens' => 1000000], self::HOLDER, self::CMP, $this->payer())['data'];
        $svc->linkRaised((int) $o['id'], ['link_id' => 'fin_pl_CANCEL1', 'url' => 'https://pay.example/c1']);
        $this->assertSame('cancelled', $svc->cancel($o['order_ref'], 'Changed my mind')['data']['status']);
        $this->assertStringEndsWith('/api/v1/inbound/engage/payment-links/fin_pl_CANCEL1/cancel', (string) $history[0]['request']->getUri());

        $p = $svc->create(['tokens' => 1000000, 'scope' => 'company'], self::HOLDER, self::CMP, $this->payer())['data'];
        $svc->linkRaised((int) $p['id'], ['link_id' => 'fin_pl_CANCEL2', 'url' => 'https://pay.example/c2']);
        $r = $svc->cancel($p['order_ref'], 'Too late');
        $this->assertSame([409, 'already_paid'], [$r['status'], $r['code']]);
        $this->assertSame('awaiting_payment', $svc->findByRef($p['order_ref'])['status'], 'the payment is on its way: nothing cancelled');
    }

    /** "₹500 off" and "30% off, minimum ₹500, at most ₹1,000" on AI credits — the owner's launch coupons. */
    public function testTheLaunchCouponsOnCredits(): void
    {
        $this->activeCoupon('CREDIT500', 50000, ['product_codes' => ['ai_credits'], 'offer_codes' => [], 'licence_type_codes' => []]);
        $q = $this->svc()->quote(['tokens' => 1000000, 'coupon_code' => 'credit-500']);
        $this->assertSame(200, $q['status'], 'a coupon for AI credits, typed with a hyphen and in lower case');
        $this->assertSame([100000, 50000, 50000, 9000, 59000], [$q['data']['list_paise'], $q['data']['discount_paise'], $q['data']['net_paise'], $q['data']['gst_paise'], $q['data']['total_paise']]);
        $equal = $this->svc()->quote(['tokens' => 500000, 'coupon_code' => 'CREDIT500']);
        $this->assertSame([422, 'below_min_order'], [$equal['status'], $equal['code']], '₹500 off a ₹500 purchase is a giveaway, not a discount');

        $this->activeCoupon('THIRTY', 0, null, ['discount_type' => 'percent', 'flat_paise' => null, 'percent_bp' => 3000,
            'min_order_paise' => 50000, 'max_discount_paise' => 100000]);
        $this->assertSame('below_min_order', $this->svc()->quote(['tokens' => 400000, 'coupon_code' => 'THIRTY'])['code'], 'minimum ₹500');
        $mid = $this->svc()->quote(['tokens' => 1000000, 'coupon_code' => 'THIRTY'])['data'];
        $this->assertSame([30000, 70000, 12600, 82600], [$mid['discount_paise'], $mid['net_paise'], $mid['gst_paise'], $mid['total_paise']], '30% of ₹1,000');
        $big = $this->svc()->quote(['tokens' => 5000000, 'coupon_code' => 'THIRTY'])['data'];
        $this->assertSame([100000, 400000, 72000, 472000], [$big['discount_paise'], $big['net_paise'], $big['gst_paise'], $big['total_paise']], 'capped at ₹1,000');
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
        $db->table('engage_coupons')->insert(array_merge([
            'coupon_ref'    => $ref,
            'code_kind'     => $codeKind,
            'label'         => $code,
            'discount_type' => 'flat',
            'flat_paise'    => $flatPaise,
            'applies_to'    => json_encode($appliesTo ?? ['product_codes' => [], 'offer_codes' => [], 'licence_type_codes' => []]),
            'status'        => 'active',
            'approved_at'   => date('Y-m-d H:i:sP'),
        ], $limits));
        $db->table('engage_coupon_codes')->insert([
            'coupon_id' => (int) $db->insertID(), 'code' => $code, 'kind' => $codeKind, 'status' => 'available',
        ]);
    }
}
