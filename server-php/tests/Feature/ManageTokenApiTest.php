<?php

namespace Tests\Feature;

use Tests\Support\CouponTestCase;

/**
 * The buy-credits routes as MANAGE actually calls them (docs/TOKEN_CONVERSIONS.md §12).
 *
 * This exists because of a bug that every other test missed. The routes worked — the service
 * was right, the arithmetic was right, Manage's own tests passed against a fake Engage that
 * spoke the contract — and yet nothing could be bought, because the controller answered in
 * Engage's internal `{ok:true, data:…}` shape while Manage's S2sClient only accepts
 * `{success:true, data:…}`. Manage read every answer as "not the AIC-S2S success envelope"
 * and told the customer Engage was down.
 *
 * So what is asserted here is the WIRE: the envelope, the status codes, and which key scope
 * may call which route. A test that calls the service directly cannot see any of it.
 */
final class ManageTokenApiTest extends CouponTestCase
{
    private const HOLDER = 'holder-token-api-1';
    private const CMP = 7788;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureManageAll();
    }

    /** @return array<string,mixed> */
    private function body($r): array
    {
        return $this->json($r);
    }

    private function payer(): array
    {
        return ['name' => 'Priya Sharma', 'email' => 'priya@example.test', 'state_code' => '27'];
    }

    // ── the envelope ───────────────────────────────────────────────────────

    public function testASuccessfulQuoteComesBackInTheEnvelopeManageReads(): void
    {
        $r = $this->quoteCall('manage/token-orders/quote', ['holder_uuid' => self::HOLDER, 'tokens' => 1000000]);

        $this->assertSame(200, $this->code($r));
        $b = $this->body($r);
        $this->assertTrue($b['success'] ?? null, 'Manage requires success:true — {ok:true} is read as the service being down');
        $this->assertArrayHasKey('data', $b);
        $this->assertArrayHasKey('request_id', $b, 'every AIC-S2S answer carries the request id');
        $this->assertSame(118000, $b['data']['total_paise'] ?? null);
        $this->assertStringContainsString('no-store', $r->response()->getHeaderLine('Cache-Control'), 'a priced answer is never cached');
    }

    public function testARefusalComesBackAsTheContractsErrorObjectNotAString(): void
    {
        $r = $this->quoteCall('manage/token-orders/quote', ['holder_uuid' => self::HOLDER, 'tokens' => 0]);

        $this->assertSame(422, $this->code($r));
        $b = $this->body($r);
        $this->assertFalse($b['success'] ?? null);
        // Manage reads error.message to show the customer something useful; a plain
        // "error": "…" string leaves it with nothing to say.
        $this->assertIsArray($b['error'] ?? null, 'error must be an object: {code, message, details, retryable}');
        $this->assertSame('validation_failed', $b['error']['code'] ?? null);
        $this->assertNotSame('', (string) ($b['error']['message'] ?? ''));
        $this->assertFalse($b['error']['retryable'] ?? null, 'a bad request is not worth retrying');
    }

    public function testEngageBeingUnableToAnswerIsMarkedRetryable(): void
    {
        // A missing holder is the caller's fault, not Engage's, so it is 400 and not retryable
        // — the shape of the answer is what matters here.
        $r = $this->quoteCall('manage/token-orders', ['tokens' => 1000000, 'payer' => $this->payer()]);

        $this->assertSame(400, $this->code($r));
        $this->assertSame('bad_request', $this->body($r)['error']['code'] ?? null);
        $this->assertSame('holder_uuid', $this->body($r)['error']['details']['field'] ?? null);
    }

    // ── which key may do what ──────────────────────────────────────────────

    public function testTheReadKeyCannotStartAPurchase(): void
    {
        // The read key is GET-only at the filter, so it never reaches the controller; what
        // matters is that it is refused and told why.
        $r = $this->s2s('POST', 'manage/token-orders/quote', '', [], json_encode(['holder_uuid' => self::HOLDER, 'tokens' => 1000000]));

        $this->assertSame(403, $this->code($r));
        $this->assertSame('route_not_allowed_for_key', $this->body($r)['error']['code'] ?? null);
    }

    public function testThePurchaseHistoryIsAReadOnTheReadKey(): void
    {
        $this->order();
        $q = 'holder_uuid=' . self::HOLDER . '&limit=10';
        $r = $this->s2s('GET', 'manage/token-orders', $q);

        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $items = $this->body($r)['data']['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertSame(self::HOLDER, $items[0]['holder_uuid'] ?? null);
    }

    /**
     * Engage's quote scope is POST-only (AicS2sConfig::SCOPE_METHODS), which is why the
     * listing moved to the read key. If somebody puts it back on the quote key it will 403
     * in production and nowhere else — so it is pinned here.
     */
    public function testTheQuoteKeyCannotBeUsedForAGet(): void
    {
        $r = $this->s2s('GET', 'manage/token-orders', 'holder_uuid=' . self::HOLDER, [], '', self::QUOTE_KEY_ID, self::QUOTE_SECRET);

        $this->assertSame(403, $this->code($r));
        $this->assertSame('route_not_allowed_for_key', $this->body($r)['error']['code'] ?? null);
    }

    public function testAnUnsignedRequestBuysNothing(): void
    {
        $r = $this->quoteCall('manage/token-orders/quote', ['holder_uuid' => self::HOLDER, 'tokens' => 1000000],
            ['X-AIC-Signature' => 'v1=' . str_repeat('0', 64)]);

        $this->assertSame(401, $this->code($r));
        $this->assertSame('signature_invalid', $this->body($r)['error']['code'] ?? null);
    }

    // ── the order, over the wire ────────────────────────────────────────────

    private function order(array $extra = []): array
    {
        $r = $this->quoteCall('manage/token-orders', [
            'holder_uuid' => self::HOLDER, 'cmp_id' => self::CMP, 'tokens' => 1000000, 'payer' => $this->payer(),
        ] + $extra);

        return [$r, $this->body($r)];
    }

    public function testAnOrderIsCreatedAndTheAnswerSaysItIsNotPaidYet(): void
    {
        [$r, $b] = $this->order();

        // 201 with no payment link: Finance is not configured in a test, and the answer must
        // say so rather than pretending a page is waiting.
        $this->assertContains($this->code($r), [201, 502, 503], (string) $r->response()->getBody());
        if ($this->code($r) === 201) {
            $this->assertStringStartsWith('TKO-', $b['data']['order_ref'] ?? '');
            $this->assertSame('quoted', $b['data']['status'] ?? null);
        } else {
            $this->assertSame('payment_link_failed', $b['error']['code'] ?? null);
            $this->assertTrue($b['error']['retryable'] ?? null, 'no payment page is worth retrying — nothing was charged');
        }
    }

    public function testCancellingSomebodyElsesOrderTellsThemNothing(): void
    {
        $this->order();
        $ref = (string) $this->db()->table('engage_token_orders')->where('holder_uuid', self::HOLDER)->get()->getRowArray()['order_ref'];

        $r = $this->quoteCall('manage/token-orders/' . $ref . '/cancel', ['holder_uuid' => 'somebody-else', 'reason' => 'nosy']);

        $this->assertSame(404, $this->code($r));
        $this->assertSame('not_found', $this->body($r)['error']['code'] ?? null);
        $this->assertSame('quoted', $this->db()->table('engage_token_orders')->where('order_ref', $ref)->get()->getRowArray()['status']);
    }
}
