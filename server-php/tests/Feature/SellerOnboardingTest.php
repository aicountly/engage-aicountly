<?php

namespace Tests\Feature;

use App\Models\SettingsModel;
use Tests\Support\SellerTestCase;

/**
 * Seller application → approval → activation → agreement → login / lockout / OTP / reset / suspension,
 * through the seller-portal API exactly as the BFF calls it (seller-aicountly docs/ENGAGE_SELLER_API.md).
 */
final class SellerOnboardingTest extends SellerTestCase
{
    public function testPortalKeyIsRequired(): void
    {
        $r = $this->portal('get', 'health', [], null, ['X-Seller-Portal-Key' => 'wrong-key-wrong-key-wrong-key-wrong-key']);
        $this->assertSame(401, $this->code($r));
        $this->assertSame('portal_key_invalid', $this->json($r)['code']);

        $this->headers = [];
        $r = $this->get('v1/seller-portal/health');
        $this->assertSame(401, $this->code($r));
        $this->assertSame('portal_key_invalid', $this->json($r)['code']);

        $this->assertSame('engage-seller-portal', $this->json($this->portal('get', 'health'))['data']['service']);

        $this->setEnv('SELLER_PORTAL_KEY', 'short');
        $r = $this->portal('get', 'health');
        $this->assertSame(503, $this->code($r));
        $this->assertSame('portal_key_missing', $this->json($r)['code']);
    }

    public function testApplicationCreatesApplicationAndSellerRequestLead(): void
    {
        $r = $this->apply();
        $this->assertSame(201, $this->code($r));
        $first = $this->json($r)['data'];
        $this->assertSame(['application_code' => 'SAP-000001', 'status' => 'requested'], $first);

        $app = $this->db()->table('engage_seller_applications')->get()->getRowArray();
        $this->assertSame('203.0.113.7', $app['ip_address'], 'the BFF-forwarded client IP is recorded');
        $this->assertTrue(json_decode($app['metadata'], true)['consent']['contact']);
        $lead = $this->db()->table('engage_leads')->where('id', $app['lead_id'])->get()->getRowArray();
        $this->assertSame('seller_request', $lead['source_type']);
        $this->assertSame('9876543210', $lead['mobile_normalized']);

        // Same email again (different case): the open application, nothing new stored.
        $r2 = $this->apply('RAVI@example.com');
        $this->assertSame(200, $this->code($r2));
        $this->assertSame($first, $this->json($r2)['data']);
        $this->assertSame(1, $this->db()->table('engage_seller_applications')->countAllResults());
    }

    public function testHoneypotValidationAndPerIpRateLimit(): void
    {
        $r = $this->apply('bot@example.com', ['website' => 'http://spam.example']);
        $this->assertSame(200, $this->code($r));
        $this->assertSame(0, $this->db()->table('engage_seller_applications')->countAllResults());

        $r = $this->apply('not-an-email');
        $this->assertSame(422, $this->code($r));
        $this->assertArrayHasKey('email', $this->json($r)['details']);
        $r = $this->apply('x@example.com', ['consent' => ['contact' => false]]);
        $this->assertArrayHasKey('consent.contact', $this->json($r)['details']);

        $this->apply('c@example.com');
        $this->apply('d@example.com');
        $r = $this->apply('e@example.com');
        $this->assertSame(429, $this->code($r), '5 per visitor IP per hour');
        $this->assertSame('rate_limited', $this->json($r)['code']);
        $r = $this->apply('f@example.com', [], ['X-Seller-Client-IP' => '198.51.100.9']);
        $this->assertSame(201, $this->code($r), 'another visitor IP is not affected');
    }

    public function testApprovalNeedsSellerRoleAndShowsLinkOnceWhenNoMailer(): void
    {
        $this->apply();
        $appId = (int) $this->db()->table('engage_seller_applications')->get()->getRow()->id;
        foreach (['member', 'kyc_reviewer', 'sales_manager'] as $role) {
            $r = $this->asStaff($this->staff([$role], "{$role}@aicountly.org"), 'post', "v1/seller-applications/{$appId}/approve");
            $this->assertSame(403, $this->code($r), "{$role} must not approve");
        }
        $this->assertSame(200, $this->code($this->asStaff($this->staff(['kyc_reviewer'], 'k@aicountly.org'), 'get', 'v1/seller-applications')));

        $s = $this->approvedSeller();
        $this->assertSame('SLR-000001', $s['seller_code']);
        $act = $s['approve']['data']['activation'];
        $this->assertFalse($act['email_sent']);
        $this->assertStringStartsWith('https://seller.aicountly.com/activate?token=', $act['link']);
        $this->assertStringContainsString('No email was sent', $act['message']);
        $this->assertNotEmpty($s['approve']['data']['warnings'], 'no agreement published yet');

        $seller = $this->db()->table('engage_sellers')->where('id', $s['seller_id'])->get()->getRowArray();
        $this->assertSame('onboarding', $seller['status']);
        $this->assertSame('individual', $seller['entity_type']);
        $this->assertNotNull($seller['lead_id']);
        $tok = $this->db()->table('engage_seller_tokens')->where('seller_id', $s['seller_id'])->get()->getRowArray();
        $this->assertSame(hash('sha256', $s['token']), $tok['token_hash'], 'only the hash is stored');
        $this->assertSame('shown_to_staff', $tok['delivery']);
        // Join request from a known seller email: same "submitted" kind of answer, no new row.
        $r = $this->apply();
        $this->assertSame(200, $this->code($r));
        $this->assertSame(1, $this->db()->table('engage_seller_applications')->countAllResults());
    }

    public function testApprovalEmailsTheLinkWhenAMailerIsConfigured(): void
    {
        $this->mailer->fakeChannel = 'console';
        $s = $this->approvedSeller();
        $act = $s['approve']['data']['activation'];
        $this->assertTrue($act['email_sent']);
        $this->assertNull($act['link'], 'an emailed link is not also shown to staff');
        $this->assertSame('seller_activation', $this->mailer->sent[0]['trigger']);

        $this->mailer->succeed = false;
        $r = $this->asStaff($this->staff(['seller_manager'], 'seller-mgr@aicountly.org'), 'post', "v1/sellers/{$s['seller_id']}/resend-activation");
        $this->assertFalse($this->json($r)['data']['activation']['email_sent'], 'Console refused: say so');
        $this->assertNotNull($this->json($r)['data']['activation']['link']);
    }

    public function testRejectNeedsReason(): void
    {
        $this->apply();
        $appId = (int) $this->db()->table('engage_seller_applications')->get()->getRow()->id;
        $mgr = $this->staff(['seller_manager']);
        $this->assertSame(422, $this->code($this->asStaff($mgr, 'post', "v1/seller-applications/{$appId}/reject", ['reason' => ''])));
        $r = $this->asStaff($mgr, 'post', "v1/seller-applications/{$appId}/reject", ['reason' => 'Not in our target market']);
        $this->assertSame('rejected', $this->json($r)['data']['status']);
        $this->assertSame(409, $this->code($this->asStaff($mgr, 'post', "v1/seller-applications/{$appId}/approve")));
    }

    public function testActivationIsSingleUseAndAgreementCompletesIt(): void
    {
        $label = $this->publishAgreement();
        $s = $this->approvedSeller();

        $inspect = $this->portal('post', 'auth/activation/inspect', ['token' => $s['token']]);
        $this->assertSame(200, $this->code($inspect));
        $this->assertSame('r***@example.com', $this->json($inspect)['data']['email_masked']);
        $this->assertSame(410, $this->code($this->portal('post', 'auth/activation/inspect', ['token' => str_repeat('a', 64)])));

        $weak = $this->portal('post', 'auth/activation', ['token' => $s['token'], 'password' => 'short']);
        $this->assertSame(422, $this->code($weak));
        $this->assertSame('weak_password', $this->json($weak)['code']);

        $ok = $this->portal('post', 'auth/activation', ['token' => $s['token'], 'password' => 'Str0ngPassword']);
        $this->assertSame(['activated' => true, 'email_masked' => 'r***@example.com'], $this->json($ok)['data']);
        $again = $this->portal('post', 'auth/activation', ['token' => $s['token'], 'password' => 'Str0ngPassword2']);
        $this->assertSame(410, $this->code($again));
        $this->assertSame('token_invalid', $this->json($again)['code']);

        $session = $this->login();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $session);
        $me = $this->json($this->portal('get', 'me', [], $session))['data']['seller'];
        $this->assertSame('onboarding', $me['status']);
        $this->assertTrue($me['agreement']['acceptance_required']);
        $this->assertSame('https://www.aicountly.com/?ref=SLR-000001', $me['referral']['links']['website']);
        $this->assertArrayNotHasKey('password_hash', $me);

        // Writes are blocked until the agreement is accepted.
        $lead = $this->portal('post', 'leads', ['name' => 'Customer', 'email' => 'c@cust.example', 'consent_confirmed' => true], $session);
        $this->assertSame(403, $this->code($lead));
        $this->assertSame('agreement_required', $this->json($lead)['code']);

        $ag = $this->json($this->portal('get', 'agreement', [], $session))['data'];
        $this->assertSame($label, $ag['agreement']['version']);
        $this->assertFalse($ag['accepted']);
        $this->assertArrayHasKey('body_text', $ag['agreement']);

        $stale = $this->portal('post', 'agreement/acceptances', ['version' => 'old', 'accepted' => true], $session);
        $this->assertSame(409, $this->code($stale));
        $this->assertSame('agreement_version_mismatch', $this->json($stale)['code']);
        $acc = $this->portal('post', 'agreement/acceptances', ['version' => $label, 'accepted' => true], $session);
        $this->assertSame(201, $this->code($acc));
        $this->assertSame('kyc_pending', $this->json($acc)['data']['seller']['status']);
        $this->assertSame('203.0.113.7', $this->db()->table('engage_seller_agreement_acceptances')->get()->getRow()->ip_address);
        $this->assertSame(200, $this->code($this->portal('post', 'agreement/acceptances', ['version' => $label, 'accepted' => true], $session)), 'idempotent');
    }

    public function testNewAgreementVersionRequiresReacceptanceBeforeWrites(): void
    {
        $s = $this->activeSellerSession();
        $this->publishAgreement('2027.1');
        $me = $this->json($this->portal('get', 'me', [], $s['session']))['data']['seller'];
        $this->assertTrue($me['agreement']['acceptance_required']);
        $this->assertSame('kyc_pending', $me['status'], 'status is not changed by a new version');
        $r = $this->portal('post', 'leads', ['name' => 'Cust', 'email' => 'cust@example.org', 'consent_confirmed' => true], $s['session']);
        $this->assertSame('agreement_required', $this->json($r)['code']);
        $this->assertSame(200, $this->code($this->portal('patch', 'me', ['city' => 'Mumbai'], $s['session'])), 'profile stays editable');
    }

    public function testLoginGenericErrorsLockoutAndUnlock(): void
    {
        $s = $this->activeSellerSession();

        $unknown = $this->portal('post', 'auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever1']);
        $this->assertSame(401, $this->code($unknown));
        $this->assertSame('invalid_credentials', $this->json($unknown)['code']);
        $unknownError = $this->json($unknown)['error'];
        $wrong = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'wrongpass1']);
        $this->assertSame($unknownError, $this->json($wrong)['error']);

        for ($i = 0; $i < 4; $i++) {
            $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'wrongpass1']);
        }
        $locked = $this->portal('post', 'auth/login', ['email' => 'RAVI@example.com', 'password' => 'Str0ngPassword']);
        $this->assertSame(423, $this->code($locked));
        $this->assertSame('account_locked', $this->json($locked)['code']);
        $this->assertSame(401, $this->code($this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'wrongpass1'])));

        $this->assertSame(200, $this->code($this->asStaff($this->staff(['seller_manager']), 'post', "v1/sellers/{$s['seller_id']}/unlock")));
        $this->assertNotNull($this->login());
    }

    public function testSuspensionIsReadOnlyAndTerminationEndsAccess(): void
    {
        $s = $this->activeSellerSession();
        $mgr = $this->staff(['seller_manager']);

        $this->assertSame(422, $this->code($this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/suspend", ['reason' => ''])));
        $r = $this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/suspend", ['reason' => 'Complaint under review']);
        $this->assertSame('suspended', $this->json($r)['data']['status']);
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $s['session'])), 'sessions are revoked at suspension');

        $session = $this->login();
        $me = $this->json($this->portal('get', 'me', [], $session))['data']['seller'];
        $this->assertSame('suspended', $me['status']);
        $this->assertSame('Complaint under review', $me['status_reason']);
        $w = $this->portal('post', 'leads', ['name' => 'Cust', 'email' => 'cust@example.org', 'consent_confirmed' => true], $session);
        $this->assertSame(403, $this->code($w));
        $this->assertSame('seller_suspended', $this->json($w)['code']);

        $r = $this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/reactivate", ['reason' => 'Cleared']);
        $this->assertSame('kyc_pending', $this->json($r)['data']['status'], 'back to what KYC / agreement allow');

        $t = $this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/terminate", ['reason' => 'Contract ended']);
        $this->assertSame('terminated', $this->json($t)['data']['status']);
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $session)));
        $l = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']);
        $this->assertSame('seller_terminated', $this->json($l)['code']);
        $this->assertSame(409, $this->code($this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/reactivate", ['reason' => 'x y z'])));
    }

    public function testPasswordResetIsHonestAboutEmail(): void
    {
        $s = $this->activeSellerSession();

        // No mailer: 503 for everyone (nothing could be sent), no token created.
        $r = $this->portal('post', 'auth/password-reset/request', ['email' => 'ravi@example.com']);
        $this->assertSame(503, $this->code($r));
        $this->assertSame(503, $this->code($this->portal('post', 'auth/password-reset/request', ['email' => 'nobody@example.com'])));
        $this->assertSame(0, $this->db()->table('engage_seller_tokens')->where('purpose', 'password_reset')->countAllResults());

        $this->mailer->fakeChannel = 'smtp';
        $known = $this->portal('post', 'auth/password-reset/request', ['email' => 'ravi@example.com']);
        $this->assertSame(202, $this->code($known));
        $knownBody = $this->json($known)['data'];
        $this->assertSame($knownBody, $this->json($this->portal('post', 'auth/password-reset/request', ['email' => 'nobody@example.com']))['data']);
        $url = $this->mailer->sent[0]['vars']['reset_url'];
        $this->assertStringStartsWith('https://seller.aicountly.com/reset-password?token=', $url);
        $token = substr($url, strpos($url, 'token=') + 6);
        $this->assertSame(200, $this->code($this->portal('post', 'auth/password-reset/inspect', ['token' => $token])));
        $this->assertSame(422, $this->code($this->portal('post', 'auth/password-reset', ['token' => $token, 'password' => 'nodigitshere'])));
        $ok = $this->portal('post', 'auth/password-reset', ['token' => $token, 'password' => 'N3wPassword!']);
        $this->assertSame(['reset' => true], $this->json($ok)['data']);
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $s['session'])), 'every session revoked');
        $this->assertSame(410, $this->code($this->portal('post', 'auth/password-reset', ['token' => $token, 'password' => 'N3wPassword!2'])));
        $this->assertNotNull($this->login('ravi@example.com', 'N3wPassword!'));

        // Mailer down for an existing seller: an honest 503, never "sent".
        $this->mailer->succeed = false;
        $this->assertSame(503, $this->code($this->portal('post', 'auth/password-reset/request', ['email' => 'ravi@example.com'])));
    }

    public function testPasswordResetForOnboardingSellerResendsActivation(): void
    {
        $this->approvedSeller();
        $this->mailer->fakeChannel = 'console';
        $this->assertSame(202, $this->code($this->portal('post', 'auth/password-reset/request', ['email' => 'ravi@example.com'])));
        $this->assertSame('seller_activation', $this->mailer->sent[0]['trigger']);
    }

    public function testStaffResetPasswordShowsLinkWhenNoMailer(): void
    {
        $s = $this->activeSellerSession();
        $r = $this->asStaff($this->staff(['seller_manager']), 'post', "v1/sellers/{$s['seller_id']}/reset-password");
        $reset = $this->json($r)['data']['reset'];
        $this->assertFalse($reset['email_sent']);
        $this->assertStringStartsWith('https://seller.aicountly.com/reset-password?token=', $reset['link']);
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $s['session'])));
    }

    public function testEmailOtpStepWhenEnabledAndMailerConfigured(): void
    {
        $this->activeSellerSession();
        (new SettingsModel())->setSetting('seller_login_otp_enabled', true);
        $this->assertNotNull($this->login(), 'setting on but no mailer: no OTP step');

        $this->mailer->fakeChannel = 'console';
        $d = $this->json($this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']))['data'];
        $this->assertTrue($d['otp_required']);
        $this->assertArrayNotHasKey('session_token', $d);
        $this->assertSame('r***@example.com', $d['email_masked']);
        $code = $this->mailer->sent[0]['vars']['code'];

        $bad = $this->portal('post', 'auth/login/otp', ['challenge_token' => $d['challenge_token'], 'code' => $code === '000000' ? '111111' : '000000']);
        $this->assertSame(401, $this->code($bad));
        $this->assertSame('invalid_otp', $this->json($bad)['code']);
        $ok = $this->portal('post', 'auth/login/otp', ['challenge_token' => $d['challenge_token'], 'code' => $code]);
        $this->assertNotEmpty($this->json($ok)['data']['session_token']);
        $used = $this->portal('post', 'auth/login/otp', ['challenge_token' => $d['challenge_token'], 'code' => $code]);
        $this->assertSame(410, $this->code($used));
        $this->assertSame('challenge_expired', $this->json($used)['code']);

        $this->mailer->succeed = false;
        $r = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']);
        $this->assertSame(503, $this->code($r), 'fails closed rather than skipping the code');
    }

    public function testSessionIdleTimeoutAndIdempotentLogout(): void
    {
        $s = $this->activeSellerSession();
        $this->db()->query("UPDATE engage_seller_sessions SET last_seen_at = NOW() - INTERVAL '9 hours'");
        $r = $this->portal('get', 'me', [], $s['session']);
        $this->assertSame(401, $this->code($r));
        $this->assertSame('session_invalid', $this->json($r)['code']);

        $session = $this->login();
        $this->assertSame(['revoked' => true], $this->json($this->portal('post', 'auth/logout', [], $session))['data']);
        $this->assertSame(200, $this->code($this->portal('post', 'auth/logout', [], $session)), 'idempotent');
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $session)));
    }

    public function testChangePasswordRotatesEverySession(): void
    {
        $s = $this->activeSellerSession();
        $bad = $this->portal('post', 'me/password', ['current_password' => 'nope', 'new_password' => 'An0therPassword'], $s['session']);
        $this->assertSame('invalid_current_password', $this->json($bad)['code']);
        $ok = $this->json($this->portal('post', 'me/password', ['current_password' => 'Str0ngPassword', 'new_password' => 'An0therPassword'], $s['session']))['data'];
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $ok['session_token']);
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $s['session'])));
        $this->assertSame(200, $this->code($this->portal('get', 'me', [], $ok['session_token'])));
    }

    public function testProfilePatch(): void
    {
        $s = $this->activeSellerSession();
        $other = $this->activeSellerSession('meena@example.com');
        $this->db()->table('engage_sellers')->where('id', $other['seller_id'])->update(['mobile' => '+919999988888']);
        $r = $this->portal('patch', 'me', ['mobile' => '09999988888'], $s['session']);
        $this->assertSame(422, $this->code($r));
        $this->assertArrayHasKey('mobile', $this->json($r)['details']);
        $r = $this->portal('patch', 'me', ['business_name' => null, 'name' => 'Ravi K', 'city' => 'Nashik'], $s['session']);
        $seller = $this->json($r)['data']['seller'];
        $this->assertNull($seller['business_name']);
        $this->assertSame('Ravi K', $seller['name']);
        $this->assertSame('Nashik', $seller['city']);
    }

    public function testIdempotencyKeyReplaysWrites(): void
    {
        $s = $this->activeSellerSession();
        $body = ['name' => 'Meena Shah', 'company' => 'Shah Traders', 'email' => 'meena@shah.example', 'consent_confirmed' => true];
        $h = ['Idempotency-Key' => 'abc123def456ghi789'];
        $a = $this->portal('post', 'leads', $body, $s['session'], $h);
        $this->assertSame(201, $this->code($a));
        $first = $this->json($a)['data'];
        $b = $this->portal('post', 'leads', $body, $s['session'], $h);
        $this->assertSame(201, $this->code($b));
        $this->assertSame($first, $this->json($b)['data']);
        $this->assertSame('true', $b->response()->getHeaderLine('Idempotent-Replayed'));
        $this->assertSame(1, $this->db()->table('engage_leads')->where('source_type', 'seller')->countAllResults());
        $c = $this->portal('post', 'leads', ['name' => 'Other'] + $body, $s['session'], $h);
        $this->assertSame(409, $this->code($c));
    }

    public function testPartnerMasterIsAdministratorOnly(): void
    {
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['member']), 'get', 'v1/partners')));
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['seller_manager'], 'sm@aicountly.org'), 'post', 'v1/partners/1/unlock')));
    }

    public function testPayoutsAreHonestlyNotEnabled(): void
    {
        $s = $this->activeSellerSession();
        $r = $this->json($this->portal('get', 'payouts', [], $s['session']))['data'];
        $this->assertFalse($r['enabled']);
        $this->assertSame('payouts not enabled yet', $r['status']);
        $this->assertSame([], $r['items']);
        $this->assertSame(0, $r['total']);
        $this->assertSame(404, $this->code($this->portal('get', 'payouts/PO-1/statement', [], $s['session'])));
        $d = $this->json($this->portal('get', 'dashboard', [], $s['session']))['data'];
        $this->assertNull($d['next_payout_date']);
        $this->assertFalse($d['commission_plan']['published']);
        $this->assertSame('500.00', $d['payout_threshold']);
    }
}
