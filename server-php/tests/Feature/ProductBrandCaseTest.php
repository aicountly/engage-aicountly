<?php

namespace Tests\Feature;

use App\Database\Migrations\BrandCaseDescriptions;
use App\Database\Migrations\RenameProductBrandCase;
use App\Database\Seeds\ProductsSeeder;
use App\Services\Seller\SellerMailer;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/** Brand case "Aicountly": product display names (2026-10-23-000111), stored descriptions (000112) and seller-facing copy. */
final class ProductBrandCaseTest extends DatabaseTestCase
{
    protected $seed = ProductsSeeder::class;

    public function testOldSeededNamesAreRenamedAndStaffNamesAreLeftAlone(): void
    {
        $db = Database::connect('tests');
        $this->assertSame('Aicountly Books', $db->table('engage_products')->where('code', 'books')->get()->getRow()->name, 'fresh seed');

        // A database seeded before the change, with one name edited by staff.
        $db->query("UPDATE engage_products SET name = 'AICOUNTLY ' || SUBSTRING(name FROM 11) WHERE name LIKE 'Aicountly %' AND category <> 'Academy'");
        $db->table('engage_products')->where('code', 'vault')->update(['name' => 'Vault (staff name)']);
        (new RenameProductBrandCase(Database::forge('tests')))->up();
        $names = array_column($db->table('engage_products')->get()->getResultArray(), 'name', 'code');
        $this->assertSame('Aicountly Books', $names['books']);
        $this->assertSame('Aicountly HRMS', $names['hrms']);
        $this->assertSame('Vault (staff name)', $names['vault']);
        $this->assertSame('Other / Custom', $names['other']);
        $this->assertSame([], array_filter($names, static fn ($n) => str_contains((string) $n, 'AICOUNTLY')));
    }

    public function testStoredDescriptionsUseTheMixedCaseBrand(): void
    {
        $db = Database::connect('tests');
        // Lead sources come from migration 000091 (old text) and are corrected by 000112 in the same run.
        $this->assertSame('Lead registered by an Aicountly seller on seller.aicountly.com.',
            $db->table('engage_lead_sources')->where('code', 'seller')->get()->getRow()->description);

        // A settings row seeded before the change; a staff-edited description is left alone.
        $db->table('engage_settings')->insert(['key' => 'theme_brand_colour', 'value_json' => '"#16a34a"', 'description' => 'AICOUNTLY green-white primary colour reference.']);
        $db->table('engage_lead_sources')->where('code', 'seller_request')->update(['description' => 'Edited by staff (AICOUNTLY)']);
        (new BrandCaseDescriptions(Database::forge('tests')))->up();
        $this->assertSame('Aicountly green-white primary colour reference.',
            $db->table('engage_settings')->where('key', 'theme_brand_colour')->get()->getRow()->description);
        $this->assertSame('Edited by staff (AICOUNTLY)', $db->table('engage_lead_sources')->where('code', 'seller_request')->get()->getRow()->description);
    }

    public function testSellerEmailsUseTheMixedCaseBrand(): void
    {
        [$subject, $body] = SellerMailer::compose('seller_activation', ['name' => 'Ravi', 'seller_code' => 'SLR-000001', 'expires_at' => 'soon', 'activation_url' => 'https://x']);
        $this->assertSame('Activate your Aicountly seller account', $subject);
        $this->assertStringNotContainsString('AICOUNTLY', $body);
        $this->assertStringEndsWith("\n\nAicountly", $body);
    }
}
