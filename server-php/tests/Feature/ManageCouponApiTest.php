<?php

namespace Tests\Feature;

use Tests\Support\CouponTestCase;

/**
 * Manage-facing coupon routes (contract §4.1, docs/COUPONS.md §7): key scope `quote` (POST only on the two paths),
 * existing read / issue behaviour unchanged, generic reason codes, per-holder rate limits, upgrade requests
 * (subscription inquiry + reserved redemption), idempotency and audit.
 */
final class ManageCouponApiTest extends CouponTestCase
{
    private const HOLDER = '6f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37';

    public function testQuoteScopeIsConfinedToItsTwoPathsAndOtherKeysAreUnchanged(): void
    {
        $this->configureManageAll();
        $this->offers();
        $this->activeCoupon();
        $body = ['holder_uuid' => self::HOLDER, 'offer_code' => 'books-pro', 'code' => 'DIWALI26'];

        $this->assertSame(200, $this->code($this->quoteCall('manage/coupons/preview', $body)));
        // quote key: GET anywhere, POST elsewhere → 403
        $r = $this->s2s('GET', 'manage/catalogue', 'cmp_id=42', [], '', self::QUOTE_KEY_ID, self::QUOTE_SECRET);
        $this->assertSame(403, $this->code($r));
        $this->assertSame('route_not_allowed_for_key', $this->json($r)['error']['code']);
        $this->assertSame(403, $this->code($this->quoteCall('manage/companies/42/licences', [])));
        // read key: unchanged (GET ok; POST to the coupon routes 403)
        $this->assertSame(200, $this->code($this->s2s('GET', 'manage/catalogue', 'cmp_id=42')));
        $this->assertSame(403, $this->code($this->quoteCall('manage/coupons/preview', $body, [], self::READ_KEY_ID, self::READ_SECRET)));
        // issue key: may POST under manage (unchanged: unknown paths 404), but not these coupon routes
        $iss = $this->quoteCall('manage/coupons/preview', $body, [], self::ISSUE_KEY_ID, self::ISSUE_SECRET);
        $this->assertSame(403, $this->code($iss));
        $this->assertSame('route_not_allowed_for_key', $this->json($iss)['error']['code']);
        $this->assertSame(404, $this->code($this->quoteCall('manage/something-else', [], [], self::ISSUE_KEY_ID, self::ISSUE_SECRET)));
        // signature still required
        $this->assertSame(401, $this->code($this->quoteCall('manage/coupons/preview', $body, ['X-AIC-Signature' => 'v1=' . str_repeat('0', 64)])));
        // unconfigured quote key id → unknown_key
        $this->setEnv('ENGAGE_INBOUND_MANAGE_SCOPE_MANAGE_QUOTE_2026_10', 'bogus');
        $this->assertSame(401, $this->code($this->quoteCall('manage/coupons/preview', $body)));
    }

    public function testPreviewAnswersGenericReasonsWithoutReserving(): void
    {
        $this->configureManageAll();
        $this->offers();
        $this->activeCoupon(['applies_to' => ['offer_codes' => ['books-annual']], 'max_redemptions_per_holder' => 1]);

        $ok = $this->quoteCall('manage/coupons/preview', ['holder_uuid' => self::HOLDER, 'offer_code' => 'books-annual', 'code' => 'diwali 26']);
        $this->assertSame(200, $this->code($ok));
        $j = $this->json($ok);
        $this->assertTrue($j['success']);
        $this->assertStringContainsString('no-store', $ok->response()->getHeaderLine('Cache-Control'));
        $d = $j['data'];
        $this->assertTrue($d['valid']);
        $this->assertNull($d['reason_code']);
        $this->assertSame([1180000, 236000, 944000, '18', 144000, 944000], [$d['list_paise'], $d['discount_paise'], $d['net_paise'], $d['gst_rate'], $d['gst_paise'], $d['total_paise']]);
        $this->assertSame(['code' => 'DIWA-LI26', 'label' => 'Diwali 20 %'], $d['coupon']);
        $this->assertSame(0, $this->db()->table('engage_coupon_redemptions')->countAllResults(), 'preview never reserves');

        $cases = [
            ['NOPE9999', 'books-annual', 'invalid_or_expired'],
            ['DIWALI26', 'books-pro', 'not_applicable'],
            ['DIWALI26', 'no-such-offer', 'not_applicable'],
        ];
        foreach ($cases as [$code, $offer, $reason]) {
            $r = $this->json($this->quoteCall('manage/coupons/preview', ['holder_uuid' => self::HOLDER, 'offer_code' => $offer, 'code' => $code]))['data'];
            $this->assertFalse($r['valid']);
            $this->assertSame($reason, $r['reason_code'], "{$code} / {$offer}");
            $this->assertSame(0, $r['discount_paise']);
            $this->assertNull($r['coupon']);
        }
        // limit_reached once the holder has used it
        $this->quote(['holder_uuid' => self::HOLDER, 'offer_code' => 'books-annual', 'coupon_code' => 'DIWALI26']);
        $lim = $this->json($this->quoteCall('manage/coupons/preview', ['holder_uuid' => self::HOLDER, 'offer_code' => 'books-annual', 'code' => 'DIWALI26']))['data'];
        $this->assertSame('limit_reached', $lim['reason_code']);
        $this->assertSame(1180000, $lim['list_paise'], 'the list price is still shown');

        $bad = $this->quoteCall('manage/coupons/preview', ['offer_code' => 'books-annual', 'code' => 'X']);
        $this->assertSame(400, $this->code($bad));
        $this->assertSame('holder_uuid', $this->json($bad)['error']['details']['field']);
        $notObject = $this->s2s('POST', 'manage/coupons/preview', '', [], '[1,2]', self::QUOTE_KEY_ID, self::QUOTE_SECRET);
        $this->assertSame(400, $this->code($notObject));

        $audit = $this->db()->table('engage_audit_logs')->where('event', 'manage.coupon.preview')->get()->getResultArray();
        $this->assertCount(5, $audit);
        $this->assertSame('manage:uuid-actor-1', $audit[0]['actor_email']);
        $this->assertSame('service:manage', $audit[0]['actor_role']);
        foreach ($audit as $a) {
            $this->assertStringNotContainsString('DIWALI26', (string) $a['metadata'], 'never the raw code');
        }
    }

    public function testPreviewIsRateLimitedPerHolder(): void
    {
        $this->configureManageAll();
        $this->setEnv('ENGAGE_COUPON_PREVIEW_LIMIT', '3');
        $this->offers();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->code($this->quoteCall('manage/coupons/preview', ['holder_uuid' => self::HOLDER, 'offer_code' => 'books-pro', 'code' => 'GUESS00' . $i])));
        }
        $r = $this->quoteCall('manage/coupons/preview', ['holder_uuid' => self::HOLDER, 'offer_code' => 'books-pro', 'code' => 'GUESS009']);
        $this->assertSame(429, $this->code($r));
        $this->assertSame('rate_limited', $this->json($r)['error']['code']);
        $this->assertTrue($this->json($r)['error']['retryable']);
        $this->assertGreaterThan(0, (int) $r->response()->getHeaderLine('Retry-After'));
        // another holder is not affected
        $this->assertSame(200, $this->code($this->quoteCall('manage/coupons/preview', ['holder_uuid' => '0b2f9d1e-1111-4e8a-9c61-5d2e8f0a1b38', 'offer_code' => 'books-pro', 'code' => 'GUESS001'])));
        // the window passes
        $this->db()->query("UPDATE engage_coupon_attempts SET created_at = NOW() - INTERVAL '11 minutes'");
        $this->assertSame(200, $this->code($this->quoteCall('manage/coupons/preview', ['holder_uuid' => self::HOLDER, 'offer_code' => 'books-pro', 'code' => 'GUESS010'])));
    }

    public function testUpgradeRequestCreatesAnInquiryAndReservesTheCoupon(): void
    {
        $this->configureManageAll();
        $this->offers();
        $c = $this->activeCoupon();
        $this->db()->table('engage_leads')->insert(['name' => 'Asha', 'email' => 'asha@example.com', 'lead_code' => 'LD-UPG-1']);
        $leadId = (int) $this->db()->insertID();

        $body = ['holder_uuid' => self::HOLDER, 'cmp_id' => 42, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26',
            'contact' => ['name' => 'Asha', 'email' => 'Asha@Example.com', 'phone' => '+91 98200 00000'], 'request_id' => 'req-0001-abcdef'];
        $r = $this->quoteCall('manage/upgrade-requests', $body);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $d = $this->json($r)['data'];
        $this->assertMatchesRegularExpression('/\AUPG-\d{6}\z/', $d['request_ref']);
        $this->assertSame([1000000, 200000, 800000, 144000, 944000], [$d['quote']['list_paise'], $d['quote']['discount_paise'], $d['quote']['net_paise'], $d['quote']['gst_paise'], $d['quote']['total_paise']]);
        $this->assertSame('DIWA-LI26', $d['quote']['coupon']['code']);

        $inq = $this->db()->table('engage_subscription_inquiries')->where('request_ref', $d['request_ref'])->get()->getRowArray();
        $this->assertSame('manage', $inq['source']);
        $this->assertSame(self::HOLDER, $inq['holder_uuid']);
        $this->assertSame('42', (string) $inq['cmp_id']);
        $this->assertSame($d['quote']['quote_ref'], $inq['quote_ref']);
        $this->assertSame('asha@example.com', $inq['contact_email']);
        $this->assertSame($leadId, (int) $inq['lead_id'], 'linked to the one lead with that email');
        $this->assertSame('9440.00', $inq['target_amount']);
        $red = $this->db()->table('engage_coupon_redemptions')->where('quote_ref', $d['quote']['quote_ref'])->get()->getRowArray();
        $this->assertSame('reserved', $red['state']);
        $this->assertSame('manage', $red['source']);

        // Retried by Manage: same answer, nothing new.
        $again = $this->quoteCall('manage/upgrade-requests', $body);
        $this->assertSame(200, $this->code($again));
        $this->assertSame($d['request_ref'], $this->json($again)['data']['request_ref']);
        $this->assertSame(1, $this->db()->table('engage_subscription_inquiries')->countAllResults());
        $this->assertSame(1, $this->db()->table('engage_coupon_redemptions')->countAllResults());

        // A code that cannot be used: the request still goes to staff, quote null + generic reason.
        $r2 = $this->json($this->quoteCall('manage/upgrade-requests', ['request_id' => 'req-0002-abcdef', 'coupon_code' => 'WRONG123'] + $body))['data'];
        $this->assertNull($r2['quote']);
        $this->assertSame('invalid_or_expired', $r2['coupon_reason_code']);
        // No code at all
        $r3 = $this->json($this->quoteCall('manage/upgrade-requests', ['request_id' => 'req-0003-abcdef', 'coupon_code' => null] + $body))['data'];
        $this->assertNull($r3['quote']);
        $this->assertArrayNotHasKey('coupon_reason_code', $r3);
        $this->assertSame(3, $this->db()->table('engage_subscription_inquiries')->countAllResults());

        // Validation and an offer not on sale
        $this->assertSame('contact.email', $this->json($this->quoteCall('manage/upgrade-requests', ['contact' => ['name' => 'A', 'email' => 'x']] + $body))['error']['details']['field']);
        $this->assertSame(422, $this->code($this->quoteCall('manage/upgrade-requests', ['request_id' => 'req-0004-abcdef', 'offer_code' => 'retired-offer'] + $body)));

        $events = array_column($this->db()->table('engage_audit_logs')->where('event', 'manage.upgrade_request.created')->get()->getResultArray(), 'actor_email');
        $this->assertSame(['manage:uuid-actor-1', 'manage:uuid-actor-1', 'manage:uuid-actor-1'], $events);
        // Staff see the inquiry in the existing screen.
        $list = $this->json($this->asStaff($this->maker(), 'get', 'v1/subscription-inquiries'))['data']['items'];
        $this->assertCount(3, $list);
        $this->assertSame((string) $c['id'], (string) $this->db()->table('engage_coupon_redemptions')->get()->getRow()->coupon_id);
    }

    public function testUpgradeRequestsAreRateLimitedPerHolder(): void
    {
        $this->configureManageAll();
        $this->setEnv('ENGAGE_UPGRADE_REQUEST_LIMIT', '2');
        $this->offers();
        $body = ['holder_uuid' => self::HOLDER, 'offer_code' => 'books-pro', 'contact' => ['name' => 'Asha', 'email' => 'a@example.com']];
        $this->assertSame(201, $this->code($this->quoteCall('manage/upgrade-requests', ['request_id' => 'rate-000001'] + $body)));
        $this->assertSame(201, $this->code($this->quoteCall('manage/upgrade-requests', ['request_id' => 'rate-000002'] + $body)));
        $r = $this->quoteCall('manage/upgrade-requests', ['request_id' => 'rate-000003'] + $body);
        $this->assertSame(429, $this->code($r));
        $this->assertNotSame('', $r->response()->getHeaderLine('Retry-After'));
        // an idempotent retry of an accepted request is still answered
        $this->assertSame(200, $this->code($this->quoteCall('manage/upgrade-requests', ['request_id' => 'rate-000001'] + $body)));
    }
}
