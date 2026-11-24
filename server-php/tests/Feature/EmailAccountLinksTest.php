<?php
namespace Tests\Feature;

use App\Services\Licensing\EmailAccountLinks;
use Tests\Support\LicensingTestCase;

final class EmailAccountLinksTest extends LicensingTestCase
{
    public function testTransferIsScopedVersionedAndIdempotent(): void
    {
        $this->publishedOffer('email-paid', ['product_code' => 'email', 'app_keys' => ['email']]);
        $email = $this->issue(42, ['offer_code' => 'email-paid', 'holder_uuid' => 'child-1', 'holder_note' => 'Fixture verified']);
        $books = $this->issue(43, ['holder_uuid' => 'child-1', 'holder_note' => 'Fixture verified']);
        $service = new EmailAccountLinks($this->db());
        $input = ['link_id' => str_repeat('a',32), 'source_subject' => 'child-1', 'source_realm' => 'business', 'main_subject' => 'main-1'];
        $preview = $service->process($input, false);
        $this->assertSame(200, $preview['status'], json_encode($preview));
        $this->assertCount(1, $preview['data']['licences']);
        $this->assertSame(409, $service->process($input + ['digest' => str_repeat('0',64)], true)['status']);
        $receipt = $service->process($input + ['digest' => $preview['data']['digest']], true);
        $this->assertSame(200, $receipt['status'], json_encode($receipt));
        $this->assertSame('active', $receipt['data']['status']);
        $rows = $this->db()->query("SELECT product_code, holder_uuid, version FROM engage_licences ORDER BY id")->getResultArray();
        $byProduct = array_column($rows, 'holder_uuid', 'product_code');
        $this->assertSame('main-1', $byProduct['email']);
        $this->assertSame('child-1', $byProduct['books']);
        $this->assertEquals($receipt, $service->process($input + ['digest' => $preview['data']['digest']], true));
        $this->assertSame(1, $this->db()->table('engage_licence_events')->where('type', 'holder_changed')->countAllResults());
        $this->assertSame(409, $service->process(['link_id' => str_repeat('b',32), 'source_subject' => 'main-1', 'source_realm' => 'business', 'main_subject' => 'child-1'], false)['status']);
        $this->db()->transBegin();
        $this->assertSame('main-1', $service->canonicalHolder('child-1', 'email'));
        $this->assertSame('child-1', $service->canonicalHolder('child-1', 'books'));
        $this->db()->transRollback();
    }

    public function testInvalidIdentityAndStalePreviewCannotTransfer(): void
    {
        $service = new EmailAccountLinks($this->db());
        $bad = ['link_id' => str_repeat('a',32), 'source_subject' => 'mid_example', 'source_realm' => 'business', 'main_subject' => 'main-1'];
        $this->assertSame(422, $service->process($bad, true)['status']);
        $good = array_replace($bad, ['source_realm' => 'personal']);
        $preview = $service->process($good, false);
        $this->assertSame(200, $preview['status']);
        $this->assertSame(409, $service->process($good + ['digest' => 'wrong'], true)['status']);
        $this->assertSame(0, $this->db()->table('engage_email_account_links')->countAllResults());
    }
    public function testAChangedLicenceRequiresAnotherPreviewAndPreservesCompanyScope(): void
    {
        $this->publishedOffer('email-paid', ['product_code' => 'email', 'app_keys' => ['email']]);
        $this->issue(42, ['offer_code' => 'email-paid', 'holder_uuid' => 'child-1', 'holder_note' => 'Fixture verified']);
        $service = new EmailAccountLinks($this->db());
        $input = ['link_id' => str_repeat('c',32), 'source_subject' => 'child-1', 'source_realm' => 'business', 'main_subject' => 'main-1'];
        $preview = $service->process($input, false);
        $this->db()->query("UPDATE engage_licences SET version = version + 1 WHERE holder_uuid = 'child-1'");
        $this->assertSame(409, $service->process($input + ['digest' => $preview['data']['digest']], true)['status']);
        $before = $this->db()->query("SELECT scope, cmp_id, ends_at FROM engage_licences WHERE holder_uuid = 'child-1'")->getRowArray();
        $fresh = $service->process($input, false);
        $this->assertSame(200, $service->process($input + ['digest' => $fresh['data']['digest']], true)['status']);
        $after = $this->db()->query("SELECT scope, cmp_id, ends_at FROM engage_licences WHERE holder_uuid = 'main-1'")->getRowArray();
        $this->assertSame($before, $after);
        $this->assertSame(409, $service->process(array_replace($input, ['link_id' => str_repeat('d',32), 'main_subject' => 'another-main']), false)['status']);
    }

    public function testMixedProductBundlesCannotTransferUnrelatedRights(): void
    {
        $this->publishedOffer('email-bundle', ['product_code' => 'email', 'app_keys' => ['email', 'books']]);
        $this->issue(42, ['offer_code' => 'email-bundle', 'holder_uuid' => 'child-1', 'holder_note' => 'Fixture verified']);
        $service = new EmailAccountLinks($this->db());
        $input = ['link_id' => str_repeat('e',32), 'source_subject' => 'child-1', 'source_realm' => 'business', 'main_subject' => 'main-1'];
        $this->assertSame(409, $service->process($input, false)['status']);
        $this->assertSame('child-1', $this->db()->query('SELECT holder_uuid FROM engage_licences')->getRowArray()['holder_uuid']);
    }

    public function testHttpContractRequiresSignedEmailServiceAndRejectsReplay(): void
    {
        $this->setEnv('ENGAGE_S2S_ENVIRONMENT', 'sandbox');
        $this->setEnv('ENGAGE_INBOUND_EMAIL_KEY_IDS', 'email-link-test');
        $this->setEnv('ENGAGE_INBOUND_EMAIL_SECRET_EMAIL_LINK_TEST', self::ISSUE_SECRET);
        $this->setEnv('ENGAGE_INBOUND_EMAIL_SCOPE_EMAIL_LINK_TEST', 'issue');
        $body = json_encode(['link_id' => str_repeat('f',32), 'source_subject' => 'child-1', 'source_realm' => 'business', 'main_subject' => 'main-1']);
        $path = '/v1/email/account-links/preview';
        $headers = \App\Libraries\AicS2s\AicS2sSignature::headers('email', 'email-link-test', self::ISSUE_SECRET, 'sandbox', 'POST', $path, '', $body, 'child-1');
        $headers['Content-Type'] = 'application/json';
        $ok = $this->withHeaders($headers)->withBody($body)->post(ltrim($path, '/'));
        $this->assertSame(200, $this->code($ok), $ok->getJSON());
        $replayed = $this->withHeaders($headers)->withBody($body)->post(ltrim($path, '/'));
        $this->assertSame(401, $this->code($replayed));
        $headers['X-AIC-Signature'] = 'v1=' . str_repeat('0',64);
        $bad = $this->withHeaders($headers)->withBody($body)->post(ltrim($path, '/'));
        $this->assertSame(401, $this->code($bad));
    }

}
