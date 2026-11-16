<?php

namespace Tests\Feature;

use Tests\Support\SellerTestCase;

/**
 * Staff add a seller directly, without a request to join (docs/SELLING_LICENCES.md §7): POST v1/sellers.
 *
 * @internal
 */
final class SellerDirectCreateTest extends SellerTestCase
{
    private function body(array $over = []): array
    {
        return array_merge([
            'name' => 'Meera Shah', 'email' => 'Meera@Example.com', 'mobile' => '+919812345678', 'business_name' => 'Shah Associates',
            'entity_type' => 'proprietorship', 'city' => 'Surat', 'state' => 'Gujarat', 'note' => 'Met at the Surat CA meet; signed up on the spot.',
        ], $over);
    }

    public function testSellerManagerAddsASellerWhoCanActivate(): void
    {
        $label = $this->publishAgreement();
        $mgr = $this->staff(['seller_manager'], 'seller-mgr@aicountly.org');
        $r = $this->asStaff($mgr, 'post', 'v1/sellers', $this->body());
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $d = $this->json($r)['data'];
        $this->assertMatchesRegularExpression('/\ASLR-\d{6}\z/', $d['seller']['seller_code']);
        $this->assertSame('onboarding', $d['seller']['status']);
        $this->assertSame('meera@example.com', $d['seller']['email']);
        $this->assertTrue($d['seller']['added_directly']);
        $this->assertNull($d['seller']['application_id']);
        $this->assertFalse($d['activation']['email_sent']);
        $this->assertStringContainsString('/activate?token=', (string) $d['activation']['link'], 'no mailer: the link is shown once');
        $this->assertSame([], $d['warnings']);
        $this->assertSame(1, $this->db()->table('engage_seller_referral_codes')->where('code', $d['seller']['seller_code'])->countAllResults());
        $this->assertSame(1, $this->db()->table('engage_audit_logs')->where('event', 'seller.created_directly')->countAllResults());

        // The seller finishes onboarding exactly like an approved applicant.
        $token = substr((string) $d['activation']['link'], strpos((string) $d['activation']['link'], 'token=') + 6);
        $this->assertSame(200, $this->code($this->portal('post', 'auth/activation', ['token' => $token, 'password' => 'Str0ngPassword'])));
        $session = $this->login('meera@example.com');
        $this->assertNotNull($session);
        $this->assertSame(201, $this->code($this->portal('post', 'agreement/acceptances', ['version' => $label, 'accepted' => true], $session)));
        $this->assertSame('kyc_pending', $this->db()->table('engage_sellers')->where('id', $d['seller']['id'])->get()->getRow()->status);
    }

    public function testDuplicatesValidationAndRights(): void
    {
        $mgr = $this->staff(['seller_manager'], 'seller-mgr@aicountly.org');
        $this->assertSame(201, $this->code($this->asStaff($mgr, 'post', 'v1/sellers', $this->body())));

        $r = $this->asStaff($mgr, 'post', 'v1/sellers', $this->body(['email' => 'meera@example.com']));
        $this->assertSame(409, $this->code($r));
        $this->assertSame('seller_exists', $this->json($r)['code']);

        $this->apply('ravi@example.com');
        $r = $this->asStaff($mgr, 'post', 'v1/sellers', $this->body(['email' => 'RAVI@example.com']));
        $this->assertSame(409, $this->code($r));
        $this->assertSame('application_open', $this->json($r)['code']);
        $this->assertMatchesRegularExpression('/\ASAP-\d{6}\z/', $this->json($r)['details']['application_code']);

        $r = $this->asStaff($mgr, 'post', 'v1/sellers', $this->body(['email' => 'not-an-email', 'mobile' => '12', 'entity_type' => 'trust']));
        $this->assertSame(422, $this->code($r));
        $this->assertEqualsCanonicalizing(['email', 'mobile', 'entity_type'], array_keys($this->json($r)['details']));

        foreach (['member', 'kyc_reviewer', 'sales_manager'] as $role) {
            $t = $this->staff([$role], "{$role}@aicountly.org");
            $this->assertSame(403, $this->code($this->asStaff($t, 'post', 'v1/sellers', $this->body(['email' => "{$role}-x@example.com"]))), $role);
        }
    }
}
