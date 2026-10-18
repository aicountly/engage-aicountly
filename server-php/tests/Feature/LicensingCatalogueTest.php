<?php

namespace Tests\Feature;

use Tests\Support\LicensingTestCase;

/**
 * Licence types and offers (staff): nothing shipped, validation, role gates, draft → published → retired versioning,
 * published versions immutable (service and DB trigger), one published version per offer_code, audit rows.
 */
final class LicensingCatalogueTest extends LicensingTestCase
{
    public function testNothingIsShippedAndTablesStartEmpty(): void
    {
        foreach (['engage_licence_types', 'engage_offers', 'engage_licences', 'engage_account_companies', 'engage_licence_events'] as $t) {
            $this->assertSame(0, $this->db()->table($t)->countAllResults(), $t);
        }
        $r = $this->asStaff($this->staff(['member']), 'get', 'v1/offers');
        $this->assertSame(200, $this->code($r));
        $this->assertSame([], $this->json($r)['data']['items']);
    }

    public function testLicenceTypeCrudValidationAndRoles(): void
    {
        $member = $this->staff(['member'], 'member@aicountly.org');
        $admin = $this->admin();
        $this->assertSame(403, $this->code($this->asStaff($member, 'post', 'v1/licence-types', ['code' => 'x1', 'label' => 'X', 'lifecycle_kind' => 'paid'])));

        $bad = $this->asStaff($admin, 'post', 'v1/licence-types', ['code' => 'Bad Code', 'label' => 'X', 'lifecycle_kind' => 'forever']);
        $this->assertSame(422, $this->code($bad));
        $this->assertArrayHasKey('code', $this->json($bad)['details']);
        $this->assertArrayHasKey('lifecycle_kind', $this->json($bad)['details']);

        $r = $this->asStaff($admin, 'post', 'v1/licence-types', ['code' => 'trial-30', 'label' => 'Trial', 'lifecycle_kind' => 'trial', 'display_schema' => ['badge' => 'Trial']]);
        $this->assertSame(201, $this->code($r));
        $t = $this->json($r)['data'];
        $this->assertSame('trial', $t['lifecycle_kind']);
        $this->assertSame(['badge' => 'Trial'], $t['display_schema']);
        $this->assertSame(409, $this->code($this->asStaff($admin, 'post', 'v1/licence-types', ['code' => 'trial-30', 'label' => 'Again', 'lifecycle_kind' => 'trial'])));

        $u = $this->asStaff($admin, 'put', "v1/licence-types/{$t['id']}", ['label' => 'Trial (30 days)', 'lifecycle_kind' => 'complimentary', 'active' => false]);
        $this->assertSame(200, $this->code($u));
        $this->assertSame('complimentary', $this->json($u)['data']['lifecycle_kind']);
        $this->assertFalse($this->json($u)['data']['active']);
        $this->assertSame(422, $this->code($this->asStaff($admin, 'put', "v1/licence-types/{$t['id']}", ['code' => 'renamed'])));
        $this->assertSame(2, $this->db()->table('engage_audit_logs')->like('event', 'licence_type.', 'after')->countAllResults());
    }

    public function testLifecycleKindIsFrozenOnceAnOfferUsesTheType(): void
    {
        $this->publishedOffer();
        $id = (int) $this->db()->table('engage_licence_types')->where('code', 'standard')->get()->getRow()->id;
        $r = $this->asStaff($this->admin(), 'put', "v1/licence-types/{$id}", ['lifecycle_kind' => 'trial']);
        $this->assertSame(409, $this->code($r));
    }

    public function testOfferValidationNeedsEveryCommercialFieldFromStaff(): void
    {
        $this->licenceType();
        $r = $this->asStaff($this->admin(), 'post', 'v1/offers', ['offer_code' => 'x-offer', 'product_code' => 'nope', 'licence_type_code' => 'standard', 'label' => 'X']);
        $this->assertSame(422, $this->code($r));
        $d = $this->json($r)['details'];
        foreach (['product_code', 'price_paise', 'price_basis', 'gst_rate'] as $f) {
            $this->assertArrayHasKey($f, $d, $f);
        }
        $r = $this->asStaff($this->admin(), 'post', 'v1/offers', [
            'offer_code' => 'x-offer', 'product_code' => 'books', 'licence_type_code' => 'standard', 'label' => 'X offer',
            'price_paise' => 100, 'price_basis' => 'exclusive', 'gst_rate' => '101', 'entitlements' => [['code' => 'a', 'label' => 'A'], ['code' => 'a', 'label' => 'B']],
            'currency' => 'USD', 'sac' => '12', 'display' => ['attributes' => 'nope'],
        ]);
        $d = $this->json($r)['details'];
        foreach (['gst_rate', 'entitlements', 'currency', 'sac', 'display'] as $f) {
            $this->assertArrayHasKey($f, $d, $f);
        }
    }

    public function testDraftPublishVersionRetire(): void
    {
        $admin = $this->admin();
        $this->licenceType();
        $r = $this->asStaff($admin, 'post', 'v1/offers', [
            'offer_code' => 'books-annual', 'product_code' => 'books', 'licence_type_code' => 'standard', 'label' => 'Books annual',
            'price_paise' => 500000, 'price_basis' => 'inclusive', 'gst_rate' => '18', 'term_months' => 12, 'seats' => 2,
        ]);
        $this->assertSame(201, $this->code($r));
        $o = $this->json($r)['data'];
        $this->assertSame('draft', $o['status']);
        $this->assertSame(1, $o['version']);
        $this->assertSame(['books'], $o['app_keys']); // defaults to the product code
        $this->assertSame('18', $o['gst_rate']);

        // A priced offer cannot be published without a SAC.
        $p = $this->asStaff($admin, 'post', "v1/offers/{$o['id']}/publish");
        $this->assertSame(422, $this->code($p));
        $this->assertArrayHasKey('sac', $this->json($p)['details']);
        $this->assertSame(200, $this->code($this->asStaff($admin, 'put', "v1/offers/{$o['id']}", ['sac' => '997331', 'label' => 'Books annual (v1)'])));
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/offers/{$o['id']}/publish")));

        // Published: immutable through the API…
        $u = $this->asStaff($admin, 'put', "v1/offers/{$o['id']}", ['price_paise' => 1]);
        $this->assertSame(409, $this->code($u));
        $this->assertSame('offer_immutable', $this->json($u)['code']);
        $this->assertSame(409, $this->code($this->asStaff($admin, 'delete', "v1/offers/{$o['id']}")));
        $this->assertSame(409, $this->code($this->asStaff($admin, 'post', "v1/offers/{$o['id']}/publish")));

        // New version → edit → publish retires v1.
        $nv = $this->asStaff($admin, 'post', "v1/offers/{$o['id']}/new-version");
        $this->assertSame(201, $this->code($nv));
        $v2 = $this->json($nv)['data'];
        $this->assertSame(2, $v2['version']);
        $this->assertSame('draft', $v2['status']);
        $this->assertSame(409, $this->code($this->asStaff($admin, 'post', "v1/offers/{$o['id']}/new-version")), 'only one draft per code');
        $this->asStaff($admin, 'put', "v1/offers/{$v2['id']}", ['price_paise' => 600000]);
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/offers/{$v2['id']}/publish")));
        $rows = $this->json($this->asStaff($admin, 'get', 'v1/offers', ['offer_code' => 'books-annual']))['data']['items'];
        $this->assertSame([2 => 'published', 1 => 'retired'], array_column($rows, 'status', 'version'));
        $this->assertSame(500000, array_column($rows, 'price_paise', 'version')[1], 'v1 price kept');

        // Retire the published version; drafts are discarded instead.
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/offers/{$v2['id']}/retire")));
        $this->assertSame(409, $this->code($this->asStaff($admin, 'post', "v1/offers/{$v2['id']}/retire")));
        $nv3 = $this->json($this->asStaff($admin, 'post', "v1/offers/{$v2['id']}/new-version"))['data'];
        $this->assertSame(200, $this->code($this->asStaff($admin, 'delete', "v1/offers/{$nv3['id']}")));
        $this->assertSame(0, $this->db()->table('engage_offers')->where('id', $nv3['id'])->countAllResults());

        $events = array_column($this->db()->table('engage_audit_logs')->like('event', 'offer.', 'after')->get()->getResultArray(), 'event');
        foreach (['offer.created', 'offer.updated', 'offer.published', 'offer.version_created', 'offer.retired', 'offer.discarded'] as $ev) {
            $this->assertContains($ev, $events);
        }
    }

    public function testMembersCannotWriteOffers(): void
    {
        $id = $this->publishedOffer();
        $member = $this->staff(['member'], 'member@aicountly.org');
        $this->assertSame(200, $this->code($this->asStaff($member, 'get', "v1/offers/{$id}")));
        foreach (["v1/offers/{$id}/retire", "v1/offers/{$id}/new-version", "v1/offers/{$id}/publish"] as $p) {
            $this->assertSame(403, $this->code($this->asStaff($member, 'post', $p)), $p);
        }
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['seller_manager'], 'sm@aicountly.org'), 'post', 'v1/offers', ['offer_code' => 'x'])));
    }

    public function testDatabaseRefusesChangesToAPublishedOffer(): void
    {
        $id = $this->publishedOffer();
        $db = $this->db();
        foreach (["UPDATE engage_offers SET price_paise = 1 WHERE id = {$id}", "UPDATE engage_offers SET entitlements = '[]'::jsonb WHERE id = {$id}",
            "UPDATE engage_offers SET status = 'draft' WHERE id = {$id}", "DELETE FROM engage_offers WHERE id = {$id}"] as $sql) {
            try {
                $db->query($sql);
                $this->fail('Trigger allowed: ' . $sql);
            } catch (\Throwable $e) {
                $this->assertStringContainsString('engage_offers', $e->getMessage());
            }
        }
        // published → retired is the one change allowed.
        $db->query("UPDATE engage_offers SET status = 'retired', retired_at = NOW() WHERE id = {$id}");
        $this->assertSame('retired', $db->table('engage_offers')->where('id', $id)->get()->getRow()->status);
        try {
            $db->query("UPDATE engage_offers SET status = 'published' WHERE id = {$id}");
            $this->fail('retired → published allowed');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('cannot move', $e->getMessage());
        }
    }

    public function testOnlyOnePublishedVersionPerCode(): void
    {
        $id = $this->publishedOffer();
        $db = $this->db();
        $o = $db->table('engage_offers')->where('id', $id)->get()->getRowArray();
        $this->expectException(\Throwable::class);
        $db->query("INSERT INTO engage_offers (offer_code, version, product_code, licence_type_code, label, price_paise, price_basis, gst_rate, status)
            VALUES (?, 9, 'books', 'standard', 'dup', 1, 'inclusive', 18, 'published')", [$o['offer_code']]);
    }
}
