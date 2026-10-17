<?php

namespace Tests\Feature;

use App\Models\SettingsModel;
use Tests\Support\SellerTestCase;

/**
 * Seller application → approval → activation → login / lockout / OTP / reset / suspension,
 * through the seller-portal API exactly as the BFF calls it.
 */
final class SellerOnboardingTest extends SellerTestCase
{
    public function testPortalKeyIsRequired(): void
    {
        $r = $this->withHeaders(['X-Seller-Portal-Key' => 'wrong-key-wrong-key-wrong-key-wrong-key'])->withBodyFormat('json')->post('v1/seller-portal/auth/login', ['email' => 'a@b.co', 'password' => 'x']);
        $this->assertSame(401, $this->code($r));
        $this->assertSame('portal_key_invalid', $this->json($r)['code']);

        $this->headers = [];
        $r = $this->withBodyFormat('json')->post('v1/seller-portal/auth/login', ['email' => 'a@b.co', 'password' => 'x']);
        $this->assertSame(401, $this->code($r));
        $this->assertSame('portal_key_missing', $this->json($r)['code']);

        $this->setEnv('SELLER_PORTAL_KEY', 'short');
        $r = $this->portal('post', 'auth/login', ['email' => 'a@b.co', 'password' => 'x']);
        $this->assertSame(503, $this->code($r));
    }

    public function testApplicationCreatesApplicationAndSellerRequestLead(): void
    {
        $r = $this->apply();
        $this->assertSame(201, $this->code($r));
        $first = $this->json($r)['data'];
        $this->assertTrue($first['received']);

        $app = $this->db()->table('engage_seller_applications')->get()->getRowArray();
        $this->assertSame('requested', $app['status']);
        $this->assertSame('203.0.113.7', $app['ip_address'], 'the BFF-forwarded client IP is recorded');
        $lead = $this->db()->table('engage_leads')->where('id', $app['lead_id'])->get()->getRowArray();
        $this->assertSame('seller_request', $lead['source_type']);
        $this->assertSame('9876543210', $lead['mobile_normalized']);

        // Same email again (different case): same public answer, nothing new stored.
        $r2 = $this->apply('RAVI@example.com');
        $this->assertSame(201, $this->code($r2));
        $this->assertSame($first, $this->json($r2)['data']);
        $this->assertSame(1, $this->db()->table('engage_seller_applications')->countAllResults());
    }

    public function testHoneypotAndValidationAndRateLimit(): void
    {
        $r = $this->apply('bot@example.com', ['website' => 'http://spam.example']);
        $this->assertSame(201, $this->code($r));
        $this->assertSame(0, $this->db()->table('engage_seller_applications')->countAllResults());

        $r = $this->apply('not-an-email');
        $this->assertSame(422, $this->code($r));
        $this->assertArrayHasKey('email', $this->json($r)['details']);

        // 5 per visitor IP per hour (2 used above).
        $this->apply('c@example.com');
        $this->apply('d@example.com');
        $this->apply('g@example.com');
        $r = $this->apply('e@example.com');
        $this->assertSame(429, $this->code($r));
        $this->assertSame('rate_limited', $this->json($r)['code']);
        // Another visitor IP is not affected.
        $r = $this->portal('post', 'applications', ['name' => 'Other', 'email' => 'f@example.com', 'mobile' => '9876500000'], null, ['X-Seller-Client-IP' => '198.51.100.9']);
        $this->assertSame(201, $this->code($r));
    }

    public function testApprovalNeedsSellerRoleAndShowsLinkOnceWhenNoMailer(): void
    {
        $this->apply();
        $appId = (int) $this->db()->table('engage_seller_applications')->get()->getRow()->id;

        foreach (['member', 'kyc_reviewer', 'sales_manager'] as $role) {
            $r = $this->asStaff($this->staff([$role], "{$role}@aicountly.org"), 'post', "v1/seller-applications/{$appId}/approve");
            $this->assertSame(403, $this->code($r), "{$role} must not approve");
        }

        $s = $this->approvedSeller();
        $this->assertSame('SLR-000001', $s['seller_code']);
        $act = $s['approve']['data']['activation'];
        $this->assertFalse($act['email_sent']);
        $this->assertStringStartsWith('https://seller.aicountly.com/activate?token=', $act['link']);
        $this->assertStringContainsString('No email was sent', $act['message']);
        $this->assertNotEmpty($s['approve']['data']['warnings'], 'no agreement published yet');

        $seller = $this->db()->table('engage_sellers')->where('id', $s['seller_id'])->get()->getRowArray();
        $this->assertSame('onboarding', $seller['status']);
        $this->assertNotNull($seller['lead_id']);
        $tok = $this->db()->table('engage_seller_tokens')->where('seller_id', $s['seller_id'])->get()->getRowArray();
        $this->assertSame(hash('sha256', $s['token']), $tok['token_hash'], 'only the hash is stored');
        $this->assertSame('shown_to_staff', $tok['delivery']);
        $this->assertSame('approved', $this->db()->table('engage_seller_applications')->where('id', $appId)->get()->getRow()->status);
    }

    public function testApprovalEmailsTheLinkWhenAMailerIsConfigured(): void
    {
        $this->mailer->fakeChannel = 'console';
        $s = $this->approvedSeller();
        $act = $s['approve']['data']['activation'];
        $this->assertTrue($act['email_sent']);
        $this->assertNull($act['link'], 'an emailed link is not also shown to staff');
        $this->assertSame('seller_activation', $this->mailer->sent[0]['trigger']);

        // Mailer configured but Console refuses: honest answer + link for staff.
        $this->mailer->succeed = false;
        $mgr = $this->staff(['seller_manager'], 'seller-mgr@aicountly.org');
        $r = $this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/resend-activation");
        $this->assertFalse($this->json($r)['data']['activation']['email_sent']);
        $this->assertNotNull($this->json($r)['data']['activation']['link']);
    }

    public function testRejectNeedsReason(): void
    {
        $this->apply();
        $appId = (int) $this->db()->table('engage_seller_applications')->get()->getRow()->id;
        $mgr = $this->staff(['seller_manager']);
        $this->assertSame(422, $this->code($this->asStaff($mgr, 'post', "v1/seller-applications/{$appId}/reject", ['reason' => ''])));
        $r = $this->asStaff($mgr, 'post', "v1/seller-applications/{$appId}/reject", ['reason' => 'Not in our target market']);
        $this->assertSame(200, $this->code($r));
        $this->assertSame('rejected', $this->json($r)['data']['status']);
        $this->assertSame(409, $this->code($this->asStaff($mgr, 'post', "v1/seller-applications/{$appId}/approve")));
    }

    public function testActivationSetsPasswordAcceptsAgreementAndIsSingleUse(): void
    {
        $agreementId = $this->publishAgreement();
        $s = $this->approvedSeller();

        $check = $this->portal('get', 'auth/activation', ['token' => $s['token']]);
        $this->assertSame(200, $this->code($check));
        $this->assertSame($agreementId, $this->json($check)['data']['agreement']['id']);
        $this->assertStringContainsString('•', $this->json($check)['data']['email']);

        $weak = $this->portal('post', 'auth/activate', ['token' => $s['token'], 'password' => 'short']);
        $this->assertSame(422, $this->code($weak));
        $this->assertSame('weak_password', $this->json($weak)['code']);

        $stale = $this->portal('post', 'auth/activate', ['token' => $s['token'], 'password' => 'Str0ngPassword', 'agreement_version_id' => $agreementId + 99]);
        $this->assertSame(409, $this->code($stale));
        $this->assertSame('agreement_version_mismatch', $this->json($stale)['code']);

        $ok = $this->portal('post', 'auth/activate', ['token' => $s['token'], 'password' => 'Str0ngPassword', 'agreement_version_id' => $agreementId]);
        $this->assertSame(200, $this->code($ok));
        $body = $this->json($ok)['data'];
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $body['session_token']);
        $this->assertSame('kyc_pending', $body['seller']['status']);
        $this->assertSame('submit_kyc', $body['seller']['next_step']);

        $row = $this->db()->table('engage_sellers')->where('id', $s['seller_id'])->get()->getRowArray();
        $this->assertTrue(password_verify('Str0ngPassword', $row['password_hash']));
        $acc = $this->db()->table('engage_seller_agreement_acceptances')->get()->getRowArray();
        $this->assertSame('203.0.113.7', $acc['ip_address']);

        $again = $this->portal('post', 'auth/activate', ['token' => $s['token'], 'password' => 'Str0ngPassword2']);
        $this->assertSame(410, $this->code($again));
        $this->assertSame('token_invalid', $this->json($again)['code']);

        $me = $this->portal('get', 'me', [], $body['session_token']);
        $this->assertSame('SLR-000001', $this->json($me)['data']['seller_code']);
        $this->assertArrayNotHasKey('password_hash', $this->json($me)['data']);
    }

    public function testActivationWithoutAgreementLeavesSellerOnboarding(): void
    {
        $s = $this->approvedSeller();
        $r = $this->portal('post', 'auth/activate', ['token' => $s['token'], 'password' => 'Str0ngPassword']);
        $this->assertSame(200, $this->code($r));
        $seller = $this->json($r)['data']['seller'];
        $session = $this->json($r)['data']['session_token'];
        $this->assertSame('onboarding', $seller['status']);
        $this->assertSame('agreement_unavailable', $seller['next_step']);
        // Onboarding sellers cannot register leads yet.
        $lead = $this->portal('post', 'leads', ['name' => 'Customer', 'email' => 'c@cust.example'], $session);
        $this->assertSame(403, $this->code($lead));
        $this->assertSame('agreement_required', $this->json($lead)['code']);

        $agreementId = $this->publishAgreement();
        $acc = $this->portal('post', 'agreement/accept', ['agreement_version_id' => $agreementId], $session);
        $this->assertSame('kyc_pending', $this->json($acc)['data']['seller']['status']);
    }

    public function testLoginGenericErrorsLockoutAndUnlock(): void
    {
        $s = $this->activeSellerSession();

        $unknown = $this->portal('post', 'auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever1']);
        $this->assertSame(401, $this->code($unknown));
        $unknownError = $this->json($unknown)['error'];
        $wrong = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'wrongpass1']);
        $this->assertSame($unknownError, $this->json($wrong)['error'], 'same message for unknown email and wrong password');

        for ($i = 0; $i < 4; $i++) {
            $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'wrongpass1']);
        }
        $locked = $this->portal('post', 'auth/login', ['email' => 'RAVI@example.com', 'password' => 'Str0ngPassword']);
        $this->assertSame(423, $this->code($locked));
        $this->assertSame('account_locked', $this->json($locked)['code']);
        $lockedWrong = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'wrongpass1']);
        $this->assertSame(401, $this->code($lockedWrong), 'the lock is only disclosed to someone who knows the password');

        $mgr = $this->staff(['seller_manager']);
        $this->assertSame(200, $this->code($this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/unlock")));
        cache()->clean();
        $ok = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']);
        $this->assertSame(200, $this->code($ok));
        $this->assertFalse($this->json($ok)['data']['otp_required']);
    }

    public function testSuspendRevokesSessionsAndReactivateRestores(): void
    {
        $s = $this->activeSellerSession();
        $mgr = $this->staff(['seller_manager']);

        $this->assertSame(422, $this->code($this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/suspend", ['reason' => ''])));
        $r = $this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/suspend", ['reason' => 'Complaint under review']);
        $this->assertSame('suspended', $this->json($r)['data']['status']);

        $me = $this->portal('get', 'me', [], $s['session']);
        $this->assertSame(403, $this->code($me));
        $this->assertSame('seller_suspended', $this->json($me)['code']);
        $login = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']);
        $this->assertSame('seller_suspended', $this->json($login)['code']);

        $r = $this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/reactivate", ['reason' => 'Cleared']);
        $this->assertSame('kyc_pending', $this->json($r)['data']['status'], 'returns to the status its KYC / agreement allow');
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $s['session'])), 'old sessions stay revoked');
        $this->assertSame(200, $this->code($this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword'])));

        $t = $this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/terminate", ['reason' => 'Contract ended']);
        $this->assertSame('terminated', $this->json($t)['data']['status']);
        $this->assertSame(409, $this->code($this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/reactivate", ['reason' => 'x y z'])));
    }

    public function testForgotPasswordNeverClaimsAnEmailAndResetRevokesSessions(): void
    {
        $s = $this->activeSellerSession();

        // No mailer: same answer, and no live reset token is left behind.
        $r = $this->portal('post', 'auth/forgot-password', ['email' => 'ravi@example.com']);
        $this->assertSame(200, $this->code($r));
        $known = $this->json($r)['data'];
        $unknown = $this->portal('post', 'auth/forgot-password', ['email' => 'nobody@example.com']);
        $this->assertSame($known, $this->json($unknown)['data']);
        $live = $this->db()->query("SELECT COUNT(*) AS n FROM engage_seller_tokens WHERE purpose = 'password_reset' AND revoked_at IS NULL AND used_at IS NULL")->getRow()->n;
        $this->assertSame(0, (int) $live);

        // With a mailer: link emailed, reset works once and ends every session.
        $this->mailer->fakeChannel = 'smtp';
        $this->portal('post', 'auth/forgot-password', ['email' => 'ravi@example.com']);
        $url = $this->mailer->sent[0]['vars']['reset_url'];
        $token = substr($url, strpos($url, 'token=') + 6);
        $this->assertSame(200, $this->code($this->portal('get', 'auth/reset-password', ['token' => $token])));
        $this->assertSame(422, $this->code($this->portal('post', 'auth/reset-password', ['token' => $token, 'password' => 'nodigitshere'])));
        $ok = $this->portal('post', 'auth/reset-password', ['token' => $token, 'password' => 'N3wPassword!']);
        $this->assertSame(200, $this->code($ok));
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $s['session'])));
        $this->assertSame(410, $this->code($this->portal('post', 'auth/reset-password', ['token' => $token, 'password' => 'N3wPassword!2'])));
        $this->assertSame(200, $this->code($this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'N3wPassword!'])));
    }

    public function testStaffResetPasswordShowsLinkWhenNoMailer(): void
    {
        $s = $this->activeSellerSession();
        $mgr = $this->staff(['seller_manager']);
        $r = $this->asStaff($mgr, 'post', "v1/sellers/{$s['seller_id']}/reset-password");
        $this->assertSame(200, $this->code($r));
        $reset = $this->json($r)['data']['reset'];
        $this->assertFalse($reset['email_sent']);
        $this->assertStringStartsWith('https://seller.aicountly.com/reset-password?token=', $reset['link']);
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $s['session'])));
    }

    public function testEmailOtpStepWhenEnabledAndMailerConfigured(): void
    {
        $this->activeSellerSession();
        (new SettingsModel())->setSetting('seller_login_otp_enabled', true);

        // Setting on but no mailer: the OTP step is skipped (it cannot be delivered).
        $r = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']);
        $this->assertFalse($this->json($r)['data']['otp_required']);

        $this->mailer->fakeChannel = 'console';
        $r = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']);
        $d = $this->json($r)['data'];
        $this->assertTrue($d['otp_required']);
        $this->assertArrayNotHasKey('session_token', $d);
        $code = $this->mailer->sent[0]['vars']['code'];

        $bad = $this->portal('post', 'auth/login/otp', ['challenge_token' => $d['challenge_token'], 'code' => $code === '000000' ? '111111' : '000000']);
        $this->assertSame(401, $this->code($bad));
        $ok = $this->portal('post', 'auth/login/otp', ['challenge_token' => $d['challenge_token'], 'code' => $code]);
        $this->assertSame(200, $this->code($ok));
        $this->assertNotEmpty($this->json($ok)['data']['session_token']);
        $this->assertSame(410, $this->code($this->portal('post', 'auth/login/otp', ['challenge_token' => $d['challenge_token'], 'code' => $code])));

        // Mailer refuses: sign-in fails closed rather than skipping the code.
        $this->mailer->succeed = false;
        $r = $this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']);
        $this->assertSame(503, $this->code($r));
        $this->assertSame('otp_unavailable', $this->json($r)['code']);
    }

    public function testSessionIdleTimeoutAndLogout(): void
    {
        $s = $this->activeSellerSession();
        $this->db()->query("UPDATE engage_seller_sessions SET last_seen_at = NOW() - INTERVAL '9 hours'");
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $s['session'])));

        $login = $this->json($this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']));
        $session = $login['data']['session_token'];
        $this->assertSame(200, $this->code($this->portal('post', 'auth/logout', [], $session)));
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $session)));
    }

    public function testChangePasswordRevokesOtherSessions(): void
    {
        $s = $this->activeSellerSession();
        $other = $this->json($this->portal('post', 'auth/login', ['email' => 'ravi@example.com', 'password' => 'Str0ngPassword']))['data']['session_token'];
        $bad = $this->portal('post', 'auth/change-password', ['current_password' => 'nope', 'new_password' => 'An0therPassword'], $s['session']);
        $this->assertSame('invalid_current_password', $this->json($bad)['code']);
        $ok = $this->portal('post', 'auth/change-password', ['current_password' => 'Str0ngPassword', 'new_password' => 'An0therPassword'], $s['session']);
        $this->assertSame(1, $this->json($ok)['data']['other_sessions_revoked']);
        $this->assertSame(200, $this->code($this->portal('get', 'me', [], $s['session'])));
        $this->assertSame(401, $this->code($this->portal('get', 'me', [], $other)));
    }

    public function testPartnerMasterIsAdministratorOnly(): void
    {
        $r = $this->asStaff($this->staff(['member']), 'get', 'v1/partners');
        $this->assertSame(403, $this->code($r));
        $r = $this->asStaff($this->staff(['seller_manager'], 'sm@aicountly.org'), 'post', 'v1/partners/1/unlock');
        $this->assertSame(403, $this->code($r));
    }

    public function testPayoutsAreHonestlyNotEnabled(): void
    {
        $s = $this->activeSellerSession();
        $r = $this->json($this->portal('get', 'payouts', [], $s['session']))['data'];
        $this->assertFalse($r['enabled']);
        $this->assertSame('payouts not enabled yet', $r['status']);
        $this->assertSame([], $r['items']);
    }
}
