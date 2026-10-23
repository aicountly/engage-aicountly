<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Brand case in stored descriptions: "Aicountly", not "AICOUNTLY". Seeded rows only, and only while they still hold
 * the old seeded text (a description staff edited is left alone):
 *   engage_lead_sources  seller_request, seller   (from 2026-10-17-000091_CreateSellerDomain)
 *   engage_settings      theme_brand_colour       (from SettingsSeeder)
 */
class BrandCaseDescriptions extends Migration
{
    /** [table, key column, key, old text, new text] */
    public const ROWS = [
        ['engage_lead_sources', 'code', 'seller_request',
            'Someone asking to become an AICOUNTLY seller (seller.aicountly.com request form).',
            'Someone asking to become an Aicountly seller (seller.aicountly.com request form).'],
        ['engage_lead_sources', 'code', 'seller',
            'Lead registered by an AICOUNTLY seller on seller.aicountly.com.',
            'Lead registered by an Aicountly seller on seller.aicountly.com.'],
        ['engage_settings', 'key', 'theme_brand_colour',
            'AICOUNTLY green-white primary colour reference.',
            'Aicountly green-white primary colour reference.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$table, $col, $key, $old, $new]) {
            $this->db->query("UPDATE {$table} SET description = ? WHERE {$col} = ? AND description = ?", [$new, $key, $old]);
        }
    }

    public function down(): void
    {
        foreach (self::ROWS as [$table, $col, $key, $old, $new]) {
            $this->db->query("UPDATE {$table} SET description = ? WHERE {$col} = ? AND description = ?", [$old, $key, $new]);
        }
    }
}
