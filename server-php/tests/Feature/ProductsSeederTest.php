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

    public function testEveryManageCatalogAppIsAProductAndLegacyRowsStay(): void
    {
        $db = Database::connect('tests');
        $db->table('engage_offers')->where('id >', 0)->delete();
        // An existing row staff renamed must survive a re-seed untouched.
        $db->table('engage_products')->whereIn('code', ['crm', 'community'])->delete();
        $db->table('engage_products')->insert(['code' => 'crm', 'name' => 'CRM (renamed by staff)', 'category' => 'custom', 'is_active' => false, 'sort_order' => 5]);
        $seeder = new ProductsSeeder(config('Database'), $db);
        $seeder->run();
        $before = $db->table('engage_products')->countAllResults();
        $seeder->run();
        $this->assertSame($before, $db->table('engage_products')->countAllResults(), 'idempotent');
        foreach (ManageAppRegistry::CATALOG_KEYS as $code) {
            $row = $db->table('engage_products')->where('code', $code)->get()->getRowArray();
            $this->assertNotNull($row, "{$code} is a product");
            $this->assertNotSame('', trim((string) $row['name']), $code);
            $this->assertNotSame('', trim((string) $row['category']), $code);
        }
        $crm = $db->table('engage_products')->where('code', 'crm')->get()->getRowArray();
        $this->assertSame(['CRM (renamed by staff)', 'custom'], [$crm['name'], $crm['category']], 'an existing row is never overwritten');
        foreach (['ourpeople', 'flow', 'console', 'other'] as $legacy) {
            $this->assertSame(1, $db->table('engage_products')->where('code', $legacy)->countAllResults(), "legacy {$legacy} kept");
        }
        $this->assertSame(0, $db->table('engage_offers')->countAllResults(), 'no offers or prices are seeded');
        $this->assertSame([], array_values(array_filter(array_column($db->table('engage_products')->get()->getResultArray(), 'code'), static fn ($c) => ! in_array($c, [...ManageAppRegistry::CATALOG_KEYS, 'ourpeople', 'flow', 'console', 'other', 'academy'], true) && ! str_starts_with((string) $c, 'test'))),
            'nothing outside the catalogue, the legacy codes and the Academy umbrella course');
    }
}
