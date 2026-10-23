<?php

namespace Tests\Feature;

use App\Database\Seeds\ProductsSeeder;
use App\Libraries\Licensing\ManageAppRegistry;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * ProductsSeeder carries every product Engage licenses into Manage (integration 2026-10): Inventory, Billing and Notes
 * were missing, so engage:basic-offers could not draft their Basic offers. Codes are Manage registry keys; the
 * seeder is catalogue identity only (no price, no offer) and idempotent.
 */
final class ProductsSeederTest extends DatabaseTestCase
{
    public function testSeedsLicensedProductsWithManageKeysIdempotentlyAndNoOffers(): void
    {
        $db = Database::connect('tests');
        $db->table('engage_offers')->where('id >', 0)->delete();
        $db->table('engage_products')->whereIn('code', ['inventory', 'billing', 'notes'])->delete();
        $seeder = new ProductsSeeder(config('Database'), $db);
        $seeder->run();
        $seeder->run();
        foreach (['books', 'inventory', 'billing', 'notes', 'docs'] as $code) {
            $this->assertSame(1, $db->table('engage_products')->where('code', $code)->countAllResults(), $code);
            $this->assertSame($code, ManageAppRegistry::fold($code), "{$code} is a Manage registry key");
        }
        $this->assertSame(0, $db->table('engage_offers')->countAllResults(), 'no offers or prices are seeded');
    }
}
