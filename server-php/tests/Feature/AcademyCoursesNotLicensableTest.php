<?php

namespace Tests\Feature;

use App\Database\Migrations\RetireAcademyCourseLicensing;
use Config\Database;
use Tests\Support\LicensingTestCase;

/**
 * Aicountly Edge and Aicountly Aspire are Aicountly Academy courses, never licensed (owner decision 2026-10-24;
 * docs/LICENSING.md § Aicountly Academy courses): no offer can be created or published for a course product, no
 * licence issued or renewed for one, the Manage read API (v1/manage/*) never returns a course licence, its seats or
 * events, or a course offer — and migration 2026-10-24-000020 retires the offers Phase 5 may have left behind,
 * skips their pending pushes and leaves the licences as they are. The course product rows themselves stay.
 */
final class AcademyCoursesNotLicensableTest extends LicensingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureManage();
    }

    /** A course offer as Phase 5 could have stored it (written directly: the API no longer allows it). */
    private function legacyOffer(string $code, string $product, array $appKeys, bool $publish, bool $isDefault = false): int
    {
        $db = $this->db();
        $id = (int) $db->query(
            "INSERT INTO engage_offers (offer_code, version, product_code, licence_type_code, label, price_paise, currency, price_basis,
                gst_rate, term_months, term_days, seats, app_keys, entitlements, display, status, is_default)
             VALUES (?, 1, ?, 'standard', ?, 0, 'INR', 'inclusive', 0, 12, 0, NULL, ?::text[], '[]'::jsonb, '{}'::jsonb, 'draft', ?) RETURNING id",
            [$code, $product, 'Legacy ' . $code, '{' . implode(',', $appKeys) . '}', $isDefault]
        )->getRow()->id;
        if ($publish) {
            $db->query("UPDATE engage_offers SET status = 'published', published_at = NOW() WHERE id = ?", [$id]);
        }

        return $id;
    }

    /** A licence of a course issued before the decision, with one event, for company $cmp. */
    private function legacyLicence(int $offerId, string $ref, string $product, int $cmp, int $accountId): void
    {
        $db = $this->db();
        $lid = (int) $db->query(
            "INSERT INTO engage_licences (licence_ref, cmp_id, account_id, offer_id, offer_code, offer_version, offer_snapshot, product_code,
                licence_type_code, app_keys, starts_at, ends_at, status, source, version, activated_at)
             SELECT ?, ?, ?, o.id, o.offer_code, o.version, '{}'::jsonb, ?, 'standard', ?::text[], NOW() - INTERVAL '1 day', NOW() + INTERVAL '1 year',
                'active', 'staff', 1, NOW() FROM engage_offers o WHERE o.id = ? RETURNING id",
            [$ref, $cmp, $accountId, $product, '{' . $product . '}', $offerId]
        )->getRow()->id;
        $db->query(
            "INSERT INTO engage_licence_events (event_id, licence_id, licence_ref, cmp_id, seq, type, actor_kind, actor_display, summary, changes, licence_version)
             VALUES (?, ?, ?, ?, 0, 'issued', 'system', 'Engage', 'Licence issued (legacy course)', '{}'::jsonb, 1)",
            ['lev_' . bin2hex(random_bytes(12)), $lid, $ref, $cmp]
        );
    }

    private function migrate(): void
    {
        (new RetireAcademyCourseLicensing(Database::forge('tests')))->up();
    }

    public function testNoOfferCanBeCreatedOrPublishedForACourse(): void
    {
        $this->licenceType();
        $admin = $this->admin();
        $base = ['licence_type_code' => 'standard', 'label' => 'Course plan', 'price_paise' => 0, 'price_basis' => 'inclusive', 'gst_rate' => '0',
            'term_months' => 12, 'term_days' => 0];
        foreach (['edge', 'aspire', 'academy'] as $course) {
            $r = $this->asStaff($admin, 'post', 'v1/offers', $base + ['offer_code' => "{$course}-annual", 'product_code' => $course]);
            $this->assertSame(422, $this->code($r), $course);
            $this->assertStringContainsString('Aicountly Academy course', $this->json($r)['details']['product_code'] ?? '', $course);
        }
        // Another product may not license a course either, nor carry a course entitlement code.
        $r = $this->asStaff($admin, 'post', 'v1/offers', $base + ['offer_code' => 'books-edge', 'product_code' => 'books', 'app_keys' => ['books', 'edge']]);
        $this->assertSame(422, $this->code($r));
        $this->assertStringContainsString('edge', $this->json($r)['details']['app_keys'] ?? '');
        $r = $this->asStaff($admin, 'post', 'v1/offers', $base + ['offer_code' => 'books-learners', 'product_code' => 'books',
            'entitlements' => [['code' => 'aspire.max_workshops_per_month', 'label' => 'Workshops', 'value' => 4]]]);
        $this->assertSame(422, $this->code($r));
        $this->assertSame(0, $this->db()->table('engage_offers')->countAllResults());

        // A draft left from Phase 5 cannot be published or versioned.
        $draft = $this->legacyOffer('edge-basic', 'edge', ['edge'], false, true);
        $r = $this->asStaff($admin, 'post', "v1/offers/{$draft}/publish");
        $this->assertSame(422, $this->code($r));
        $this->assertSame('course_not_licensable', $this->json($r)['code'] ?? null);
        $pub = $this->legacyOffer('aspire-annual', 'aspire', ['aspire'], true);
        $r = $this->asStaff($admin, 'post', "v1/offers/{$pub}/new-version");
        $this->assertSame(422, $this->code($r), (string) $r->response()->getBody());
        $this->assertSame('course_not_licensable', $this->json($r)['code'] ?? null);
        $this->assertSame(0, $this->db()->table('engage_offers')->where('offer_code', 'aspire-annual')->where('version', 2)->countAllResults());

        // The course products stay (leads, fee payments, commission).
        $this->assertSame(['academy', 'aspire', 'edge'], array_column($this->db()->table('engage_products')->where('category', 'Academy')->orderBy('code')->get()->getResultArray(), 'code'));
    }

    public function testManageReadsNeverReturnCourseLicencesOrOffers(): void
    {
        $books = $this->issue(42);
        $accountId = (int) $this->db()->table('engage_account_companies')->where('cmp_id', 42)->get()->getRow()->account_id;
        $edgeOffer = $this->legacyOffer('edge-annual', 'edge', ['edge'], true);
        $aspireOffer = $this->legacyOffer('aspire-basic', 'aspire', ['aspire'], true, true);
        $bundle = $this->legacyOffer('books-plus-edge', 'books', ['books', 'edge'], true);
        $this->legacyLicence($edgeOffer, 'LIC-900001', 'edge', 42, $accountId);
        $this->legacyLicence($aspireOffer, 'LIC-900002', 'aspire', 42, $accountId);

        $s2s = function (string $path, string $query = ''): array {
            $r = $this->s2s('get', $path, $query);

            return [$this->code($r), $this->json($r)];
        };
        [$st, $b] = $s2s('manage/companies/42/licences');
        $this->assertSame(200, $st);
        $this->assertSame([$books['licence_ref']], array_column($b['data'], 'licence_ref'), 'only the books licence');
        foreach (['edge', 'aspire'] as $p) {
            [$st, $b] = $s2s('manage/companies/42/licences', "product_code={$p}");
            $this->assertSame([], $b['data'], $p);
        }
        foreach (['LIC-900001', 'LIC-900002'] as $ref) {
            foreach (["manage/companies/42/licences/{$ref}", "manage/companies/42/licences/{$ref}/seats"] as $path) {
                [$st, $b] = $s2s($path);
                $this->assertSame(404, $st, $path);
                $this->assertSame('not_found', $b['error']['code'] ?? null, $path);
            }
        }
        [$st, $b] = $s2s('manage/companies/42/activity');
        $this->assertSame(200, $st);
        $refs = array_values(array_unique(array_column($b['data'], 'licence_ref')));
        $this->assertSame([$books['licence_ref']], $refs, 'no course licence events');

        [$st, $b] = $s2s('manage/catalogue');
        $this->assertSame(200, $st);
        $codes = array_map(static fn (array $o): string => $o['offer']['code'], $b['data']);
        $this->assertContains('books-annual', $codes);
        foreach (['edge-annual', 'aspire-basic', 'books-plus-edge'] as $c) {
            $this->assertNotContains($c, $codes, $c);
        }
        foreach ($b['data'] as $o) {
            $this->assertNotContains($o['product']['code'], ['academy', 'edge', 'aspire']);
            $this->assertSame([], array_intersect($o['app_keys'], ['academy', 'edge', 'aspire']));
        }
        $this->assertNotNull($bundle);
    }

    public function testNoLicenceCanBeIssuedOrRenewedForACourse(): void
    {
        $this->issue(42);
        $accountId = (int) $this->db()->table('engage_account_companies')->where('cmp_id', 42)->get()->getRow()->account_id;
        $edgeOffer = $this->legacyOffer('edge-annual', 'edge', ['edge'], true);
        $this->legacyLicence($edgeOffer, 'LIC-900001', 'edge', 42, $accountId);
        $admin = $this->admin();

        $r = $this->asStaff($admin, 'post', 'v1/licences', ['account_id' => $accountId, 'cmp_id' => 42, 'offer_code' => 'edge-annual']);
        $this->assertSame(422, $this->code($r));
        $this->assertStringContainsString('Aicountly Academy course', $this->json($r)['details']['offer_code'] ?? '');
        $this->assertSame(1, $this->db()->table('engage_licences')->where('product_code', 'edge')->countAllResults());

        foreach (['renew' => ['version' => 1], 'extend' => ['version' => 1, 'ends_at' => gmdate('Y-m-d', strtotime('+2 years')), 'reason' => 'More time'], 'change-offer' => ['version' => 1, 'offer_code' => 'books-annual']] as $action => $body) {
            $r = $this->asStaff($admin, 'post', "v1/licences/LIC-900001/{$action}", $body);
            $this->assertSame(409, $this->code($r), $action . ' ' . (string) $r->response()->getBody());
            $this->assertSame('course_not_licensable', $this->json($r)['code'] ?? null, $action);
        }
        $l = $this->db()->table('engage_licences')->where('licence_ref', 'LIC-900001')->get()->getRowArray();
        $this->assertSame(['active', 1], [$l['status'], (int) $l['version']], 'unchanged');

        // Winding it down is still possible.
        $r = $this->asStaff($admin, 'post', 'v1/licences/LIC-900001/revoke', ['version' => 1, 'reason' => 'Aicountly Academy course: not licensed']);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $this->assertSame(0, $this->db()->table('engage_entitlement_outbox')->where('app_key', 'edge')->countAllResults(), 'no push for a course key');
        // Manage answers 422 unknown_application to an edge / aspire push: the outbox never queues one.
        $this->assertSame([], (new \App\Services\Licensing\EntitlementOutbox($this->db()))->enqueue(null, 42, ['edge', 'aspire', 'academy', 'EDGE'], 'test', 'LIC-900001', true));
        $this->assertSame(0, $this->db()->table('engage_entitlement_outbox')->whereIn('app_key', ['academy', 'edge', 'aspire'])->countAllResults());
    }

    public function testMigrationRetiresCourseOffersSkipsPushesAndKeepsLicences(): void
    {
        $books = $this->issue(42);
        $accountId = (int) $this->db()->table('engage_account_companies')->where('cmp_id', 42)->get()->getRow()->account_id;
        $edgePub = $this->legacyOffer('edge-annual', 'edge', ['edge'], true);
        $aspireDefault = $this->legacyOffer('aspire-basic', 'aspire', ['aspire'], true, true);
        $edgeDraft = $this->legacyOffer('edge-basic', 'edge', ['edge'], false, true);
        $bundlePub = $this->legacyOffer('books-plus-edge', 'books', ['books', 'edge'], true);
        $bundleDraft = $this->legacyOffer('books-plus-aspire', 'books', ['books', 'aspire'], false);
        $this->legacyLicence($edgePub, 'LIC-900001', 'edge', 42, $accountId);
        $db = $this->db();
        $db->query("INSERT INTO engage_entitlement_outbox (event_id, subject_key, cmp_id, app_key, seq, reason, payload, state_hash, status)
            VALUES ('ent_legacy_edge', 'cmp:42', 42, 'edge', 9001, 'licence.issued', '{}', ?, 'pending')", [str_repeat('a', 64)]);
        $licBefore = $db->query('SELECT * FROM engage_licences ORDER BY id')->getResultArray();

        $this->migrate();

        $status = static fn (int $id) => Database::connect('tests')->query('SELECT status, retired_at, app_keys FROM engage_offers WHERE id = ?', [$id])->getRowArray();
        foreach ([$edgePub, $aspireDefault, $edgeDraft, $bundlePub] as $id) {
            $o = $status($id);
            $this->assertSame('retired', $o['status'], (string) $id);
            $this->assertNotNull($o['retired_at']);
        }
        $this->assertSame(['draft', '{books}'], [$status($bundleDraft)['status'], $status($bundleDraft)['app_keys']], 'a draft of another product only loses the course key');
        $this->assertSame('published', $db->table('engage_offers')->where('offer_code', 'books-annual')->get()->getRow()->status, 'other offers untouched');
        $this->assertSame(1, $db->table('engage_offers')->where('id', $edgeDraft)->countAllResults(), 'retired, not deleted');
        $ob = $db->table('engage_entitlement_outbox')->where('event_id', 'ent_legacy_edge')->get()->getRowArray();
        $this->assertSame('skipped', $ob['status']);
        $this->assertStringContainsString('Aicountly Academy course', (string) $ob['last_error']);
        $this->assertSame($licBefore, $db->query('SELECT * FROM engage_licences ORDER BY id')->getResultArray(), 'licences are not changed');
        $this->assertSame(4, $db->table('engage_audit_logs')->where('event', 'offer.retired')->where('actor_email', 'system:engage')->countAllResults());

        // Idempotent: nothing more to do.
        $offersBefore = $db->query('SELECT id, status, app_keys, retired_at, updated_at FROM engage_offers ORDER BY id')->getResultArray();
        $this->migrate();
        $this->assertSame($offersBefore, $db->query('SELECT id, status, app_keys, retired_at, updated_at FROM engage_offers ORDER BY id')->getResultArray());
        $this->assertSame(4, $db->table('engage_audit_logs')->where('event', 'offer.retired')->where('actor_email', 'system:engage')->countAllResults());

        // The books licence still reads in Manage; the course licence does not.
        $r = $this->s2s('get', 'manage/companies/42/licences');
        $this->assertSame([$books['licence_ref']], array_column($this->json($r)['data'], 'licence_ref'));
    }
}
