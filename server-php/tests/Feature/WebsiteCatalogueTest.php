<?php

namespace Tests\Feature;

use App\Libraries\AicS2s\AicS2sSignature;
use CodeIgniter\Test\TestResponse;
use Tests\Support\LicensingTestCase;

/**
 * GET v1/website/catalogue — the plans www.aicountly.com shows against each product (docs/SELLING_LICENCES.md §6):
 * AIC-S2S client `website` with a read key, only offers on sale on the website channel, the price as Finance bills it,
 * and the revision scheduled for it.
 *
 * @internal
 */
final class WebsiteCatalogueTest extends LicensingTestCase
{
    private const WEB_KEY_ID = 'website-read-2026-11';
    private const WEB_SECRET = 'website-read-secret-0123456789abcdefghijklmno';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(array_merge($_ENV, $_SERVER, getenv())) as $k) {
            if (is_string($k) && str_starts_with($k, 'ENGAGE_INBOUND_WEBSITE_')) {
                $this->setEnv($k, null);
            }
        }
    }

    private function configureWebsite(): void
    {
        $this->setEnv('ENGAGE_S2S_ENVIRONMENT', 'sandbox');
        $this->setEnv('ENGAGE_INBOUND_WEBSITE_KEY_IDS', self::WEB_KEY_ID);
        $this->setEnv('ENGAGE_INBOUND_WEBSITE_SECRET_WEBSITE_READ_2026_11', self::WEB_SECRET);
    }

    private function web(string $client = 'website', string $keyId = self::WEB_KEY_ID, string $secret = self::WEB_SECRET): TestResponse
    {
        $h = AicS2sSignature::headers($client, $keyId, $secret, 'sandbox', 'GET', '/v1/website/catalogue', '', '', 'service');
        $this->headers = [];

        return $this->withHeaders($h)->get('v1/website/catalogue');
    }

    public function testDormantUntilConfigured(): void
    {
        $r = $this->web();
        $this->assertSame(503, $this->code($r));
        $this->assertSame('inbound_not_configured', $this->json($r)['error']['code']);
    }

    public function testListsWebsiteOffersWithPriceBreakdownAndUpcomingRevision(): void
    {
        $this->configureWebsite();
        $this->configureManage();
        $v1 = $this->publishedOffer('books-annual');
        $this->publishedOffer('books-pro', ['label' => 'Books Pro', 'price_paise' => 900000, 'price_basis' => 'exclusive']);
        $manageOnly = $this->publishedOffer('books-enterprise', ['label' => 'Enterprise', 'price_paise' => 9900000]);
        $this->asStaff($this->admin(), 'post', "v1/offers/{$manageOnly}/channels", ['channels' => ['manage']]);
        $wef = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $rev = $this->asStaff($this->admin(), 'post', "v1/offers/{$v1}/revise", ['price_paise' => 1416000, 'effective_from' => $wef, 'note' => 'FY27 revision']);
        $this->assertSame(201, $this->code($rev), (string) $rev->response()->getBody());

        $r = $this->web();
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $b = $this->json($r);
        $this->assertTrue($b['success']);
        $this->assertSame('INR', $b['data']['currency']);
        $this->assertSame(['books'], array_column($b['data']['products'], 'code'));
        $offers = $b['data']['products'][0]['offers'];
        $this->assertSame(['books-pro', 'books-annual'], array_column($offers, 'offer_code'), 'cheapest first; the Manage-only offer is not listed');

        $pro = $offers[0];
        $this->assertSame(['currency' => 'INR', 'amount_paise' => 900000, 'basis' => 'exclusive', 'gst_rate' => '18', 'sac' => '997331',
            'taxable_paise' => 900000, 'gst_paise' => 162000, 'total_paise' => 1062000], $pro['price']);
        $annual = $offers[1];
        $this->assertSame(['taxable_paise' => 1000000, 'gst_paise' => 180000, 'total_paise' => 1180000],
            array_intersect_key($annual['price'], array_flip(['taxable_paise', 'gst_paise', 'total_paise'])));
        $this->assertSame(['months' => 12, 'days' => 0, 'cycle' => '12 months'], $annual['term']);
        $this->assertSame([['label' => 'Support', 'value' => 'Email']], $annual['attributes']);
        $this->assertSame(2, $annual['upcoming']['version']);
        $this->assertSame(1416000, $annual['upcoming']['price']['total_paise']);
        $this->assertNull($pro['upcoming']);
    }

    public function testAnotherClientsKeyIsRefused(): void
    {
        $this->configureWebsite();
        $this->configureManage();
        $r = $this->web('manage', self::READ_KEY_ID, self::READ_SECRET);
        $this->assertSame(401, $this->code($r));
        $this->assertSame('unknown_key', $this->json($r)['error']['code']);
    }
}
