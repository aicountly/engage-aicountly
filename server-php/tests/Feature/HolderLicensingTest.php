<?php

namespace Tests\Feature;

use App\Libraries\AicS2s\AicS2sSignature;
use App\Libraries\AicS2s\ManageClient;
use App\Services\Licensing\EntitlementOutbox;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\LicensingTestCase;

/**
 * Holder-level licences, Basic, change-offer, provisioning and the Engage → Manage entitlement outbox
 * (contract §3.1–3.3, docs/LICENSING.md).
 *
 * @internal
 */
final class HolderLicensingTest extends LicensingTestCase
{
    private const MY_KEY_ID = 'myaicountly-issue-2026-10';
    private const MY_SECRET = 'myaicountly-issue-secret-0123456789abcdefghij';

    // ------------------------------------------------------------------ fixtures

    /** A published Basic (default) offer for $product; returns its row id. */
    private function basicOffer(string $product = 'books', array $entitlements = [['code' => 'max_companies', 'label' => 'Companies', 'value' => 1, 'unit' => 'count']]): int
    {
        if (! $this->db()->table('engage_licence_types')->where('code', 'basic')->countAllResults()) {
            $this->licenceType('basic', 'complimentary');
        }
        $admin = $this->admin();
        $r = $this->asStaff($admin, 'post', 'v1/offers', [
            'offer_code' => $product . '-basic', 'product_code' => $product, 'licence_type_code' => 'basic', 'label' => 'Basic',
            'price_paise' => 0, 'price_basis' => 'inclusive', 'gst_rate' => '0', 'term_months' => 0, 'term_days' => 0,
            'entitlements' => $entitlements, 'is_default' => true,
        ]);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $id = (int) $this->json($r)['data']['id'];
        $p = $this->asStaff($admin, 'post', "v1/offers/{$id}/publish");
        $this->assertSame(200, $this->code($p), (string) $p->response()->getBody());

        return $id;
    }

    private function issueToHolder(string $holder, array $extra = []): TestResponse
    {
        if (! $this->db()->table('engage_offers')->where('offer_code', 'books-annual')->where('status', 'published')->countAllResults()) {
            $this->publishedOffer();
        }
        $acc = $this->db()->table('engage_accounts')->where('name', 'Holder account')->get()->getRowArray();
        $accountId = $acc ? (int) $acc['id'] : $this->account('Holder account');

        return $this->asStaff($this->admin(), 'post', 'v1/licences', array_merge([
            'account_id' => $accountId, 'holder_uuid' => $holder, 'offer_code' => 'books-annual', 'holder_note' => 'Owner confirmed on a call.',
        ], $extra));
    }

    /** @return list<array<string,mixed>> */
    private function outbox(?string $holder = null): array
    {
        $b = $this->db()->table('engage_entitlement_outbox')->orderBy('id');
        if ($holder !== null) {
            $b->where('holder_uuid', $holder);
        }

        return array_map(static function (array $r) {
            $r['payload'] = json_decode((string) $r['payload'], true);

            return $r;
        }, $b->get()->getResultArray());
    }

    private function licenceRow(string $ref): array
    {
        return $this->db()->table('engage_licences')->where('licence_ref', $ref)->get()->getRowArray();
    }

    private function configureMyAicountly(): void
    {
        $this->setEnv('ENGAGE_S2S_ENVIRONMENT', 'sandbox');
        $this->setEnv('ENGAGE_INBOUND_MYAICOUNTLY_KEY_IDS', self::MY_KEY_ID);
        $this->setEnv('ENGAGE_INBOUND_MYAICOUNTLY_SECRET_MYAICOUNTLY_ISSUE_2026_10', self::MY_SECRET);
        $this->setEnv('ENGAGE_INBOUND_MYAICOUNTLY_SCOPE_MYAICOUNTLY_ISSUE_2026_10', 'issue');
    }

    private function provisionCall(array $body, string $client = 'myaicountly', ?string $keyId = null, ?string $secret = null): TestResponse
    {
        $json = json_encode($body);
        $h = AicS2sSignature::headers($client, $keyId ?? self::MY_KEY_ID, $secret ?? self::MY_SECRET, 'sandbox', 'POST', '/v1/provisioning/accounts', '', $json, 'signup');
        $this->headers = [];
        $this->requestBody = '';
        $this->bodyFormat = '';

        return $this->withHeaders($h + ['Content-Type' => 'application/json'])->withBody($json)->call('post', 'v1/provisioning/accounts');
    }

    /** An outbox whose Manage client records calls and answers from $answers (status per call, last one repeats). */
    private function deliveringOutbox(array &$calls, array $answers): EntitlementOutbox
    {
        $i = 0;
        $transport = static function (string $method, string $url, array $headers, string $body) use (&$calls, $answers, &$i) {
            $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => json_decode($body, true), 'raw' => $body];
            $status = $answers[min($i, count($answers) - 1)];
            $i++;

            return ['status' => $status, 'body' => json_encode($status < 300 ? ['success' => true, 'data' => ['result' => 'applied']] : ['success' => false, 'error' => ['code' => 'x']]), 'error' => ''];
        };

        return new EntitlementOutbox(null, new ManageClient($transport, 'https://manage.test/api', 'engage-push-2026-10', str_repeat('k', 40), 'sandbox'));
    }

    // ------------------------------------------------------------------ holder issue + outbox

    public function testHolderLicenceIsIssuedWithoutCompanyAndQueuesFullState(): void
    {
        $r = $this->issueToHolder('u-1001');
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $l = $this->json($r)['data'];
        $this->assertNull($l['cmp_id']);
        $this->assertSame('u-1001', $l['holder_uuid']);
        $this->assertSame('active', $l['status']['code']);

        $rows = $this->outbox('u-1001');
        $this->assertCount(1, $rows);
        $p = $rows[0]['payload'];
        $this->assertEqualsCanonicalizing(['event_id', 'holder_uuid', 'app_key', 'seq', 'plan', 'status', 'licence_ref', 'starts_at', 'ends_at', 'seats_total', 'entitlements', 'occurred_at'], array_keys($p));
        $this->assertSame('books', $p['app_key']);
        $this->assertSame(1, $p['seq']);
        $this->assertSame('active', $p['status']);
        $this->assertSame($l['licence_ref'], $p['licence_ref']);
        $this->assertSame('books-annual', $p['plan']['offer_code']);
        $this->assertSame('paid', $p['plan']['lifecycle_kind']);
        $this->assertFalse($p['plan']['is_default']);
        $this->assertSame(3, $p['seats_total']);
        $this->assertSame([['code' => 'companies', 'label' => 'Companies', 'value' => 1, 'unit' => null]], $p['entitlements']);

        // Events of a company-less licence are numbered per holder.
        $events = $this->db()->table('engage_licence_events')->where('licence_ref', $l['licence_ref'])->orderBy('id')->get()->getResultArray();
        $this->assertSame([1, 2], array_map('intval', array_column($events, 'seq')));
        $this->assertSame(['u-1001', 'u-1001'], array_column($events, 'holder_uuid'));

        // The holder is now in the staff lookup.
        $h = $this->json($this->asStaff($this->admin(), 'get', 'v1/holders', ['q' => 'u-1001']))['data']['items'];
        $this->assertSame('u-1001', $h[0]['holder_uuid']);
    }

    public function testUnknownHolderNeedsANoteAndAHolderOrCompany(): void
    {
        $this->publishedOffer();
        $acc = $this->account();
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $acc, 'offer_code' => 'books-annual']);
        $this->assertSame(422, $this->code($r));
        $this->assertArrayHasKey('holder_uuid', $this->json($r)['details']);
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $acc, 'holder_uuid' => 'u-new', 'offer_code' => 'books-annual']);
        $this->assertSame(422, $this->code($r));
        $this->assertArrayHasKey('holder_note', $this->json($r)['details']);
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $acc, 'holder_uuid' => 'bad uuid!', 'offer_code' => 'books-annual']);
        $this->assertSame(422, $this->code($r));
    }

    public function testEveryTransitionQueuesItsStateAndSeqIsMonotonicPerHolder(): void
    {
        $l = $this->json($this->issueToHolder('u-2002'))['data'];
        $ref = $l['licence_ref'];
        $admin = $this->admin();
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/licences/{$ref}/suspend", ['version' => 1, 'reason' => 'Payment bounced'])));
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/licences/{$ref}/reinstate", ['version' => 2, 'reason' => 'Paid'])));
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/licences/{$ref}/seats", ['version' => 3, 'seats_total' => 5])));
        // A seat assignment does not change the entitlement state: nothing queued.
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/licences/{$ref}/seat-assignments", ['version' => 4, 'member_ref' => 'm1'])));
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/licences/{$ref}/extend", ['version' => 5, 'ends_at' => gmdate('Y-m-d', strtotime('+3 years')), 'reason' => 'Goodwill'])));
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/licences/{$ref}/renew", ['version' => 6])));
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/licences/{$ref}/revoke", ['version' => 7, 'reason' => 'Refund'])));

        $rows = $this->outbox('u-2002');
        $this->assertSame(['licence.issued', 'licence.suspend', 'licence.reinstate', 'licence.seats', 'licence.extend', 'licence.renew', 'licence.revoke'], array_column($rows, 'reason'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_map('intval', array_column($rows, 'seq')));
        $this->assertSame(['active', 'suspended', 'active', 'active', 'active', 'active', 'revoked'], array_map(static fn ($r) => $r['payload']['status'], $rows));
        $this->assertSame(5, $rows[3]['payload']['seats_total']);
        $this->assertSame(array_column($rows, 'seq'), array_map(static fn ($r) => (string) $r['payload']['seq'], $rows));
    }

    public function testLicenceChangeAndOutboxCommitTogetherOrNotAtAll(): void
    {
        $l = $this->json($this->issueToHolder('u-3003'))['data'];
        // Make the outbox insert fail for a suspend: the licence change must roll back with it.
        $this->db()->query("CREATE OR REPLACE FUNCTION test_fail_outbox() RETURNS trigger LANGUAGE plpgsql AS \$f\$
            BEGIN IF NEW.reason = 'licence.suspend' THEN RAISE EXCEPTION 'outbox down'; END IF; RETURN NEW; END \$f\$");
        $this->db()->query('CREATE TRIGGER trg_test_fail_outbox BEFORE INSERT ON engage_entitlement_outbox FOR EACH ROW EXECUTE FUNCTION test_fail_outbox()');
        try {
            $r = $this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/suspend", ['version' => 1, 'reason' => 'x']);
            $this->assertSame(500, $this->code($r));
            $row = $this->licenceRow($l['licence_ref']);
            $this->assertSame('active', $row['status']);
            $this->assertSame(1, (int) $row['version']);
            $this->assertSame(0, $this->db()->table('engage_licence_events')->where('licence_ref', $l['licence_ref'])->where('type', 'suspended')->countAllResults());
            $this->assertCount(1, $this->outbox('u-3003'));
        } finally {
            $this->db()->query('DROP TRIGGER IF EXISTS trg_test_fail_outbox ON engage_entitlement_outbox');
            $this->db()->query('DROP FUNCTION IF EXISTS test_fail_outbox()');
        }
        // A refused write (stale version) queues nothing either.
        $this->assertSame(409, $this->code($this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/suspend", ['version' => 9, 'reason' => 'x'])));
        $this->assertCount(1, $this->outbox('u-3003'));
    }

    // ------------------------------------------------------------------ issue guards

    public function testIdempotencyKeyReplaysAndRefusesADifferentRequest(): void
    {
        $this->publishedOffer();
        $acc = $this->account('Holder account');
        $body = ['account_id' => $acc, 'holder_uuid' => 'u-idem', 'offer_code' => 'books-annual', 'holder_note' => 'ok'];
        $this->headers = [];
        $r1 = $this->withHeaders(['Authorization' => 'Bearer ' . $this->admin(), 'Idempotency-Key' => 'issue-key-0001'])->withBodyFormat('json')->post('v1/licences', $body);
        $this->assertSame(201, $this->code($r1));
        $this->headers = [];
        $r2 = $this->withHeaders(['Authorization' => 'Bearer ' . $this->admin(), 'Idempotency-Key' => 'issue-key-0001'])->withBodyFormat('json')->post('v1/licences', $body);
        $this->assertSame(200, $this->code($r2));
        $this->assertTrue($this->json($r2)['data']['replayed']);
        $this->assertSame($this->json($r1)['data']['licence_ref'], $this->json($r2)['data']['licence_ref']);
        $this->assertSame(1, $this->db()->table('engage_licences')->where('holder_uuid', 'u-idem')->countAllResults());
        $this->assertCount(1, $this->outbox('u-idem'));

        $r3 = $this->asStaff($this->admin(), 'post', 'v1/licences', $body + ['idempotency_key' => 'issue-key-0001', 'seats_total' => 9]);
        $this->assertSame(409, $this->code($r3));
        $this->assertSame('idempotency_conflict', $this->json($r3)['code']);
    }

    public function testSecondLiveLicenceIsRefusedUnlessAddon(): void
    {
        $first = $this->json($this->issueToHolder('u-dup'))['data'];
        $r = $this->issueToHolder('u-dup');
        $this->assertSame(409, $this->code($r));
        $this->assertSame('live_licence_exists', $this->json($r)['code']);
        $this->assertSame($first['licence_ref'], $this->json($r)['details']['licence_ref']);
        $r = $this->issueToHolder('u-dup', ['is_addon' => true]);
        $this->assertSame(201, $this->code($r));
        $this->assertTrue($this->json($r)['data']['is_addon']);

        // Company-level (legacy) issue is guarded the same way.
        $this->issue(42);
        $acc = (int) $this->db()->table('engage_account_companies')->where('cmp_id', 42)->get()->getRow()->account_id;
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $acc, 'cmp_id' => 42, 'offer_code' => 'books-annual']);
        $this->assertSame(409, $this->code($r));
    }

    public function testQuoteRefIsValidatedAndPassedThrough(): void
    {
        $r = $this->issueToHolder('u-quote-holder', ['quote_ref' => 'not a ref!']);
        $this->assertSame(422, $this->code($r));
        $this->assertArrayHasKey('quote_ref', $this->json($r)['details']);
        // A well-formed ref no quote carries: the coupons hook (LicenceHooks) refuses and the licence rolls back.
        $r = $this->issueToHolder('u-quote-holder', ['quote_ref' => 'QTE-000042']);
        $this->assertSame(422, $this->code($r));
        $this->assertSame(['quote_invalid', 'quote_not_found'], [$this->json($r)['code'], $this->json($r)['details']['reason']]);
        $this->assertSame(0, $this->db()->table('engage_licences')->where('holder_uuid', 'u-quote-holder')->countAllResults());
        // A real staff quote for this holder and offer is passed through and accepted.
        $q = $this->asStaff($this->admin(), 'post', 'v1/quotes', ['holder_uuid' => 'u-quote-holder', 'offer_code' => 'books-annual']);
        $this->assertSame(201, $this->code($q), (string) $q->getJSON());
        $ref = $this->json($q)['data']['quote_ref'];
        $this->assertSame(201, $this->code($this->issueToHolder('u-quote-holder', ['quote_ref' => $ref])));
    }

    // ------------------------------------------------------------------ default offers + Basic

    public function testAtMostOnePublishedDefaultOfferPerProduct(): void
    {
        $this->basicOffer('books');
        $admin = $this->admin();
        $r = $this->asStaff($admin, 'post', 'v1/offers', [
            'offer_code' => 'books-free', 'product_code' => 'books', 'licence_type_code' => 'basic', 'label' => 'Free',
            'price_paise' => 0, 'price_basis' => 'inclusive', 'gst_rate' => '0', 'is_default' => true,
        ]);
        $id = (int) $this->json($r)['data']['id'];
        $p = $this->asStaff($admin, 'post', "v1/offers/{$id}/publish");
        $this->assertSame(409, $this->code($p), (string) $p->response()->getBody());
        $this->assertSame('default_offer_exists', $this->json($p)['code']);

        // The database refuses it whatever the code path.
        $this->publishedOffer();
        $this->expectException(\Throwable::class);
        $this->db()->query("UPDATE engage_offers SET is_default = TRUE WHERE offer_code = 'books-annual' AND status = 'published'");
    }

    public function testDefaultFlagRulesAndToggle(): void
    {
        $this->publishedOffer(); // priced, 12 months
        $id = (int) $this->db()->table('engage_offers')->where('offer_code', 'books-annual')->get()->getRow()->id;
        $r = $this->asStaff($this->admin(), 'post', "v1/offers/{$id}/default", ['is_default' => true]);
        $this->assertSame(422, $this->code($r));
        $basic = $this->basicOffer('books');
        $off = $this->asStaff($this->admin(), 'post', "v1/offers/{$basic}/default", ['is_default' => false]);
        $this->assertSame(200, $this->code($off));
        $this->assertFalse($this->json($off)['data']['is_default']);
        $on = $this->asStaff($this->admin(), 'post', "v1/offers/{$basic}/default", ['is_default' => true]);
        $this->assertSame(200, $this->code($on));
        $this->assertTrue($this->json($on)['data']['is_default']);
        // Read-only roles cannot toggle.
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['member'], 'm@aicountly.org'), 'post', "v1/offers/{$basic}/default", ['is_default' => false])));
    }

    public function testPaidLicenceSupersedesBasicAndBasicReturnsWhenItEnds(): void
    {
        $this->basicOffer('books');
        $prov = Services::provisioning()->provision(['holder_uuid' => 'u-basic']);
        $this->assertSame(1, $prov['created']);
        $basicRef = $prov['licences'][0]['licence_ref'];
        $this->assertSame('active', $prov['licences'][0]['status']);
        $first = $this->outbox('u-basic');
        $this->assertCount(1, $first);
        $this->assertTrue($first[0]['payload']['plan']['is_default']);
        $this->assertSame('complimentary', $first[0]['payload']['plan']['lifecycle_kind']);
        $this->assertSame([['code' => 'max_companies', 'label' => 'Companies', 'value' => 1, 'unit' => 'count']], $first[0]['payload']['entitlements']);

        $paid = $this->json($this->issueToHolder('u-basic'))['data'];
        $this->assertSame('superseded', $this->licenceRow($basicRef)['status']);
        $this->assertSame(1, $this->db()->table('engage_licence_events')->where('licence_ref', $basicRef)->where('type', 'superseded')->countAllResults());
        $rows = $this->outbox('u-basic');
        $last = end($rows)['payload'];
        $this->assertSame($paid['licence_ref'], $last['licence_ref']);
        $this->assertFalse($last['plan']['is_default']);

        $this->assertSame(200, $this->code($this->asStaff($this->admin(), 'post', "v1/licences/{$paid['licence_ref']}/revoke", ['version' => 1, 'reason' => 'Refund'])));
        $this->assertSame('active', $this->licenceRow($basicRef)['status']);
        $this->assertSame(1, $this->db()->table('engage_licence_events')->where('licence_ref', $basicRef)->where('type', 'reactivated')->countAllResults());
        $rows = $this->outbox('u-basic');
        $last = end($rows)['payload'];
        $this->assertSame('active', $last['status']);
        $this->assertSame($basicRef, $last['licence_ref']);
        $this->assertTrue($last['plan']['is_default']);
    }

    public function testExpiryJobFallsBackToBasic(): void
    {
        $this->basicOffer('books');
        Services::provisioning()->provision(['holder_uuid' => 'u-exp']);
        $paid = $this->json($this->issueToHolder('u-exp', ['starts_at' => gmdate('Y-m-d', strtotime('-13 months'))]))['data'];
        // The term already ended: the paid licence does not supersede Basic.
        $basic = $this->db()->table('engage_licences')->where('holder_uuid', 'u-exp')->where('is_basic', true)->get()->getRowArray();
        $this->assertSame('active', $basic['status']);
        $this->assertSame(1, Services::licences()->expireDue());
        $this->assertSame('expired', $this->licenceRow($paid['licence_ref'])['status']);
        $rows = $this->outbox('u-exp');
        $last = end($rows)['payload'];
        $this->assertSame($basic['licence_ref'], $last['licence_ref']);
        $this->assertSame('active', $last['status']);
    }

    // ------------------------------------------------------------------ change-offer

    public function testChangeOfferMovesToAnotherOfferOfTheSameProduct(): void
    {
        $l = $this->json($this->issueToHolder('u-chg'))['data'];
        $this->publishedOffer('books-pro', ['label' => 'Books Pro', 'price_paise' => 2360000, 'seats' => 10,
            'entitlements' => [['code' => 'max_companies', 'label' => 'Companies', 'value' => 5, 'unit' => 'count']]]);
        $r = $this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/change-offer", ['version' => 1, 'offer_code' => 'books-pro', 'reason' => 'Upgrade']);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $d = $this->json($r)['data'];
        $this->assertSame('books-pro', $d['offer']['code']);
        $this->assertSame(10, $d['seats']['total']);
        $this->assertSame($l['term']['ends_at'], $d['term']['ends_at']); // term kept
        $ev = $this->db()->table('engage_licence_events')->where('licence_ref', $l['licence_ref'])->where('type', 'plan_changed')->get()->getRowArray();
        $changes = json_decode($ev['changes'], true);
        $this->assertSame('upgrade', $changes['direction']);
        $this->assertSame('books-annual', $changes['offer']['from']['code']);
        $rows = $this->outbox('u-chg');
        $last = end($rows);
        $this->assertSame('licence.change-offer', $last['reason']);
        $this->assertSame('books-pro', $last['payload']['plan']['offer_code']);
        $this->assertSame(5, $last['payload']['entitlements'][0]['value']);

        // restart: the new term starts now.
        $this->publishedOffer('books-lite', ['label' => 'Books Lite', 'price_paise' => 590000, 'term_months' => 6]);
        $r = $this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/change-offer", ['version' => 2, 'offer_code' => 'books-lite', 'term_mode' => 'restart']);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $this->assertGreaterThan(strtotime('+5 months'), strtotime($this->json($r)['data']['term']['ends_at']));
        $this->assertLessThan(strtotime('+7 months'), strtotime($this->json($r)['data']['term']['ends_at']));
        $ev = $this->db()->table('engage_licence_events')->where('licence_ref', $l['licence_ref'])->where('type', 'plan_changed')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('downgrade', json_decode($ev['changes'], true)['direction']);
    }

    public function testChangeOfferRefusals(): void
    {
        $this->basicOffer('books');
        $l = $this->json($this->issueToHolder('u-chg2'))['data'];
        $admin = $this->admin();
        // Same offer, unknown offer, the Basic offer, another product.
        $this->assertSame(422, $this->code($this->asStaff($admin, 'post', "v1/licences/{$l['licence_ref']}/change-offer", ['version' => 1, 'offer_code' => 'books-annual'])));
        $this->assertSame(422, $this->code($this->asStaff($admin, 'post', "v1/licences/{$l['licence_ref']}/change-offer", ['version' => 1, 'offer_code' => 'nope'])));
        $this->assertSame(422, $this->code($this->asStaff($admin, 'post', "v1/licences/{$l['licence_ref']}/change-offer", ['version' => 1, 'offer_code' => 'books-basic'])));
        $this->publishedOffer('docs-annual', ['product_code' => 'docs', 'label' => 'Docs']);
        $this->assertSame(422, $this->code($this->asStaff($admin, 'post', "v1/licences/{$l['licence_ref']}/change-offer", ['version' => 1, 'offer_code' => 'docs-annual'])));
        // A Basic licence is never changed this way.
        $basic = Services::provisioning()->provision(['holder_uuid' => 'u-chg3'])['licences'][0];
        $r = $this->asStaff($admin, 'post', "v1/licences/{$basic['licence_ref']}/change-offer", ['version' => 1, 'offer_code' => 'books-annual']);
        $this->assertSame(409, $this->code($r));
        $this->assertSame('basic_not_changeable', $this->json($r)['code']);
        // Read-only role.
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['member'], 'm2@aicountly.org'), 'post', "v1/licences/{$l['licence_ref']}/change-offer", ['version' => 1, 'offer_code' => 'docs-annual'])));
    }

    // ------------------------------------------------------------------ publish-time validation

    public function testPublishValidatesManageAppKeysAndFinanceSac(): void
    {
        $this->licenceType();
        $admin = $this->admin();
        $mk = function (string $code, array $extra) use ($admin): int {
            $r = $this->asStaff($admin, 'post', 'v1/offers', array_merge(['offer_code' => $code, 'product_code' => 'books', 'licence_type_code' => 'standard',
                'label' => 'Xy', 'price_paise' => 100000, 'price_basis' => 'inclusive', 'gst_rate' => '18', 'sac' => '997331', 'term_months' => 12], $extra));
            $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());

            return (int) $this->json($r)['data']['id'];
        };
        $bad = $mk('x-unknown-app', ['app_keys' => ['books', 'flowx']]);
        $p = $this->asStaff($admin, 'post', "v1/offers/{$bad}/publish");
        $this->assertSame(422, $this->code($p));
        $this->assertStringContainsString('flowx', $this->json($p)['details']['app_keys']);

        // Manage holds no plan for `manage` (its push answers 422 unknown_application): refused at publish, by name.
        $mg = $mk('x-manage-app', ['app_keys' => ['books', 'manage']]);
        $p = $this->asStaff($admin, 'post', "v1/offers/{$mg}/publish");
        $this->assertSame(422, $this->code($p));
        $this->assertStringContainsString('Manage holds no plan for: manage', $this->json($p)['details']['app_keys']);
        $this->assertSame('draft', $this->db()->table('engage_offers')->where('id', $mg)->get()->getRowArray()['status']);

        $drive = $mk('x-drive', ['app_keys' => ['drive', 'docs', 'books']]);
        $this->assertSame(['docs', 'books'], $this->json($this->asStaff($admin, 'get', "v1/offers/{$drive}"))['data']['app_keys']);
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/offers/{$drive}/publish")));

        $sac = $mk('x-sac', ['sac' => '9983']);
        $p = $this->asStaff($admin, 'post', "v1/offers/{$sac}/publish");
        $this->assertSame(422, $this->code($p));
        $this->assertArrayHasKey('sac', $this->json($p)['details']);
    }

    // ------------------------------------------------------------------ provisioning endpoint

    public function testProvisioningEndpointIsIdempotentAndSkipsProductsWithoutDefault(): void
    {
        $this->configureMyAicountly();
        $this->basicOffer('books');
        $body = ['holder_uuid' => 'u-prov', 'email' => 'asha@example.com', 'name' => 'Asha', 'signed_up_at' => '2026-10-23T10:00:00Z', 'request_id' => AicS2sSignature::uuid4()];
        $r = $this->provisionCall($body);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $b = $this->json($r);
        $this->assertTrue($b['success']);
        $this->assertSame('u-prov', $b['data']['holder_uuid']);
        $this->assertCount(1, $b['data']['licences']);
        $this->assertSame(['licence_ref', 'product_code', 'plan', 'status'], array_keys($b['data']['licences'][0]));
        $this->assertSame('books', $b['data']['licences'][0]['product_code']);
        $this->assertSame(['code' => 'books-basic', 'label' => 'Basic'], $b['data']['licences'][0]['plan']);
        $this->assertSame('active', $b['data']['licences'][0]['status']);

        // Retried (new nonce, same holder): same licence, nothing new.
        $again = $this->json($this->provisionCall($body + ['x' => 1]));
        $this->assertSame($b['data']['licences'], $again['data']['licences']);
        $this->assertSame(1, $this->db()->table('engage_licences')->where('holder_uuid', 'u-prov')->countAllResults());
        $this->assertCount(1, $this->outbox('u-prov'));
        $holder = $this->db()->table('engage_holders')->where('holder_uuid', 'u-prov')->get()->getRowArray();
        $this->assertSame('asha@example.com', $holder['email']);

        // A product whose Basic is published later is picked up by engage:provision-basic --pending.
        $this->basicOffer('docs', []);
        $pending = Services::provisioning()->pendingHolders();
        $this->assertSame(['u-prov'], array_column($pending, 'holder_uuid'));
        $r2 = Services::provisioning()->provision(['holder_uuid' => 'u-prov']);
        $this->assertSame(1, $r2['created']);
        $this->assertSame([], Services::provisioning()->pendingHolders());

        // Staff revoked a Basic: provisioning does not bring it back.
        $docs = $this->db()->table('engage_licences')->where('holder_uuid', 'u-prov')->where('product_code', 'docs')->get()->getRowArray();
        $this->asStaff($this->admin(), 'post', "v1/licences/{$docs['licence_ref']}/revoke", ['version' => (int) $docs['version'], 'reason' => 'Abuse']);
        $r3 = Services::provisioning()->provision(['holder_uuid' => 'u-prov']);
        $this->assertSame(0, $r3['created']);
        $this->assertContains('revoked', array_column($r3['licences'], 'status'));
    }

    public function testProvisioningEndpointAuthAndValidation(): void
    {
        // Dormant until configured.
        $this->assertSame(503, $this->code($this->provisionCall(['holder_uuid' => 'u-x'])));
        $this->configureMyAicountly();
        $this->assertSame(422, $this->code($this->provisionCall(['holder_uuid' => ''])));
        $this->assertSame(422, $this->code($this->provisionCall(['holder_uuid' => 'u-x', 'email' => 'not-an-email'])));
        // Manage's keys are not myaicountly's.
        $this->configureManage(true);
        $this->assertSame(401, $this->code($this->provisionCall(['holder_uuid' => 'u-x'], 'manage', self::ISSUE_KEY_ID, self::ISSUE_SECRET)));
        // A myaicountly read key may not call it (route requires scope issue).
        $this->setEnv('ENGAGE_INBOUND_MYAICOUNTLY_KEY_IDS', self::MY_KEY_ID . ',myaicountly-read-1');
        $this->setEnv('ENGAGE_INBOUND_MYAICOUNTLY_SECRET_MYAICOUNTLY_READ_1', str_repeat('r', 40));
        $r = $this->provisionCall(['holder_uuid' => 'u-x'], 'myaicountly', 'myaicountly-read-1', str_repeat('r', 40));
        $this->assertSame(403, $this->code($r));
        $this->assertSame('route_not_allowed_for_key', $this->json($r)['error']['code']);
    }

    // ------------------------------------------------------------------ delivery

    public function testDeliveryIsInSeqOrderPerHolderWithRetryAndDeadLetter(): void
    {
        // One licence covering two apps: seq 1 (books) and seq 2 (docs) for the same holder.
        $this->publishedOffer('suite', ['label' => 'Suite', 'app_keys' => ['books', 'drive']]);
        $l = $this->json($this->issueToHolder('u-del', ['offer_code' => 'suite']))['data'];
        $this->issueToHolder('u-other');
        $other = $this->db()->table('engage_licences')->where('holder_uuid', 'u-other')->get()->getRowArray();
        $this->assertSame([['books', '1'], ['docs', '2']], array_map(static fn ($r) => [$r['app_key'], $r['seq']], $this->outbox('u-del')));

        // seq 1 fails (503): seq 2 of the same holder waits behind it; the other holder is not held up.
        $calls = [];
        $r = $this->deliveringOutbox($calls, [503, 200])->deliverDue();
        $this->assertSame([['u-del', 1], ['u-other', 1]], array_map(static fn ($c) => [$c['body']['holder_uuid'], $c['body']['seq']], $calls));
        $this->assertSame(1, $r['retry']);
        $rows = $this->outbox('u-del');
        $this->assertSame(['pending', 'pending'], array_column($rows, 'status'));
        $this->assertSame(503, (int) $rows[0]['last_http_status']);
        $this->assertSame(1, (int) $rows[0]['attempts']);

        // A newer state is queued for both apps; once due, the stale rows are skipped and the new ones go in seq order.
        $this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/suspend", ['version' => 1, 'reason' => 'x']);
        $this->db()->query("UPDATE engage_entitlement_outbox SET next_attempt_at = NOW() WHERE status = 'pending'");
        $calls = [];
        $r = $this->deliveringOutbox($calls, [200])->deliverDue();
        $this->assertSame(2, $r['skipped']);
        $this->assertSame([['u-del', 3], ['u-del', 4]], array_map(static fn ($c) => [$c['body']['holder_uuid'], $c['body']['seq']], $calls));
        $this->assertSame('suspended', $calls[0]['body']['status']);
        $this->assertSame(['skipped', 'skipped', 'delivered', 'delivered'], array_column($this->outbox('u-del'), 'status'));

        // Signed as Engage, to Manage's path.
        $h = [];
        foreach ($calls[0]['headers'] as $line) {
            [$k, $v] = explode(': ', $line, 2);
            $h[$k] = $v;
        }
        $this->assertSame('engage', $h['X-AIC-Client']);
        $this->assertSame('engage-push-2026-10', $h['X-AIC-Key-Id']);
        $this->assertSame('https://manage.test/api/v1/inbound/engage/entitlements', $calls[0]['url']);
        $canonical = AicS2sSignature::canonical('POST', '/v1/inbound/engage/entitlements', '', $h['X-AIC-Timestamp'], $h['X-AIC-Nonce'], $calls[0]['raw']);
        $this->assertTrue(AicS2sSignature::verify(str_repeat('k', 40), $canonical, $h['X-AIC-Signature']));

        // A 422 is terminal (rejected); 12 failures make a row dead. Both show on /health.
        $this->asStaff($this->admin(), 'post', "v1/licences/{$other['licence_ref']}/suspend", ['version' => 1, 'reason' => 'x']);
        $this->db()->query("UPDATE engage_entitlement_outbox SET next_attempt_at = NOW() WHERE status = 'pending'");
        $calls = [];
        $this->assertSame(1, $this->deliveringOutbox($calls, [422])->deliverDue()['rejected']);
        $this->asStaff($this->admin(), 'post', "v1/licences/{$other['licence_ref']}/reinstate", ['version' => 2, 'reason' => 'x']);
        $this->db()->query("UPDATE engage_entitlement_outbox SET attempts = 11 WHERE status = 'pending'");
        $calls = [];
        $this->assertSame(1, $this->deliveringOutbox($calls, [500])->deliverDue()['dead']);
        $health = $this->json($this->get('health'));
        $this->assertStringContainsString('1 dead, 1 rejected', $health['checks']['entitlement_outbox']);
        $panel = $this->json($this->asStaff($this->admin(), 'get', 'v1/entitlements/outbox', ['status' => 'dead']))['data'];
        $this->assertSame(1, $panel['summary']['dead']);
        $this->assertCount(1, $panel['items']);

        // Staff re-queue the current state as a new event (next seq).
        $rq = $this->asStaff($this->admin(), 'post', "v1/entitlements/outbox/{$panel['items'][0]['id']}/requeue");
        $this->assertSame(200, $this->code($rq));
        $this->assertSame(4, $this->json($rq)['data']['seq']);
    }

    public function testUnconfiguredPushIsDormant(): void
    {
        $this->issueToHolder('u-dormant');
        $outbox = new EntitlementOutbox(null, new ManageClient(static fn () => throw new \LogicException('no HTTP'), '', '', '', ''));
        $this->assertSame(0, $outbox->deliverDue()['sent']);
        $this->assertSame('pending', $this->outbox('u-dormant')[0]['status']);
    }

    // ------------------------------------------------------------------ commands

    /** Run a spark command class with options as CodeIgniter passes them; returns its output. */
    private function spark(string $class, array $params = []): string
    {
        \CodeIgniter\Test\Filters\CITestStreamFilter::registration();
        \CodeIgniter\Test\Filters\CITestStreamFilter::addOutputFilter();
        \CodeIgniter\Test\Filters\CITestStreamFilter::addErrorFilter();
        try {
            (new $class(Services::logger(), Services::commands()))->run($params);
        } finally {
            \CodeIgniter\Test\Filters\CITestStreamFilter::removeOutputFilter();
            \CodeIgniter\Test\Filters\CITestStreamFilter::removeErrorFilter();
        }

        return \CodeIgniter\Test\Filters\CITestStreamFilter::$buffer;
    }

    public function testBasicOffersCommandCreatesDraftsOnlyAndIsIdempotent(): void
    {
        $out = $this->spark(\App\Commands\BasicOffers::class, ['dry-run' => null]);
        $this->assertStringContainsString('No active complimentary licence type', $out);
        $out = $this->spark(\App\Commands\BasicOffers::class, ['dry-run' => null, 'create-type' => null]);
        $this->assertStringContainsString('would create books-basic', $out);
        $this->assertStringContainsString('skip flow: not a Manage app key', $out);
        $this->assertStringContainsString('skip manage: Manage holds no plan for it', $out);
        $this->assertStringNotContainsString('manage-basic', $out);
        $this->assertSame(0, $this->db()->table('engage_offers')->countAllResults());

        $this->spark(\App\Commands\BasicOffers::class, ['apply' => null, 'create-type' => null]);
        $drafts = $this->db()->table('engage_offers')->get()->getResultArray();
        $this->assertNotEmpty($drafts);
        foreach ($drafts as $o) {
            $this->assertSame('draft', $o['status']);
            $this->assertContains($o['is_default'], [true, 't', '1', 1]);
            $this->assertSame(0, (int) $o['price_paise']);
        }
        $books = $this->db()->table('engage_offers')->where('offer_code', 'books-basic')->get()->getRowArray();
        $this->assertSame('{books}', $books['app_keys']);
        $docs = $this->db()->table('engage_offers')->where('product_code', 'docs')->get()->getRowArray();
        $this->assertSame('{docs}', $docs['app_keys']);
        $n = count($drafts);
        $this->spark(\App\Commands\BasicOffers::class, ['apply' => null]);
        $this->assertSame($n, $this->db()->table('engage_offers')->countAllResults());

        // Staff publish one; provision-basic --holder issues it.
        $this->assertSame(200, $this->code($this->asStaff($this->admin(), 'post', "v1/offers/{$books['id']}/publish")));
        $out = $this->spark(\App\Commands\ProvisionBasic::class, ['holder=u-cli' => null]);
        $this->assertStringContainsString('1 Basic licence(s) created', $out);
        $this->assertSame(1, $this->db()->table('engage_licences')->where('holder_uuid', 'u-cli')->where('is_basic', true)->countAllResults());
    }
}
