<?php

namespace Tests\Feature;

use Tests\Support\LicensingTestCase;

/**
 * Manage-facing read API (commerce contract v1 §4): §0.2 envelope, cmp_id scoping (another company's licence = 404,
 * none = empty list), cursor paging without totals, filters, seats, activity feed (no internal reasons or staff
 * names), catalogue (published offers on sale only, type-agnostic), licence types, bad query → 400, reads audited
 * as manage:<X-AIC-Actor>.
 */
final class ManageReadApiTest extends LicensingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureManage();
    }

    private function data(\CodeIgniter\Test\TestResponse $r, int $status = 200): array
    {
        $this->assertSame($status, $this->code($r), (string) $r->response()->getBody());
        $b = $this->json($r);
        $this->assertTrue($b['success']);
        $this->assertNotEmpty($b['request_id']);

        return $b;
    }

    public function testEmptyCompanyGetsAnEmptyListNot404(): void
    {
        $b = $this->data($this->s2s('get', 'manage/companies/42/licences'));
        $this->assertSame([], $b['data']);
        $this->assertSame(['next_cursor' => null, 'limit' => 25], $b['page']);
        $this->assertArrayNotHasKey('total', $b['page']);
        $b = $this->data($this->s2s('get', 'manage/companies/42/activity'));
        $this->assertSame([], $b['data']);
    }

    public function testLicenceListDetailAndContractShape(): void
    {
        $l = $this->issue(42, ['invoice_refs' => ['AIC/26-27/000001']]);
        $this->issue(77);
        $b = $this->data($this->s2s('get', 'manage/companies/42/licences'));
        $this->assertCount(1, $b['data']);
        $x = $b['data'][0];
        foreach (['licence_ref', 'product', 'licence_type', 'plan', 'status', 'term', 'seats', 'entitlements', 'attributes', 'invoice_refs', 'version'] as $k) {
            $this->assertArrayHasKey($k, $x, $k);
        }
        $this->assertSame($l['licence_ref'], $x['licence_ref']);
        $this->assertSame(['code' => 'active', 'label' => 'Active', 'tone' => 'success'], $x['status']);
        $this->assertSame(['code' => 'books', 'label' => 'Aicountly Books'], $x['product']);
        $this->assertSame('standard', $x['licence_type']['code']);
        $this->assertNull($x['plan']);
        $this->assertSame(['total' => 3, 'assigned' => 0], $x['seats']);
        $this->assertSame(['AIC/26-27/000001'], $x['invoice_refs']);
        foreach (['cmp_id', 'account', 'notes', 'status_reason', 'offer_snapshot', 'order_ref'] as $internal) {
            $this->assertArrayNotHasKey($internal, $x, $internal);
        }

        $d = $this->data($this->s2s('get', 'manage/companies/42/licences/' . $l['licence_ref']))['data'];
        $this->assertSame($x, $d);
    }

    public function testAnotherCompanysLicenceIsNotFound(): void
    {
        $l = $this->issue(77);
        foreach (["manage/companies/42/licences/{$l['licence_ref']}", "manage/companies/42/licences/{$l['licence_ref']}/seats", 'manage/companies/42/licences/LIC-999999', 'manage/companies/42/licences/bad%20ref'] as $p) {
            $r = $this->s2s('get', $p);
            $this->assertSame(404, $this->code($r), $p);
            $b = $this->json($r);
            $this->assertFalse($b['success']);
            $this->assertSame('not_found', $b['error']['code']);
            $this->assertFalse($b['error']['retryable']);
        }
        // The owning company sees it.
        $this->assertSame(200, $this->code($this->s2s('get', "manage/companies/77/licences/{$l['licence_ref']}")));
    }

    public function testCursorPagingAndFilters(): void
    {
        $refs = [];
        for ($i = 0; $i < 5; $i++) {
            // Several live licences of one product for one company are add-ons on purpose (the duplicate guard).
            $refs[] = $this->issue(42, $i > 0 ? ['is_addon' => true] : [])['licence_ref'];
        }
        $this->asStaff($this->admin(), 'post', "v1/licences/{$refs[0]}/suspend", ['version' => 1, 'reason' => 'x']);

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $q = 'limit=2' . ($cursor ? '&cursor=' . $cursor : '');
            $b = $this->data($this->s2s('get', 'manage/companies/42/licences', $q));
            $this->assertSame(2, $b['page']['limit']);
            $seen = array_merge($seen, array_column($b['data'], 'licence_ref'));
            $cursor = $b['page']['next_cursor'];
            $pages++;
        } while ($cursor !== null && $pages < 10);
        $this->assertSame(3, $pages);
        $this->assertSame(array_reverse($refs), $seen, 'newest first, no duplicates, nothing skipped');

        $b = $this->data($this->s2s('get', 'manage/companies/42/licences', 'status=suspended'));
        $this->assertSame([$refs[0]], array_column($b['data'], 'licence_ref'));
        $b = $this->data($this->s2s('get', 'manage/companies/42/licences', 'product_code=books&status=active'));
        $this->assertCount(4, $b['data']);
        $b = $this->data($this->s2s('get', 'manage/companies/42/licences', 'product_code=auditor'));
        $this->assertSame([], $b['data']);
    }

    public function testBadQueriesAre400(): void
    {
        foreach ([['manage/companies/42/licences', 'limit=0'], ['manage/companies/42/licences', 'limit=101'], ['manage/companies/42/licences', 'limit=abc'],
            ['manage/companies/42/licences', 'cursor=not-a-cursor'], ['manage/companies/42/licences', 'status=paid'], ['manage/companies/abc/licences', ''],
            ['manage/companies/0/activity', ''], ['manage/catalogue', 'cmp_id=-1'], ['manage/catalogue', 'cmp_id=abc']] as [$p, $q]) {
            $r = $this->s2s('get', $p, $q);
            $this->assertSame(400, $this->code($r), "{$p}?{$q}");
            $this->assertSame('bad_request', $this->json($r)['error']['code']);
            $this->assertArrayHasKey('field', $this->json($r)['error']['details']);
        }
    }

    public function testSeats(): void
    {
        $l = $this->issue(42);
        $this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/seat-assignments", ['version' => 1, 'member_ref' => 'uuid-a', 'member_label' => 'Asha']);
        $d = $this->data($this->s2s('get', "manage/companies/42/licences/{$l['licence_ref']}/seats"))['data'];
        $this->assertSame(3, $d['total']);
        $this->assertSame(1, $d['assigned']);
        $this->assertSame('uuid-a', $d['items'][0]['member_ref']);
        $this->assertSame('Asha', $d['items'][0]['member_label']);
    }

    public function testActivityFeedIsCompanyScopedAndHidesInternals(): void
    {
        $l = $this->issue(42);
        $this->issue(77);
        $this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/suspend", ['version' => 1, 'reason' => 'Internal: chargeback on card']);
        $b = $this->data($this->s2s('get', 'manage/companies/42/activity', 'limit=2'));
        $this->assertSame(['suspended', 'activated'], array_column(array_column($b['data'], 'type'), 'code'));
        $this->assertSame([3, 2], array_column($b['data'], 'seq'));
        $e = $b['data'][0];
        $this->assertSame(['kind' => 'staff', 'display' => 'Aicountly team'], $e['actor']);
        $this->assertSame('Licence suspended', $e['summary']);
        $this->assertArrayNotHasKey('reason', $e);
        $this->assertStringNotContainsString('chargeback', (string) json_encode($b));
        $this->assertStringNotContainsString('sales-mgr@', (string) json_encode($b));
        $this->assertNotNull($b['page']['next_cursor']);
        $b2 = $this->data($this->s2s('get', 'manage/companies/42/activity', 'limit=2&cursor=' . $b['page']['next_cursor']));
        $this->assertSame(['issued'], array_column(array_column($b2['data'], 'type'), 'code'));
        $this->assertNull($b2['page']['next_cursor']);
        foreach ($b['data'] as $ev) {
            $this->assertSame($l['licence_ref'], $ev['licence_ref']);
        }
    }

    public function testCatalogueListsOnlyPublishedOffersOnSale(): void
    {
        $this->publishedOffer('books-annual');
        $this->publishedOffer('books-trial', ['label' => 'Trial', 'price_paise' => 0, 'sac' => null, 'term_months' => 0, 'term_days' => 30, 'display' => ['cycle_label' => '30-day trial', 'badge' => 'Try']]);
        $this->publishedOffer('books-future', ['valid_from' => '2099-01-01']);
        $this->publishedOffer('books-old', ['valid_to' => gmdate('Y-m-d', time() + 86400 * 2)]);
        $retired = $this->publishedOffer('books-retired');
        $this->asStaff($this->admin(), 'post', "v1/offers/{$retired}/retire");
        // A draft is never listed.
        $this->asStaff($this->admin(), 'post', 'v1/offers', ['offer_code' => 'books-draft', 'product_code' => 'books', 'licence_type_code' => 'standard',
            'label' => 'Draft', 'price_paise' => 1, 'price_basis' => 'inclusive', 'gst_rate' => '18']);

        $b = $this->data($this->s2s('get', 'manage/catalogue', 'cmp_id=42'));
        $codes = array_column(array_column($b['data'], 'offer'), 'code');
        sort($codes);
        $this->assertSame(['books-annual', 'books-old', 'books-trial'], $codes);
        $this->assertArrayNotHasKey('page', $b);
        $byCode = array_combine(array_column(array_column($b['data'], 'offer'), 'code'), $b['data']);
        $a = $byCode['books-annual'];
        $this->assertSame(['amount_paise' => 1180000, 'currency' => 'INR', 'basis' => 'inclusive', 'gst_rate' => '18', 'sac' => '997331'], $a['price']);
        $this->assertSame(['months' => 12, 'days' => 0, 'cycle' => '12 months'], $a['term']);
        $this->assertSame([['label' => 'Support', 'value' => 'Email']], $a['attributes']);
        $this->assertSame(['code' => 'standard', 'label' => 'Standard licence', 'lifecycle_kind' => 'paid'], $a['licence_type']);
        $this->assertSame('30-day trial', $byCode['books-trial']['term']['cycle']);
        $this->assertSame('Try', $byCode['books-trial']['display']['badge']);
    }

    public function testCatalogueWithoutCompanyCarriesIsDefault(): void
    {
        $this->publishedOffer('books-annual');
        $this->licenceType('basic', 'complimentary');
        $admin = $this->admin();
        $r = $this->asStaff($admin, 'post', 'v1/offers', ['offer_code' => 'books-basic', 'product_code' => 'books', 'licence_type_code' => 'basic',
            'label' => 'Basic', 'price_paise' => 0, 'price_basis' => 'inclusive', 'gst_rate' => '0', 'is_default' => true,
            'entitlements' => [['code' => 'max_companies', 'label' => 'Companies', 'value' => 1, 'unit' => 'count']]]);
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', 'v1/offers/' . $this->json($r)['data']['id'] . '/publish')));

        // Manage's plans_sync.php calls it with no cmp_id (contract §3.4).
        $b = $this->data($this->s2s('get', 'manage/catalogue'));
        $this->assertTrue($b['success']);
        $byCode = array_combine(array_column(array_column($b['data'], 'offer'), 'code'), $b['data']);
        $this->assertTrue($byCode['books-basic']['is_default']);
        $this->assertFalse($byCode['books-annual']['is_default']);
        $this->assertSame('complimentary', $byCode['books-basic']['licence_type']['lifecycle_kind']);
        // With cmp_id it answers the same list (unchanged behaviour).
        $this->assertSame($b['data'], $this->data($this->s2s('get', 'manage/catalogue', 'cmp_id=42'))['data']);
    }

    public function testLicenceTypesListsActiveTypesOnly(): void
    {
        $this->licenceType('standard');
        $this->licenceType('comp', 'complimentary');
        $id = (int) $this->db()->table('engage_licence_types')->where('code', 'comp')->get()->getRow()->id;
        $this->asStaff($this->admin(), 'put', "v1/licence-types/{$id}", ['active' => false]);
        $b = $this->data($this->s2s('get', 'manage/licence-types'));
        $this->assertSame(['standard'], array_column($b['data'], 'code'));
        $this->assertSame(['code', 'label', 'description', 'lifecycle_kind', 'display_schema'], array_keys($b['data'][0]));
    }

    public function testReadsAreAuditedAsManageActor(): void
    {
        $l = $this->issue(42);
        $this->s2s('get', 'manage/companies/42/licences');
        $this->s2s('get', "manage/companies/42/licences/{$l['licence_ref']}");
        $this->s2s('get', 'manage/catalogue', 'cmp_id=42');
        $rows = $this->db()->table('engage_audit_logs')->like('event', 'manage.', 'after')->orderBy('id')->get()->getResultArray();
        $this->assertSame(['manage.licences.list', 'manage.licence.read', 'manage.catalogue.read'], array_column($rows, 'event'));
        foreach ($rows as $r) {
            $this->assertSame('manage:uuid-actor-1', $r['actor_email']);
            $this->assertSame('service:manage', $r['actor_role']);
            $this->assertNull($r['actor_id']);
            $this->assertSame('42', $r['subject_id']);
            $meta = json_decode((string) $r['metadata'], true);
            $this->assertSame(self::READ_KEY_ID, $meta['key_id']);
            $this->assertNotEmpty($meta['request_id']);
        }
    }

    public function testReadsDoNotChangeAnything(): void
    {
        $l = $this->issue(42, ['starts_at' => '2024-01-01']); // ended: shown expired, stored active
        $before = $this->db()->table('engage_licences')->get()->getRowArray();
        $x = $this->data($this->s2s('get', 'manage/companies/42/licences'))['data'][0];
        $this->assertSame('expired', $x['status']['code']);
        $this->assertSame($l['licence_ref'], $this->data($this->s2s('get', 'manage/companies/42/licences', 'status=expired'))['data'][0]['licence_ref']);
        $this->assertSame([], $this->data($this->s2s('get', 'manage/companies/42/licences', 'status=active'))['data']);
        $this->assertSame($before, $this->db()->table('engage_licences')->get()->getRowArray());
    }
}
