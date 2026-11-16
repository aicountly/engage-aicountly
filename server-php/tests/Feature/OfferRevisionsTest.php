<?php

namespace Tests\Feature;

use App\Services\Licensing\OfferService;
use Config\Services;
use Tests\Support\LicensingTestCase;

/**
 * Price revisions "with effect from" and sales channels (docs/SELLING_LICENCES.md §4, §5): a future-dated version is
 * scheduled, the current price stays on sale until the effective instant, every read switches at that instant, the
 * job records it, a quote keeps its price, and channels decide where an offer is listed.
 *
 * @internal
 */
final class OfferRevisionsTest extends LicensingTestCase
{
    private function tomorrow(): string
    {
        return (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }

    /** Move a scheduled version's effective date into the past (the guard forbids it outside a fixture). */
    private function reachEffectiveDate(int $offerId): void
    {
        $db = $this->db();
        $db->query('ALTER TABLE engage_offers DISABLE TRIGGER trg_engage_offers_guard');
        $db->query("UPDATE engage_offers SET valid_from = NOW() - INTERVAL '1 minute' WHERE id = ?", [$offerId]);
        $db->query('ALTER TABLE engage_offers ENABLE TRIGGER trg_engage_offers_guard');
    }

    private function revise(int $id, array $body): \CodeIgniter\Test\TestResponse
    {
        return $this->asStaff($this->admin(), 'post', "v1/offers/{$id}/revise", $body);
    }

    private function scheduledRevision(int $v1, int $price = 1416000): int
    {
        $r = $this->revise($v1, ['price_paise' => $price, 'effective_from' => $this->tomorrow(), 'note' => 'Annual price revision FY27']);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $this->assertSame('scheduled', $this->json($r)['data']['status']);

        return (int) $this->json($r)['data']['id'];
    }

    public function testRevisionWithAFutureDateIsScheduledAndTheCurrentPriceStaysOnSale(): void
    {
        $this->configureManage();
        $v1 = $this->publishedOffer();
        $v2 = $this->scheduledRevision($v1);

        $offers = Services::offers();
        $this->assertSame($v1, (int) $offers->publishedVersion('books-annual')['id']);
        $this->assertSame('published', $offers->findRow($v1)['status']);
        $this->assertTrue(OfferService::isOnSale($offers->findRow($v1)));
        $this->assertFalse(OfferService::isOnSale($offers->findRow($v2)));

        // Manage sees one card for the code — the price on sale now — with the revision announced beside it.
        $cat = $this->json($this->s2s('get', 'manage/catalogue'))['data'];
        $this->assertCount(1, $cat);
        $this->assertSame(['code' => 'books-annual', 'version' => 1], $cat[0]['offer']);
        $this->assertSame(1180000, $cat[0]['price']['amount_paise']);
        $this->assertSame(2, $cat[0]['upcoming']['version']);
        $this->assertSame(1416000, $cat[0]['upcoming']['price']['amount_paise']);

        // A licence issued now is sold at today's price.
        $l = $this->issue();
        $this->assertSame(1, $l['offer']['version']);

        $h = $this->json($this->asStaff($this->admin(), 'get', 'v1/offers/price-history/books-annual'))['data'];
        $this->assertSame(['id' => $v2, 'version' => 2, 'effective_from' => $h['versions'][0]['effective_from']], $h['scheduled']);
        $this->assertSame('Annual price revision FY27', $h['versions'][0]['revision_note']);
        $this->assertSame(1, $h['current']['version']);
    }

    public function testAtTheEffectiveInstantEveryReadSwitchesBeforeTheJobRuns(): void
    {
        $this->configureManage();
        $v1 = $this->publishedOffer();
        $v2 = $this->scheduledRevision($v1);
        $this->reachEffectiveDate($v2);

        $offers = Services::offers();
        $this->assertSame($v2, (int) $offers->publishedVersion('books-annual')['id']);
        $this->assertFalse(OfferService::isOnSale($offers->findRow($v1)), 'the old price is superseded at the effective date');
        $this->assertTrue(OfferService::isOnSale($offers->findRow($v2)));
        $this->assertSame('scheduled', $offers->findRow($v2)['status'], 'nothing recorded yet');

        $cat = $this->json($this->s2s('get', 'manage/catalogue'))['data'];
        $this->assertSame([['code' => 'books-annual', 'version' => 2]], array_column($cat, 'offer'));
        $this->assertNull($cat[0]['upcoming']);

        $l = $this->issue();
        $this->assertSame(2, $l['offer']['version']);
        $this->assertSame(1416000, $l['offer_snapshot']['price_paise']);
    }

    public function testTheJobRecordsTheRevisionAtItsEffectiveDateAndIsIdempotent(): void
    {
        $v1 = $this->publishedOffer();
        $v2 = $this->scheduledRevision($v1);
        $this->reachEffectiveDate($v2);
        $wef = $this->db()->table('engage_offers')->select('valid_from')->where('id', $v2)->get()->getRow()->valid_from;

        $r = Services::offers()->activateDue();
        $this->assertSame([], $r['failed']);
        $this->assertCount(1, $r['activated']);
        $this->assertSame(['offer_code' => 'books-annual', 'version' => 2, 'replaced_version' => 1],
            array_intersect_key($r['activated'][0], array_flip(['offer_code', 'version', 'replaced_version'])));

        $old = $this->db()->table('engage_offers')->where('id', $v1)->get()->getRowArray();
        $new = $this->db()->table('engage_offers')->where('id', $v2)->get()->getRowArray();
        $this->assertSame('retired', $old['status']);
        $this->assertSame(strtotime((string) $wef), strtotime((string) $old['retired_at']), 'retired exactly at the effective date');
        $this->assertStringContainsString('Replaced by v2', (string) $old['retired_reason']);
        $this->assertSame('published', $new['status']);
        $this->assertSame(strtotime((string) $wef), strtotime((string) $new['published_at']));

        $this->assertSame(['activated' => [], 'failed' => []], Services::offers()->activateDue());
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) AS n FROM engage_audit_logs WHERE event = 'offer.revision_effective'")->getRow()->n);
    }

    public function testReviseValidates(): void
    {
        $v1 = $this->publishedOffer();
        $past = (new \DateTimeImmutable('-3 days'))->format('Y-m-d');
        foreach ([
            [['price_paise' => 1416000, 'effective_from' => $past, 'note' => 'Too late'], 'effective_from'],
            [['price_paise' => 1416000, 'effective_from' => $this->tomorrow()], 'note'],
            [['price_paise' => -1, 'effective_from' => $this->tomorrow(), 'note' => 'Negative'], 'price_paise'],
            [['price_paise' => 1180000, 'effective_from' => $this->tomorrow(), 'note' => 'Same price'], 'price_paise'],
            [['price_paise' => 1416000, 'gst_rate' => '180', 'effective_from' => $this->tomorrow(), 'note' => 'Bad rate'], 'gst_rate'],
        ] as [$body, $field]) {
            $r = $this->revise($v1, $body);
            $this->assertSame(422, $this->code($r), $field . ': ' . $r->response()->getBody());
            $this->assertArrayHasKey($field, $this->json($r)['details']);
        }
        $this->assertSame(1, $this->db()->table('engage_offers')->where('offer_code', 'books-annual')->countAllResults(), 'nothing left behind');

        // Only the version in effect can be revised.
        $v2 = $this->scheduledRevision($v1);
        $this->assertSame(409, $this->code($this->revise($v2, ['price_paise' => 1, 'effective_from' => $this->tomorrow(), 'note' => 'Not current'])));
        // One pending revision per offer.
        $r = $this->revise($v1, ['price_paise' => 1500000, 'effective_from' => $this->tomorrow(), 'note' => 'Second one']);
        $this->assertSame(409, $this->code($r));
        $this->assertSame('revision_pending', $this->json($r)['code']);
        $this->assertSame(2, $this->db()->table('engage_offers')->where('offer_code', 'books-annual')->countAllResults());
    }

    public function testRevisionEffectiveTodayGoesOnSaleAtOnce(): void
    {
        $v1 = $this->publishedOffer();
        $r = $this->revise($v1, ['price_paise' => 999900, 'price_basis' => 'exclusive', 'effective_from' => $this->today(), 'note' => 'Launch price']);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $d = $this->json($r)['data'];
        $this->assertSame('published', $d['status']);
        $this->assertSame('exclusive', $d['price_basis']);
        $this->assertTrue($d['on_sale_now']);
        $this->assertSame('retired', $this->db()->table('engage_offers')->where('id', $v1)->get()->getRow()->status);
        $this->assertSame((int) $d['id'], (int) Services::offers()->publishedVersion('books-annual')['id']);
    }

    public function testAScheduledRevisionCanBeCancelledUntilItTakesEffect(): void
    {
        $v1 = $this->publishedOffer();
        $v2 = $this->scheduledRevision($v1);
        $admin = $this->admin();
        $this->assertSame(422, $this->code($this->asStaff($admin, 'post', "v1/offers/{$v2}/cancel-schedule", ['reason' => ''])));
        $r = $this->asStaff($admin, 'post', "v1/offers/{$v2}/cancel-schedule", ['reason' => 'Board asked to hold prices']);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $this->assertSame('retired', $this->json($r)['data']['status']);
        $this->assertStringContainsString('Board asked to hold prices', $this->json($r)['data']['retired_reason']);
        $this->assertSame($v1, (int) Services::offers()->publishedVersion('books-annual')['id']);

        // A revision already in effect cannot be withdrawn.
        $v3 = $this->scheduledRevision($v1, 1500000);
        $this->reachEffectiveDate($v3);
        $r = $this->asStaff($admin, 'post', "v1/offers/{$v3}/cancel-schedule", ['reason' => 'Too late now']);
        $this->assertSame(409, $this->code($r));
        $this->assertSame('already_in_effect', $this->json($r)['code']);
    }

    public function testPublishingWithAFutureDateSchedulesAndImmediatePublishWaitsForAPendingRevision(): void
    {
        $v1 = $this->publishedOffer();
        $admin = $this->admin();
        $nv = (int) $this->json($this->asStaff($admin, 'post', "v1/offers/{$v1}/new-version"))['data']['id'];
        $this->asStaff($admin, 'put', "v1/offers/{$nv}", ['price_paise' => 1300000, 'valid_from' => $this->tomorrow()]);
        $p = $this->asStaff($admin, 'post', "v1/offers/{$nv}/publish");
        $this->assertSame(200, $this->code($p), (string) $p->response()->getBody());
        $this->assertSame('scheduled', $this->json($p)['data']['status']);
        $this->assertSame('published', $this->db()->table('engage_offers')->where('id', $v1)->get()->getRow()->status, 'the price on sale is not retired early');

        // A version that would go on sale now must wait until the scheduled one has taken effect (or is cancelled).
        $v3 = (int) $this->json($this->asStaff($admin, 'post', "v1/offers/{$v1}/new-version"))['data']['id'];
        $r = $this->asStaff($admin, 'post', "v1/offers/{$v3}/publish");
        $this->assertSame(409, $this->code($r));
        $this->assertSame('revision_pending', $this->json($r)['code']);
    }

    public function testScheduledVersionsAreImmutable(): void
    {
        $v2 = $this->scheduledRevision($this->publishedOffer());
        $this->assertSame(409, $this->code($this->asStaff($this->admin(), 'put', "v1/offers/{$v2}", ['label' => 'Changed'])));
        $this->expectException(\Throwable::class);
        $this->db()->query('UPDATE engage_offers SET price_paise = 1 WHERE id = ?', [$v2]);
    }

    public function testAQuoteKeepsItsPriceAcrossARevision(): void
    {
        $v1 = $this->publishedOffer();
        $accountId = $this->account();
        $this->mapCompany($accountId, 42);
        $q = $this->asStaff($this->admin(), 'post', 'v1/quotes', ['cmp_id' => 42, 'offer_code' => 'books-annual', 'kind' => 'new']);
        $this->assertSame(201, $this->code($q), (string) $q->response()->getBody());
        $quoteRef = $this->json($q)['data']['quote_ref'];

        $v2 = $this->scheduledRevision($v1);
        $this->reachEffectiveDate($v2);
        Services::offers()->activateDue();

        // Without the quote the new price applies; with it, the quoted version.
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $accountId, 'cmp_id' => 42, 'offer_code' => 'books-annual', 'quote_ref' => $quoteRef]);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $this->assertSame(1, $this->json($r)['data']['offer']['version']);
        $this->assertSame(1180000, $this->json($r)['data']['offer_snapshot']['price_paise']);
        $this->assertSame('used', $this->db()->table('engage_quotes')->where('quote_ref', $quoteRef)->get()->getRow()->status);
    }

    public function testChannelsDecideWhereAnOfferIsListed(): void
    {
        $this->configureManage();
        $id = $this->publishedOffer();
        $admin = $this->admin();
        $this->assertSame(['website', 'manage', 'seller'], Services::offers()->list(['offer_code' => 'books-annual'])[0]['channels']);

        $r = $this->asStaff($admin, 'post', "v1/offers/{$id}/channels", ['channels' => ['seller', 'website', 'shop']]);
        $this->assertSame(422, $this->code($r));
        $r = $this->asStaff($admin, 'post', "v1/offers/{$id}/channels", ['channels' => ['seller', 'website']]);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $this->assertSame(['website', 'seller'], $this->json($r)['data']['channels']);
        $this->assertSame([], $this->json($this->s2s('get', 'manage/catalogue'))['data'], 'not offered in Manage any more');

        // A revision carries the channels of the version it revises.
        $v2 = $this->scheduledRevision($id);
        $this->assertSame(['website', 'seller'], OfferService::view(Services::offers()->findRow($v2))['channels']);
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['member'], 'member@aicountly.org'), 'post', "v1/offers/{$id}/channels", ['channels' => ['manage']])));
    }
}
