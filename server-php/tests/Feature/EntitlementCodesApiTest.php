<?php

namespace Tests\Feature;

use App\Libraries\Licensing\EntitlementCodeCatalogue;
use Tests\Support\LicensingTestCase;

/**
 * GET v1/entitlement-codes: the read-only catalogue staff pick offer limits from (fleet alignment 2026-10). Any
 * signed-in role reads it; ?app= narrows to Manage's codes + that app's; free text stays allowed on offers.
 */
final class EntitlementCodesApiTest extends LicensingTestCase
{
    public function testListsEveryCodeForAnySignedInRole(): void
    {
        $r = $this->asStaff($this->staff(['member'], 'member@aicountly.org'), 'get', 'v1/entitlement-codes');
        $this->assertSame(200, $this->code($r));
        $d = $this->json($r)['data'];
        $this->assertTrue($d['free_text_allowed']);
        $this->assertSame(EntitlementCodeCatalogue::CODES, $d['items']);
        $this->assertSame(['code', 'label', 'unit', 'app', 'enforced_by'], array_keys($d['items'][0]));
    }

    public function testNarrowsToAppsWithAliases(): void
    {
        $r = $this->asStaff($this->admin(), 'get', 'v1/entitlement-codes', ['app' => 'sales,drive']);
        $this->assertSame(['max_companies', 'max_users', 'drive.storage_mb', 'sales.max_quotations_per_month', 'sales.max_orders_per_month'],
            array_column($this->json($r)['data']['items'], 'code'));
    }

    public function testNeedsASignedInStaffMember(): void
    {
        $this->assertSame(401, $this->code($this->get('v1/entitlement-codes')));
    }

    public function testOffersStillAcceptACodeTheCatalogueDoesNotKnow(): void
    {
        $this->licenceType('complimentary', 'complimentary');
        $r = $this->asStaff($this->admin(), 'post', 'v1/offers', [
            'offer_code' => 'sales-basic', 'product_code' => 'books', 'licence_type_code' => 'complimentary', 'label' => 'Basic',
            'price_paise' => 0, 'price_basis' => 'exclusive', 'gst_rate' => '18', 'app_keys' => ['sales'],
            'entitlements' => [
                ['code' => 'sales.max_orders_per_month', 'label' => 'Sales orders a month', 'value' => 50, 'unit' => 'count'],
                ['code' => 'sales.max_brand_new_thing', 'label' => 'Something new', 'value' => 3, 'unit' => 'count'],
            ],
        ]);
        $this->assertSame(201, $this->code($r), json_encode($this->json($r)));
        $this->assertSame(['sales.max_orders_per_month', 'sales.max_brand_new_thing'], array_column($this->json($r)['data']['entitlements'], 'code'));
    }
}
