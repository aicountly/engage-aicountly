<?php

namespace Tests\Feature;

use CodeIgniter\Test\TestResponse;
use Tests\Support\CouponTestCase;

/**
 * Licences for one company, or for the whole workspace (docs/LICENSING.md §3.6): the offer says which; a company
 * licence speaks for the company it is applied to (Manage subject cmp:<id>), never for the holder's workspace; the holder
 * applies it to one of their companies, moves it, or takes it off, from Manage.
 *
 * @internal
 */
final class LicenceScopeTest extends CouponTestCase
{
    private const HOLDER = 'u-scope-1';

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureManageAll();
        $this->publishedOffer('books-annual');
        $this->publishedOffer('books-one', ['label' => 'Books — one company', 'licence_scope' => 'company', 'price_paise' => 590000]);
        $this->accountId = $this->account('Scope account');
        foreach ([42, 43] as $cmp) {
            $this->mapCompany($this->accountId, $cmp);
        }
    }

    // ------------------------------------------------------------------ fixtures

    private function issueTo(string $holder, string $offer, array $extra = []): TestResponse
    {
        return $this->asStaff($this->admin(), 'post', 'v1/licences', array_merge([
            'account_id' => $this->accountId, 'holder_uuid' => $holder, 'offer_code' => $offer, 'holder_note' => 'Owner confirmed on a call.',
        ], $extra));
    }

    /** @return array<string,mixed> the staff detail of a licence issued to $holder */
    private function issued(string $holder, string $offer, array $extra = []): array
    {
        $r = $this->issueTo($holder, $offer, $extra);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());

        return $this->json($r)['data'];
    }

    /** @return list<array<string,mixed>> outbox rows of one subject (holder:<uuid> | cmp:<id>), oldest first */
    private function pushes(string $subject): array
    {
        return array_map(static function (array $r) {
            $r['payload'] = json_decode((string) $r['payload'], true);

            return $r;
        }, $this->db()->table('engage_entitlement_outbox')->where('subject_key', $subject)->orderBy('seq')->get()->getResultArray());
    }

    /** The latest state queued for (subject, app). */
    private function lastPush(string $subject, string $app = 'books'): ?array
    {
        $rows = array_values(array_filter($this->pushes($subject), static fn ($r) => $r['app_key'] === $app));

        return $rows === [] ? null : $rows[count($rows) - 1]['payload'];
    }

    private function move(string $holder, string $ref, mixed $cmpId, array $extra = []): TestResponse
    {
        return $this->quoteCall("manage/holders/{$holder}/licences/{$ref}/company", ['cmp_id' => $cmpId, 'actor' => ['uuid' => $holder]] + $extra);
    }

    private function basicOffer(): void
    {
        $this->licenceType('basic', 'complimentary');
        $admin = $this->admin();
        $r = $this->asStaff($admin, 'post', 'v1/offers', [
            'offer_code' => 'books-basic', 'product_code' => 'books', 'licence_type_code' => 'basic', 'label' => 'Basic',
            'price_paise' => 0, 'price_basis' => 'inclusive', 'gst_rate' => '0', 'term_months' => 0, 'term_days' => 0, 'is_default' => true,
            'entitlements' => [['code' => 'max_companies', 'label' => 'Companies', 'value' => 1, 'unit' => 'count']],
        ]);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', 'v1/offers/' . $this->json($r)['data']['id'] . '/publish')));
    }

    // ------------------------------------------------------------------ offers

    public function testAnOfferSaysWhatItCoversAndBasicCoversEveryCompany(): void
    {
        $offers = $this->json($this->asStaff($this->admin(), 'get', 'v1/offers', ['status' => 'published']))['data']['items'];
        $scopes = array_column(array_map(static fn ($o) => ['c' => $o['offer_code'], 's' => $o['licence_scope']], $offers), 's', 'c');
        $this->assertSame('workspace', $scopes['books-annual']);
        $this->assertSame('company', $scopes['books-one']);

        // Manage's catalogue (read key) carries it.
        $cat = $this->json($this->s2s('get', 'manage/catalogue'))['data'];
        $this->assertSame('company', array_values(array_filter($cat, static fn ($i) => $i['offer']['code'] === 'books-one'))[0]['licence_scope']);

        // A Basic is for every company, on create and on toggling the flag.
        $r = $this->asStaff($this->admin(), 'post', 'v1/offers', [
            'offer_code' => 'books-free-one', 'product_code' => 'books', 'licence_type_code' => 'standard', 'label' => 'Free one',
            'price_paise' => 0, 'price_basis' => 'inclusive', 'gst_rate' => '0', 'term_months' => 0, 'licence_scope' => 'company', 'is_default' => true,
        ]);
        $this->assertSame(422, $this->code($r));
        $this->assertArrayHasKey('licence_scope', $this->json($r)['details']);
        $this->assertSame(422, $this->code($this->asStaff($this->admin(), 'post', 'v1/offers', [
            'offer_code' => 'books-odd', 'product_code' => 'books', 'licence_type_code' => 'standard', 'label' => 'Odd', 'price_paise' => 100,
            'price_basis' => 'inclusive', 'gst_rate' => '18', 'sac' => '997331', 'licence_scope' => 'team',
        ])));

        // A published version never changes what it covers; every version of an offer covers the same.
        $id = (int) $this->db()->table('engage_offers')->where('offer_code', 'books-one')->get()->getRow()->id;
        $this->expectDbError(fn () => $this->db()->query("UPDATE engage_offers SET licence_scope = 'workspace' WHERE id = ?", [$id]));
        $v2 = $this->json($this->asStaff($this->admin(), 'post', "v1/offers/{$id}/new-version"))['data'];
        $this->assertSame('company', $v2['licence_scope']);
        $r = $this->asStaff($this->admin(), 'put', "v1/offers/{$v2['id']}", ['licence_scope' => 'workspace']);
        $this->assertSame(422, $this->code($r), (string) $r->response()->getBody());
        $this->assertArrayHasKey('licence_scope', $this->json($r)['details']);
    }

    private function expectDbError(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());

            return;
        }
        $this->fail('The published offer was changed.');
    }

    // ------------------------------------------------------------------ issue + projection

    public function testACompanyLicenceSpeaksForItsCompanyNotTheWorkspace(): void
    {
        $l = $this->issued(self::HOLDER, 'books-one', ['cmp_id' => 42]);
        $this->assertSame('company', $l['scope']);
        $this->assertSame(42, $l['cmp_id']);

        $p = $this->lastPush('cmp:42');
        $this->assertNotNull($p);
        $this->assertNull($p['holder_uuid']);
        $this->assertSame(42, $p['cmp_id']);
        $this->assertSame('company', $p['scope']);
        $this->assertSame(self::HOLDER, $p['licence_holder_uuid']);
        $this->assertSame('active', $p['status']);
        $this->assertSame($l['licence_ref'], $p['licence_ref']);
        $this->assertNull($this->lastPush('holder:' . self::HOLDER), 'a company licence says nothing about the holder\'s other companies');

        // What applies to company 42 includes it; company 43 does not see it; the holder sees everything they hold.
        $refs = fn (string $q) => array_column($this->json($this->s2s('get', 'manage/holders/' . self::HOLDER . '/licences', $q))['data'], 'licence_ref');
        $this->assertSame([$l['licence_ref']], $refs('cmp_id=42'));
        $this->assertSame([], $refs('cmp_id=43'));
        $this->assertSame([$l['licence_ref']], $refs(''));
        $v = $this->json($this->s2s('get', 'manage/holders/' . self::HOLDER . '/licences/' . $l['licence_ref']))['data'];
        $this->assertSame(['company', 42], [$v['scope'], $v['cmp_id']]);
    }

    public function testAWorkspaceLicenceAndACompanyLicenceLiveSideBySide(): void
    {
        $w = $this->issued(self::HOLDER, 'books-annual', ['cmp_id' => 43]); // 43: only the company the sale was recorded against
        $this->assertSame(['workspace', null], [$w['scope'] ?? null, $this->json($this->s2s('get', 'manage/holders/' . self::HOLDER . '/licences/' . $w['licence_ref']))['data']['cmp_id']]);
        $this->assertSame('workspace', $this->lastPush('holder:' . self::HOLDER)['scope']);
        $this->assertNull($this->lastPush('cmp:43'), 'a workspace licence is not a company licence of the company it was sold through');

        $c = $this->issued(self::HOLDER, 'books-one', ['cmp_id' => 42]);
        $this->assertSame('active', $this->lastPush('cmp:42')['status']);

        // One live licence per (holder, product) among workspace licences, per (company, product) among company licences.
        $this->assertSame(409, $this->code($this->issueTo(self::HOLDER, 'books-annual')));
        $dup = $this->issueTo(self::HOLDER, 'books-one', ['cmp_id' => 42]);
        $this->assertSame(409, $this->code($dup));
        $this->assertSame($c['licence_ref'], $this->json($dup)['details']['licence_ref']);
        $this->assertSame(201, $this->code($this->issueTo(self::HOLDER, 'books-one', ['cmp_id' => 43])));
        $this->assertSame(201, $this->code($this->issueTo(self::HOLDER, 'books-one')), 'not applied to a company: nothing to clash with');

        // What applies to 42: the workspace licence and 42's company licence, not 43's.
        $refs = array_column($this->json($this->s2s('get', 'manage/holders/' . self::HOLDER . '/licences', 'cmp_id=42'))['data'], 'licence_ref');
        sort($refs);
        $expect = [$w['licence_ref'], $c['licence_ref']];
        sort($expect);
        $this->assertSame($expect, $refs);
    }

    public function testOnlyAWorkspaceLicencePausesTheBasic(): void
    {
        $this->basicOffer();
        $b = $this->issued(self::HOLDER, 'books-basic');
        $this->issued(self::HOLDER, 'books-one', ['cmp_id' => 42]);
        $this->assertSame('active', $this->db()->table('engage_licences')->where('licence_ref', $b['licence_ref'])->get()->getRow()->status,
            'the holder\'s other companies still need Basic');
        $this->assertTrue($this->lastPush('holder:' . self::HOLDER)['plan']['is_default']);

        $this->issued(self::HOLDER, 'books-annual');
        $this->assertSame('superseded', $this->db()->table('engage_licences')->where('licence_ref', $b['licence_ref'])->get()->getRow()->status);

        // Basic cannot be moved, and a workspace licence has nowhere to move to.
        $this->assertSame('basic_not_movable', $this->json($this->move(self::HOLDER, $b['licence_ref'], 42))['error']['code'] ?? null);
    }

    // ------------------------------------------------------------------ apply / move / take off (Manage)

    public function testTheHolderAppliesMovesAndTakesOffACompanyLicence(): void
    {
        $l = $this->issued(self::HOLDER, 'books-one');
        $this->assertSame(['company', null], [$l['scope'], $l['cmp_id']]);
        $this->assertSame([], $this->db()->table('engage_entitlement_outbox')->where('licence_ref', $l['licence_ref'])->get()->getResultArray(),
            'a licence not applied to a company grants nothing yet');
        $this->assertStringContainsString('not applied to a company yet', $l['events'][count($l['events']) - 1]['summary'] ?? $l['events'][0]['summary']);

        $r = $this->move(self::HOLDER, $l['licence_ref'], 55, ['company_name' => 'Asha Traders']);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $d = $this->json($r);
        $this->assertTrue($d['success']);
        $this->assertSame(['company', 55], [$d['data']['scope'], $d['data']['cmp_id']]);
        $this->assertSame('active', $this->lastPush('cmp:55')['status']);
        $this->assertSame(self::HOLDER, $this->lastPush('cmp:55')['licence_holder_uuid']);

        $ev = $this->json($this->s2s('get', 'manage/holders/' . self::HOLDER . '/activity', 'licence_ref=' . $l['licence_ref']))['data'][0];
        $this->assertSame('company_changed', $ev['type']['code']);
        $this->assertSame(['kind' => 'customer', 'display' => 'Account owner'], $ev['actor']);
        $this->assertStringContainsString('Asha Traders (company 55)', $ev['summary']);

        // Moved: 55 falls back (status none), 56 gets it.
        $this->assertSame(200, $this->code($this->move(self::HOLDER, $l['licence_ref'], 56)));
        $this->assertSame('none', $this->lastPush('cmp:55')['status']);
        $this->assertSame('active', $this->lastPush('cmp:56')['status']);
        $this->assertSame('same_company', $this->json($this->move(self::HOLDER, $l['licence_ref'], 56))['error']['code']);

        // Taken off: 56 falls back; the licence waits to be applied again.
        $r = $this->move(self::HOLDER, $l['licence_ref'], null);
        $this->assertSame(200, $this->code($r));
        $this->assertNull($this->json($r)['data']['cmp_id']);
        $this->assertSame('none', $this->lastPush('cmp:56')['status']);
        $this->assertSame('same_company', $this->json($this->move(self::HOLDER, $l['licence_ref'], null))['error']['code']);
        $this->assertSame(4, (int) $this->db()->table('engage_licences')->where('licence_ref', $l['licence_ref'])->get()->getRow()->version);
    }

    public function testWhatTheHolderCannotMove(): void
    {
        $w = $this->issued(self::HOLDER, 'books-annual');
        $this->assertSame('not_company_licence', $this->json($this->move(self::HOLDER, $w['licence_ref'], 42))['error']['code']);

        $a = $this->issued(self::HOLDER, 'books-one', ['cmp_id' => 42]);
        $b = $this->issued(self::HOLDER, 'books-one');
        $r = $this->move(self::HOLDER, $b['licence_ref'], 42);
        $this->assertSame(409, $this->code($r));
        $this->assertSame('company_has_licence', $this->json($r)['error']['code']);
        $this->assertSame($a['licence_ref'], $this->json($r)['error']['details']['licence_ref']);

        // Somebody else's licence answers like a missing one; so does a malformed ref.
        $this->assertSame(404, $this->code($this->move('u-somebody-else', $a['licence_ref'], 43)));
        $this->assertSame(404, $this->code($this->move(self::HOLDER, 'LIC-999999', 43)));

        // The body must say which company.
        $r = $this->quoteCall('manage/holders/' . self::HOLDER . "/licences/{$b['licence_ref']}/company", ['actor' => ['uuid' => self::HOLDER]]);
        $this->assertSame(422, $this->code($r));
        foreach (['abc', 0, -4, 1.5] as $bad) {
            $this->assertSame(422, $this->code($this->move(self::HOLDER, $b['licence_ref'], $bad)), (string) json_encode($bad));
        }

        // A revoked licence is not applied anywhere any more.
        $row = $this->db()->table('engage_licences')->where('licence_ref', $b['licence_ref'])->get()->getRow();
        $this->assertSame(200, $this->code($this->asStaff($this->admin(), 'post', "v1/licences/{$b['licence_ref']}/revoke", ['version' => (int) $row->version, 'reason' => 'Refunded'])));
        $this->assertSame('licence_not_live', $this->json($this->move(self::HOLDER, $b['licence_ref'], 43))['error']['code']);

        // Only the quote key may write: the read key is refused.
        $r = $this->s2s('POST', 'manage/holders/' . self::HOLDER . "/licences/{$a['licence_ref']}/company", '', [], json_encode(['cmp_id' => 43]));
        $this->assertSame(403, $this->code($r));
        $this->assertSame(42, (int) $this->db()->table('engage_licences')->where('licence_ref', $a['licence_ref'])->get()->getRow()->cmp_id);
    }

    // ------------------------------------------------------------------ staff

    public function testStaffMoveACompanyLicenceOnlyToACompanyOfItsAccount(): void
    {
        $l = $this->issued(self::HOLDER, 'books-one', ['cmp_id' => 42]);
        $v = (int) $l['version'];
        $r = $this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/company", ['version' => $v, 'cmp_id' => 99, 'reason' => 'Asked by the owner']);
        $this->assertSame(422, $this->code($r), 'company 99 is not mapped to the licence\'s account');
        $r = $this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/company", ['version' => $v, 'cmp_id' => 43, 'reason' => 'Asked by the owner']);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $d = $this->json($r)['data'];
        $this->assertSame(43, $d['cmp_id']);
        $last = $d['events'][count($d['events']) - 1]['type']['code'] === 'company_changed' ? $d['events'][count($d['events']) - 1] : $d['events'][0];
        $this->assertSame('company_changed', $last['type']['code']);
        $this->assertStringContainsString('Acme Pvt Ltd (company 43)', $last['summary']);
        $this->assertSame('none', $this->lastPush('cmp:42')['status']);
        $this->assertSame('active', $this->lastPush('cmp:43')['status']);
        // Stale version: refused.
        $this->assertSame(409, $this->code($this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/company", ['version' => $v, 'cmp_id' => 42])));
    }

    public function testAPlanChangeKeepsWhatTheLicenceCovers(): void
    {
        $l = $this->issued(self::HOLDER, 'books-annual');
        $r = $this->asStaff($this->admin(), 'post', "v1/licences/{$l['licence_ref']}/change-offer", ['version' => (int) $l['version'], 'offer_code' => 'books-one']);
        $this->assertSame(422, $this->code($r));
        $this->assertArrayHasKey('offer_code', $this->json($r)['details']);
    }

    public function testALegacyCompanyLicenceIsACompanyLicence(): void
    {
        // Issued to a company with no holder (Phase A): it has always applied to its one company.
        $l = $this->issue(42);
        $this->assertSame(['company', 42], [$l['scope'], $l['cmp_id']]);
        $this->assertNull($this->lastPush('cmp:42')['licence_holder_uuid']);
        // A row written without a scope (an older code path) is consistent all the same.
        $this->db()->query("UPDATE engage_licences SET scope = 'workspace' WHERE licence_ref = ?", [$l['licence_ref']]);
        $this->assertSame('company', $this->db()->table('engage_licences')->where('licence_ref', $l['licence_ref'])->get()->getRow()->scope);
    }
}
