<?php

namespace Tests\Feature;

use Config\Services;
use Tests\Support\LicensingTestCase;

/**
 * Holder-scoped Manage reads (docs/SELLING_LICENCES.md §8): the licences a person holds — which the company routes
 * never list, because since D-L2 a licence names its holder — with the same shapes, scoping and envelope.
 *
 * @internal
 */
final class ManageHolderReadApiTest extends LicensingTestCase
{
    private function data(\CodeIgniter\Test\TestResponse $r): array
    {
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());

        return $this->json($r);
    }

    private function basicAndPaid(): array
    {
        $this->licenceType('basic', 'complimentary');
        $basic = $this->asStaff($this->admin(), 'post', 'v1/offers', ['offer_code' => 'books-basic', 'product_code' => 'books', 'licence_type_code' => 'basic',
            'label' => 'Basic', 'price_paise' => 0, 'price_basis' => 'inclusive', 'gst_rate' => '18', 'term_months' => 0, 'term_days' => 0, 'is_default' => true]);
        $this->asStaff($this->admin(), 'post', 'v1/offers/' . $this->json($basic)['data']['id'] . '/publish');
        $this->publishedOffer();
        Services::provisioning()->provision(['holder_uuid' => '10042', 'email' => 'asha@example.com', 'name' => 'Asha', 'signed_up_at' => gmdate('c'), 'request_id' => 'r1']);
        Services::provisioning()->provision(['holder_uuid' => '20077', 'email' => 'other@example.com', 'name' => 'Other', 'signed_up_at' => gmdate('c'), 'request_id' => 'r2']);
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $this->account(), 'holder_uuid' => '10042', 'offer_code' => 'books-annual']);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());

        return $this->json($r)['data'];
    }

    public function testAHoldersLicencesSeatsAndActivity(): void
    {
        $this->configureManage();
        $paid = $this->basicAndPaid();

        $b = $this->data($this->s2s('get', 'manage/holders/10042/licences'));
        $this->assertTrue($b['success']);
        $this->assertSame([$paid['licence_ref']], array_values(array_filter(array_column($b['data'], 'licence_ref'), static fn ($r) => $r === $paid['licence_ref'])));
        $this->assertCount(2, $b['data'], 'the paid plan and the Basic it supersedes');
        $this->assertSame(['superseded', 'active'], [$b['data'][1]['status']['code'], $b['data'][0]['status']['code']]);
        $this->assertArrayHasKey('next_cursor', $b['page']);
        $this->assertNull($b['data'][0]['sold_by']);

        $active = $this->data($this->s2s('get', 'manage/holders/10042/licences', 'status=active'));
        $this->assertSame([$paid['licence_ref']], array_column($active['data'], 'licence_ref'));

        $one = $this->data($this->s2s('get', 'manage/holders/10042/licences/' . $paid['licence_ref']));
        $this->assertSame($paid['licence_ref'], $one['data']['licence_ref']);
        $seats = $this->data($this->s2s('get', 'manage/holders/10042/licences/' . $paid['licence_ref'] . '/seats'));
        $this->assertSame(['licence_ref' => $paid['licence_ref'], 'total' => 3, 'assigned' => 0, 'items' => []], $seats['data']);

        $act = $this->data($this->s2s('get', 'manage/holders/10042/activity', 'limit=50'));
        $this->assertContains('superseded', array_column(array_column($act['data'], 'type'), 'code'));
        $this->assertContains($paid['licence_ref'], array_column($act['data'], 'licence_ref'));

        // Another holder's licence answers exactly like a missing one; a holder with nothing paid still sees Basic.
        $this->assertSame(404, $this->code($this->s2s('get', 'manage/holders/20077/licences/' . $paid['licence_ref'])));
        $this->assertSame(404, $this->code($this->s2s('get', 'manage/holders/20077/licences/' . $paid['licence_ref'] . '/seats')));
        $other = $this->data($this->s2s('get', 'manage/holders/20077/licences'));
        $this->assertCount(1, $other['data']);
        $this->assertSame([], $this->data($this->s2s('get', 'manage/holders/99999/licences'))['data']);

        // The company routes never listed these (no company on a holder licence) — why these routes exist.
        $this->assertSame([], $this->data($this->s2s('get', 'manage/companies/42/licences'))['data']);
    }

    /**
     * What Manage's Licences tab asks for: everything that applies to one company of the owner — the owner's own
     * licences and the company's legacy company-level ones — in one list, with the free Basic plans left out on request.
     */
    public function testWithACompanyTheListIncludesItsCompanyLicencesAndBasicCanBeLeftOut(): void
    {
        $this->configureManage();
        $paid = $this->basicAndPaid();
        $legacy = $this->issue(77);

        $all = $this->data($this->s2s('get', 'manage/holders/10042/licences', 'cmp_id=77'));
        $refs = array_column($all['data'], 'licence_ref');
        $this->assertCount(3, $refs, 'the Basic, the paid plan and the company licence');
        $this->assertContains($legacy['licence_ref'], $refs);
        $this->assertContains($paid['licence_ref'], $refs);
        $this->assertNotContains($legacy['licence_ref'], array_column($this->data($this->s2s('get', 'manage/holders/10042/licences'))['data'], 'licence_ref'),
            'without cmp_id only the holder\'s own');

        $bought = $this->data($this->s2s('get', 'manage/holders/10042/licences', 'cmp_id=77&is_basic=false'));
        $this->assertEqualsCanonicalizing([$paid['licence_ref'], $legacy['licence_ref']], array_column($bought['data'], 'licence_ref'));
        $this->assertSame([false, false], array_column($bought['data'], 'is_basic'));
        $basic = $this->data($this->s2s('get', 'manage/holders/10042/licences', 'is_basic=true'));
        $this->assertSame([true], array_column($basic['data'], 'is_basic'));

        // Detail, seats and activity follow the same scope.
        $this->assertSame(404, $this->code($this->s2s('get', 'manage/holders/10042/licences/' . $legacy['licence_ref'])));
        $this->assertSame($legacy['licence_ref'], $this->data($this->s2s('get', 'manage/holders/10042/licences/' . $legacy['licence_ref'], 'cmp_id=77'))['data']['licence_ref']);
        $this->assertSame(200, $this->code($this->s2s('get', 'manage/holders/10042/licences/' . $legacy['licence_ref'] . '/seats', 'cmp_id=77')));
        $this->assertSame(404, $this->code($this->s2s('get', 'manage/holders/20077/licences/' . $paid['licence_ref'], 'cmp_id=78')), 'another holder, another company');

        $one = $this->data($this->s2s('get', 'manage/holders/10042/activity', 'cmp_id=77&licence_ref=' . $legacy['licence_ref'] . '&limit=50'));
        $this->assertNotSame([], $one['data']);
        $this->assertSame([$legacy['licence_ref']], array_values(array_unique(array_column($one['data'], 'licence_ref'))));
        $this->assertSame([], $this->data($this->s2s('get', 'manage/holders/10042/activity', 'licence_ref=' . $legacy['licence_ref']))['data'],
            'a company licence is not in the holder\'s own feed');

        foreach (['cmp_id=0', 'cmp_id=abc', 'is_basic=maybe'] as $q) {
            $this->assertSame(400, $this->code($this->s2s('get', 'manage/holders/10042/licences', $q)), $q);
        }
        $this->assertSame(400, $this->code($this->s2s('get', 'manage/holders/10042/activity', 'licence_ref=' . str_repeat('x', 40))));
    }

    public function testValidationAndKeys(): void
    {
        $this->configureManage(true);
        $bad = $this->s2s('get', 'manage/holders/' . str_repeat('a', 70) . '/licences');
        $this->assertSame(400, $this->code($bad));
        $this->assertSame('holder_uuid', $this->json($bad)['error']['details']['field']);
        $this->assertSame(400, $this->code($this->s2s('get', 'manage/holders/10042/licences', 'status=bogus')));
        // An issue key may only POST.
        $this->assertSame(403, $this->code($this->s2s('get', 'manage/holders/10042/licences', '', [], '', self::ISSUE_KEY_ID, self::ISSUE_SECRET)));
    }
}
