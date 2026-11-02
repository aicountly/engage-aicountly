<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The 2026-10 beta line-up, as licensable products:
 *
 *   stay     Aicountly Stay      hospitality
 *   library  Aicountly Library   education
 *   pharma   Aicountly Pharma    healthcare
 *   me       Aicountly Me        productivity
 *   campus   Aicountly Campus    education
 *   slip     Aicountly Slip      finance
 *
 * All six are already in ManageAppRegistry::CATALOG_KEYS, in Manage's launcher, and in Manage's
 * metering sources (manage migration 027) — but they had no row in engage_products. The effect was
 * quiet and total: `engage:basic-offers` only drafts offers for products it can see here, so these
 * six could never be given a Basic offer, and a product with no published Basic is skipped by
 * provisioning without a word. Every person who has signed up since these shipped holds no licence
 * at all for them, and nothing reported it. ProductsSeederTest has been failing on `stay` for
 * exactly this reason.
 *
 * Catalogue identity only — no price, no offer, no entitlement. Staff draft each product's Basic
 * with `php spark engage:basic-offers --apply`, fill in its limits and publish it; then
 * `php spark engage:provision-basic --pending` gives it to the holders who missed out.
 *
 * Categories follow the lowercase vocabulary already used in engage_products rather than Manage's
 * display labels (Manage shows Stay / Library / Pharma / Campus as "Business", Me as
 * "Productivity", Slip as "Finance").
 *
 * Seeders only run on a first deploy, so production gets the rows here; ProductsSeeder carries the
 * same rows for a fresh install. Idempotent, and an existing row keeps its id, sort order,
 * description and is_active — if staff have already renamed one, only name and category are brought
 * in line, exactly as AddAcademyProducts does.
 */
class AddBetaProducts extends Migration
{
    public const PRODUCTS = [
        ['stay',    'Aicountly Stay',    'hospitality',  300],
        ['library', 'Aicountly Library', 'education',    310],
        ['pharma',  'Aicountly Pharma',  'healthcare',   320],
        ['me',      'Aicountly Me',      'productivity', 330],
        ['campus',  'Aicountly Campus',  'education',    340],
        ['slip',    'Aicountly Slip',    'finance',      350],
    ];

    public function up(): void
    {
        $sql = 'INSERT INTO engage_products (code, name, category, is_active, sort_order, created_at, updated_at)
                VALUES (?, ?, ?, TRUE, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT (code) DO UPDATE
                   SET name = EXCLUDED.name, category = EXCLUDED.category, updated_at = CURRENT_TIMESTAMP';
        foreach (self::PRODUCTS as [$code, $name, $category, $sort]) {
            $this->db->query($sql, [$code, $name, $category, $sort]);
        }
    }

    public function down(): void
    {
        // Only rows nothing points at: a lead, payment or offer naming the product keeps it.
        $codes = "'" . implode("','", array_column(self::PRODUCTS, 0)) . "'";
        $this->db->query(
            "DELETE FROM engage_products p
              WHERE p.code IN ({$codes})
                AND NOT EXISTS (SELECT 1 FROM engage_leads l WHERE l.interested_product_id = p.id OR l.interested_product = p.code)
                AND NOT EXISTS (SELECT 1 FROM engage_lead_payments lp WHERE lp.product_code = p.code)
                AND NOT EXISTS (SELECT 1 FROM engage_offers o WHERE o.product_code = p.code)"
        );
    }
}
