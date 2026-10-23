<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Aicountly Academy course products (docs/ACADEMY_FEES_RUNBOOK.md), so leads, confirmed payments and seller
 * commission can name them:
 *
 *   academy  Aicountly Academy  umbrella (a lead that has not chosen a course yet)
 *   edge     Aicountly Edge     long-term training programme
 *   aspire   Aicountly Aspire   short-term workshops and courses
 *
 * Seeders only run on a first deploy, so production gets the rows here (ProductsSeeder carries the same rows
 * for a fresh install). Idempotent: an existing row keeps its id, sort order, description and is_active; only
 * name and category are brought in line. No commission plan is created — the owner publishes one.
 */
class AddAcademyProducts extends Migration
{
    public const PRODUCTS = [
        ['academy', 'Aicountly Academy', 'Academy', 200],
        ['edge',    'Aicountly Edge',    'Academy', 210],
        ['aspire',  'Aicountly Aspire',  'Academy', 220],
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
        $this->db->query(
            "DELETE FROM engage_products p
              WHERE p.code IN ('academy','edge','aspire')
                AND NOT EXISTS (SELECT 1 FROM engage_leads l WHERE l.interested_product_id = p.id OR l.interested_product = p.code)
                AND NOT EXISTS (SELECT 1 FROM engage_lead_payments lp WHERE lp.product_code = p.code)
                AND NOT EXISTS (SELECT 1 FROM engage_offers o WHERE o.product_code = p.code)"
        );
    }
}
